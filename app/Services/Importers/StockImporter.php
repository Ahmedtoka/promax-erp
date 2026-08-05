<?php

namespace App\Services\Importers;

use App\Models\Batch;
use App\Models\BatchLocation;
use App\Models\GoodsReceipt;
use App\Models\Location;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Warehouse;
use App\Services\Sheet;
use Illuminate\Support\Facades\DB;

/**
 * استيراد المخزون بالباتشات.
 *
 * ⚠️ لازم يتعمل **بعد** المنتجات — كل صف بيشير لمنتج بكوده أو باركوده.
 *
 * ⚠️ تاريخ الصلاحية بيتحسب من تاريخ الإنتاج + مدة صلاحية المنتج، إلا لو
 * الشيت جايب الصلاحية صريحة. ده بيمنع التناقض اللي بيحصل لما حد يكتب
 * الصلاحية بإيده وتطلع مخالفة لمدة الصلاحية المسجّلة على الصنف.
 *
 * ⚠️ `stocks` بيتحدّث من مجموع الباتشات مش من عمود مستقل، عشان الرصيد
 * الإجمالي يفضل مطابق لتفاصيله دايماً.
 */
class StockImporter extends Importer
{
    public function kind(): string
    {
        return 'stock';
    }

    public function columns(): array
    {
        return [
            'product' => ['كود الصنف', 'code', 'sku', 'الصنف', 'المنتج', 'product'],
            'barcode' => ['الباركود', 'barcode'],
            'batch_no' => ['رقم الباتش', 'batch_no', 'batch', 'الباتش'],
            'produced_on' => ['تاريخ الإنتاج', 'produced_on', 'production date'],
            'expires_on' => ['تاريخ الصلاحية', 'expires_on', 'expiry'],
            'qty' => ['الكمية', 'qty', 'quantity', 'الرصيد'],
            'cost' => ['التكلفة', 'cost'],
            'warehouse' => ['المخزن', 'warehouse'],
            'location' => ['الرف', 'location', 'shelf'],
            'hold' => ['محجوز', 'hold', 'on hold'],
        ];
    }

    public function required(): array
    {
        return ['qty'];
    }

    public function validateRow(array $row, int $line): array
    {
        $out = [];

        $hasRef = ($row['product'] ?? null) !== null || ($row['barcode'] ?? null) !== null;
        if (! $hasRef) {
            $out[] = __('import.product_ref_required');
        }

        $qty = Sheet::number($row['qty'] ?? null);
        if ($qty === null) {
            $out[] = __('import.qty_required');
        } elseif ($qty < 0) {
            $out[] = __('import.qty_negative', ['value' => $qty]);
        }

        // ⚠️ الصنف لازم يكون موجود — الاستيراد مايعملش منتجات من نفسه،
        // وإلا بيتخلق صنف بأسعار صفر وبيبوّظ كل حسابات القيمة.
        if ($hasRef && $this->findProduct($row) === null) {
            $out[] = __('import.product_not_found', [
                'value' => $row['product'] ?? $row['barcode'],
            ]);
        }

        foreach (['produced_on', 'expires_on'] as $f) {
            $v = $row[$f] ?? null;
            if ($v !== null && Sheet::date($v) === null) {
                $out[] = __('import.bad_date', ['column' => $f, 'value' => $v]);
            }
        }

        $prod = Sheet::date($row['produced_on'] ?? null);
        $exp = Sheet::date($row['expires_on'] ?? null);
        if ($prod && $exp && $exp <= $prod) {
            $out[] = __('import.expiry_before_production');
        }
        if ($prod && $prod > new \DateTimeImmutable('today')) {
            $out[] = __('import.production_in_future', ['value' => $prod->format('Y-m-d')]);
        }

        return $out;
    }

