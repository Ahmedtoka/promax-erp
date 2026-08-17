<?php

namespace App\Services\Importers;

use App\Models\Product;
use App\Models\Stock;
use App\Services\Sheet;
use Illuminate\Support\Facades\DB;

/**
 * استيراد كتالوج المنتجات.
 *
 * ⚠️ ده أول استيراد لازم يتعمل — العملاء والمخزون والفواتير كلهم
 * بيشيروا للمنتجات بالكود. لو اتعمل بعدهم، الربط بيفشل.
 *
 * ⚠️ الأسعار التلاتة (تكلفة / قديم / جديد) هي عمود فقري التسعير كله.
 * `Pricing` بيقرا منها وبس، فأي غلط هنا بيظهر في كل فاتورة.
 */
class ProductImporter extends Importer
{
    public function kind(): string
    {
        return 'products';
    }

    public function columns(): array
    {
        return [
            'code' => ['كود الصنف', 'code', 'sku', 'الكود'],
            'name' => ['اسم الصنف', 'name', 'الصنف', 'المنتج'],
            'name_en' => ['الاسم الإنجليزي', 'name_en', 'english name'],
            'family' => ['العائلة', 'family', 'المجموعة'],
            'unit' => ['الوحدة', 'unit'],
            'barcode' => ['الباركود', 'barcode', 'gtin'],
            'cost' => ['التكلفة', 'cost'],
            'price_old' => ['السعر القديم', 'price_old', 'old price'],
            'price_new' => ['السعر الجديد', 'price_new', 'new price', 'السعر'],
            'shelf_life_months' => ['مدة الصلاحية بالشهور', 'shelf_life_months', 'shelf life'],
            'qty' => ['الرصيد الافتتاحي', 'qty', 'opening stock', 'الكمية'],
            'taxable' => ['خاضع للضريبة', 'taxable'],
            'tax_rate' => ['نسبة الضريبة', 'tax_rate', 'vat'],
        ];
    }

    public function required(): array
    {
        return ['code', 'name', 'price_new'];
    }

    public function validateRow(array $row, int $line): array
    {
        $out = [];

        if (($row['code'] ?? null) === null) {
            $out[] = __('import.code_required');
        }
        if (($row['name'] ?? null) === null) {
            $out[] = __('import.name_required');
        }

        foreach (['cost', 'price_old', 'price_new', 'qty'] as $f) {
            $v = $row[$f] ?? null;
            if ($v !== null && Sheet::number($v) === null) {
                $out[] = __('import.not_a_number', ['column' => $f, 'value' => $v]);
            }
        }

        $new = Sheet::number($row['price_new'] ?? null);
        if ($new === null || $new <= 0) {
            $out[] = __('import.price_required');
        }

        // ⚠️ تحذير مهم مش خطأ: بيع بأقل من التكلفة ممكن يكون مقصود
        // (تصفية) بس غالباً غلطة في عمود. بنرفضه عشان اليوزر يراجع.
        $cost = Sheet::number($row['cost'] ?? null);
        if ($cost !== null && $new !== null && $cost > $new && $new > 0) {
            $out[] = __('import.cost_above_price', ['cost' => $cost, 'price' => $new]);
        }

        $family = $row['family'] ?? null;
        if ($family !== null && $this->family($family) === null) {
            $out[] = __('import.unknown_family', [
                'value' => $family,
                'allowed' => implode(', ', array_keys(Product::FAMILIES)),
            ]);
        }

        return $out;
    }

    /**
     * قيمة منطقية من الشيت.
     *
     * ⚠️ اليوزر بيكتب «نعم» أو «لا» أو «yes» أو `1`. اعتبار أي نص
     * غير فاضي = true بيخلّي «لا» تبقى true.
     */
    private function flag(?string $v, bool $default): bool
    {
        if ($v === null || trim($v) === '') {
            return $default;
        }

        $t = $this->normalise($v);

        return ! in_array($t, ['0', 'لا', 'no', 'false', 'معفي', 'معفى', 'exempt'], true);
    }