    public function apply(array $rows): array
    {
        $batches = $shelved = 0;
        $touched = [];

        DB::transaction(function () use ($rows, &$batches, &$shelved, &$touched) {
            $whCache = $locCache = [];
            // ⚠️ **إذن استلام لكل مخزن** (إصلاح 2026-08-05). الاستيراد
            // كان بيعمل باتشات من غير إذن خالص — فشاشة الأذون فاضية،
            // وزرار الترصيف اللي عايش على صفحة الإذن مالوش طريق،
            // والبضاعة تقعد «مستني الترصيف» من غير أي زرار يرصّفها.
            $grns = [];

            foreach ($rows as $row) {
                $product = $this->findProduct($row);
                if ($product === null) {
                    continue;
                }

                $qty = (int) Sheet::number($row['qty']);
                if ($qty <= 0) {
                    continue;
                }

                $warehouse = $this->warehouse($row['warehouse'] ?? null, $whCache);
                $produced = Sheet::date($row['produced_on'] ?? null);

                // الصلاحية: الصريحة أولاً، وإلا الإنتاج + مدة صلاحية الصنف
                // ⚠️ batches.expires_on عمود NOT NULL — أساس الـ FEFO كله.
                // لو مفيش تاريخ صريح ولا إنتاج، بنحسبها من النهارده عشان
                // الإدخال مايفشلش ويرجّع الاستيراد كله.
                $expires = Sheet::date($row['expires_on'] ?? null);
                if ($expires === null) {
                    $base = $produced ?? new \DateTimeImmutable('today');
                    $expires = $base->modify('+'.$product->shelfLife().' months');
                }

                $batchNo = $row['batch_no']
                    ?? ($produced ? 'B'.$produced->format('ymd') : 'B'.now()->format('ymd'));

                $key = [
                    'product_id' => $product->id,
                    'batch_no' => $batchNo,
                    'warehouse_id' => $warehouse?->id,
                ];

                // ⚠️ الكميات **بتتجمّع** مش بتتستبدل. الشيت الحقيقي بيقسّم
                // الباتش الواحد على أكتر من سطر (رف مختلف، جزء محجوز)،
                // و updateOrCreate كان بيخلّي السطر التاني يمسح الأول.
                //
                // ⚠️ ولو الباتش موجود من استيراد قديم، ممنوع نرجّع
                // qty_remaining لـ qty_received — ده بيحيي بضاعة خرجت
                // للعربيات خلاص.
                $batch = Batch::where($key)->first();

                if ($batch === null) {
                    // إذن الاستيراد بتاع المخزن ده — بيتعمل مرة واحدة
                    $grn = $warehouse === null ? null
                        : ($grns[$warehouse->id] ??= GoodsReceipt::create([
                            'number' => GoodsReceipt::nextNumber(),
                            'warehouse_id' => $warehouse->id,
                            'received_on' => today()->toDateString(),
                            'status' => 'posted',
                            'reference' => __('import.opening_receipt_ref'),
                            'created_by' => auth()->id(),
                        ]));

                    $batch = Batch::create($key + [
                        'goods_receipt_id' => $grn?->id,
                        'produced_on' => $produced?->format('Y-m-d'),
                        'expires_on' => $expires->format('Y-m-d'),
                        'qty_received' => $qty,
                        'qty_remaining' => $qty,
                        'cost' => Sheet::number($row['cost'] ?? null) ?? $product->cost,
                        'blocked' => Sheet::bool($row['hold'] ?? null),
                    ]);
                } else {
                    $batch->qty_received = (int) $batch->qty_received + $qty;
                    $batch->qty_remaining = (int) $batch->qty_remaining + $qty;

                    if (Sheet::bool($row['hold'] ?? null)) {
                        $batch->blocked = true;
                    }

                    $batch->save();
                }

                $batches++;
                $touched[$product->id] = true;

                // الترصيف على الرف اختياري
                $locName = $row['location'] ?? null;
                if ($locName !== null && $warehouse !== null) {
                    $loc = $this->location($warehouse, $locName, $locCache);
                    if ($loc && BatchLocation::putAway($batch, $loc, $qty) === null) {
                        $shelved++;
                    }
                }
            }

            // ⚠️ الرصيد الإجمالي بيتحسب من الباتشات — مش عمود مستقل
            foreach (array_keys($touched) as $productId) {
                $this->syncStock($productId);
            }
        });

        return ['batches' => $batches, 'shelved' => $shelved, 'products' => count($touched)];
    }

    /**
     * ⚠️ الشيتات الحقيقية بتكتب في عمود «الصنف» **اسم** المنتج مش كوده.
     * فبندوّر بالكود، وبعدين بالباركود، وبعدين بالاسم بلغتيه. من غير
     * البحث بالاسم كل صف في شيت المصنع بيترفض.
     */
    private function findProduct(array $row): ?Product
    {
        $ref = $row['product'] ?? null;
        $barcode = $row['barcode'] ?? null;

        if ($ref !== null) {
            $p = Product::where('code', $ref)->first()
                ?? Product::where('name', $ref)->orWhere('name_en', $ref)->first();

            if ($p) {
                return $p;
            }
        }

        return $barcode !== null ? Product::where('barcode', $barcode)->first() : null;
    }

    private function warehouse(?string $name, array &$cache): ?Warehouse
    {
        $key = $name ?? '__default';

        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        if ($name === null) {
            // مفيش مخزن في الشيت — الفرع الافتراضي، وبنعمله لو مش موجود
            $w = Warehouse::defaultBranch()
                ?? Warehouse::where('active', true)->orderBy('id')->first()
                ?? Warehouse::create([
                    'code' => 'WH-01',
                    'name' => __('stock.warehouse'),
                    'type' => Warehouse::TYPE_BRANCH,
                    'active' => true,
                ]);

            return $cache[$key] = $w;
        }

        $w = Warehouse::where('code', $name)->orWhere('name', $name)->orWhere('name_en', $name)->first()
            ?? Warehouse::create([
                'code' => 'WH-'.str_pad((string) (Warehouse::count() + 1), 2, '0', STR_PAD_LEFT),
                'name' => $name,
                'type' => Warehouse::TYPE_BRANCH,
                'active' => true,
            ]);

        return $cache[$key] = $w;
    }

    private function location(Warehouse $wh, string $code, array &$cache): ?Location
    {
        $key = $wh->id.'|'.$code;

        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $loc = Location::where('warehouse_id', $wh->id)->where('code', $code)->first();

        if ($loc === null) {
            // كود الرف شكله A03 — حرف الاستاند ورقم الدور
            preg_match('/^([A-Za-z]+)\s*(\d+)/', $code, $m);

            $loc = Location::create([
                'warehouse_id' => $wh->id,
                'code' => strtoupper($code),
                'stand' => strtoupper($m[1] ?? 'A'),
                'level' => (int) ($m[2] ?? 1),
                'is_pick_face' => (int) ($m[2] ?? 1) <= 2,
                'capacity' => 2000,
                'active' => true,
            ]);
        }

        return $cache[$key] = $loc;
    }

    /**
     * ⚠️ **بقت بتنادي `StockCounting::resync` بدل ما تكرّرها.**
     * كانت نسخة تانية من نفس الحساب، وبتكتب `Stock` من غير
     * `warehouse_id` — يعني بعد ما المخزون بقى صف لكل (صنف، مخزن):
     * إمّا تدوس على صف مخزن عشوائي بإجمالي الشركة كلها، أو تحاول
     * تعمل صف جديد من غير مخزن فيرمي SQLSTATE 1364 ويوقّف الاستيراد
     * في نصه. الحساب الصح موجود في مكان واحد بس دلوقتي.
     */
    private function syncStock(int $productId): void
    {
        \App\Services\StockCounting::resync($productId);
    }
}