    /** العائلة من المفتاح أو من اسمها العربي */
    private function family(?string $v): ?string
    {
        return $this->toKey($v, Product::FAMILIES);
    }

    public function apply(array $rows): array
    {
        $created = $updated = $stocked = 0;

        // ⚠️ مرة واحدة بره اللوب — مش كويري لكل صف في شيت 500 صنف.
        $stockWarehouseId = \App\Models\Warehouse::defaultStockId();

        DB::transaction(function () use ($rows, $stockWarehouseId, &$created, &$updated, &$stocked) {
            foreach ($rows as $row) {
                $code = (string) $row['code'];
                $existing = Product::where('code', $code)->first();

                $product = Product::updateOrCreate(['code' => $code], [
                    'name' => $row['name'],
                    'name_en' => $row['name_en'] ?? null,
                    'family' => $this->family($row['family'] ?? null)
                        ?? array_key_first(Product::FAMILIES),
                                        // ⚠️ ممنوع وحدة عربية متبتّتة — بتظهر عربي جوه
                    // الواجهة الإنجليزية. المفتاح بيترجم مع اللغة.
                    'unit' => $row['unit'] ?? __('stock.unit_piece'),
                    'barcode' => $row['barcode'] ?? null,
                    'cost' => Sheet::number($row['cost'] ?? null) ?? 0,
                    'price_old' => Sheet::number($row['price_old'] ?? null)
                        ?? Sheet::number($row['price_new']),
                    'price_new' => Sheet::number($row['price_new']),
                    'shelf_life_months' => (int) (Sheet::number($row['shelf_life_months'] ?? null)
                        ?? Product::DEFAULT_SHELF_LIFE),
                    // ⚠️ الشيت بالنسبة المئوية والداتابيز بالكسر
                    'taxable' => $this->flag($row['taxable'] ?? null, true),
                    'tax_rate' => round((Sheet::number($row['tax_rate'] ?? null) ?? 0) / 100, 4),
                    // ⚠️⚠️ **`active` بتتكتب للجديد بس** (إصلاح ١٧/٨).
                    // كانت `'active' => true` ثابتة جوّه
                    // `updateOrCreate` — يعني في **التحديث** كمان.
                    // فأي إعادة تشغيل للاستيراد كانت **بتعيد تفعيل كل
                    // منتج المالك أوقفه** بصمت، والأصناف الدرافت ترجع
                    // تظهر في شاشات البيع من غير ما حد يعرف ليه.
                    //
                    // الصنف الجديد بيتولد مفعّل زي ما كان؛ الموجود
                    // حالته قرار إداري والشيت مالوش دعوة بيه.
                    ...($existing === null ? ['active' => true] : []),
                ]);

                // ⚠️ المستورد بيكتب العمودين — لازم القايمتين المهاجرتين
                // يتحدّثوا معاهم، وإلا الشيت يتقرا والفواتير تفضل بالقديم.
                \App\Services\Pricing::syncColumnsToLists($product);

                $existing ? $updated++ : $created++;

                // الرصيد الافتتاحي اختياري — لو مش موجود بنسيب المخزون فاضي
                // ⚠️ **لازم `warehouse_id`.** المخزون بقى صف لكل
                // (صنف، مخزن)، والعمود NOT NULL من غير default —
                // فالكتابة من غيره كانت بترمي SQLSTATE 1364 على أول
                // منتج جديد ليه رصيد افتتاحي، يعني كل منتج جديد.
                $qty = Sheet::number($row['qty'] ?? null);
                if ($qty !== null && $qty > 0 && $stockWarehouseId !== null) {
                    Stock::updateOrCreate(
                        ['product_id' => $product->id, 'warehouse_id' => $stockWarehouseId],
                        [
                            'qty' => (int) $qty,
                            'good_qty' => (int) $qty,
                            'hold_qty' => 0,
                        ],
                    );
                    $stocked++;
                }
            }
        });

        return ['created' => $created, 'updated' => $updated, 'stocked' => $stocked];
    }
}
