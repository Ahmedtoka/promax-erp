<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\BatchLocation;
use App\Models\Location;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * الرصيد اليدوي — تحويل رقم متكتب بالإيد لباتشات على أرفف
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **ليه الكلاس ده موجود أصلاً:** شاشة المخازن كانت بتكتب في
 * `stocks` مباشرة — رقم من غير باتش ولا رف. النتيجة اللي حصلت فعلاً
 * على اللايف (2026-08-03): شاشة المخزون بتقول «فيه بضاعة» وشاشة
 * تسليم العهدة بتقول «المتاح 0»، لأن التسليم بيخصم من الأرفف
 * (`Warehouse::availableFor`) والأرفف فاضية. وأي `resync` كان هيمسح
 * الرقم اليدوي لأن الباتشات هي المصدر.
 *
 * القاعدة هنا: **الرقم المكتوب هو الحقيقة، والباتشات بتتظبط عليه** —
 * زي اعتماد الجرد بالظبط:
 *   - زيادة ← باتش تسوية `ADJ` بينضاف ويترصّف على رف السحب فوراً
 *   - نقص  ← بيتخصم من الباتشات بالـ FEFO (ومن أرففها)
 *   - الهولد ← بيتحط في باتش `ADJ-H` محجوب (`blocked`)
 * وفي الآخر `StockCounting::resync` بيخلّي `stocks` صورة من الباتشات.
 */
class OpeningStock
{
    /** كود رف التسوية اللي بينشأ لو المخزن مالوش أرفف سحب */
    private const SHELF_CODE = 'ADJ';

    /**
     * تثبيت رصيد صنف في مخزن على رقم معيّن.
     *
     * ⚠️ لازم تتنادى **جوه ترانزاكشن** (الكنترولر أو الأمر هو اللي
     * بيفتحها) — بتقفل على باتشات الصنف قبل أي حساب.
     *
     * @return bool اتغيّر فعلاً ولا كان مظبوط أصلاً
     */
    public static function apply(Warehouse $warehouse, Product $product, int $qty, int $hold): bool
    {
        $qty = max(0, $qty);
        $hold = min(max(0, $hold), $qty);
        $good = $qty - $hold;

        // ⚠️ القفل بترتيب الـ id (زي اعتماد الجرد) — يمنع الديد لوك.
        // ترتيب الـ FEFO بيتعمل في الذاكرة بعد القفل.
        $batches = Batch::where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)
            ->lockForUpdate()
            ->orderBy('id')
            ->get()
            ->sortBy([['expires_on', 'asc'], ['id', 'asc']])
            ->values();

        $curGood = (int) $batches->where('blocked', false)->sum('qty_remaining');
        $curHold = (int) $batches->where('blocked', true)->sum('qty_remaining');

        if ($curGood === $good && $curHold === $hold) {
            return false;
        }

        self::adjustPool($warehouse, $product, $batches->where('blocked', false), $good - $curGood, false);
        self::adjustPool($warehouse, $product, $batches->where('blocked', true), $hold - $curHold, true);

        StockCounting::resync($product->id, $warehouse->id);

        return true;
    }

    /**
     * ظبط مجموعة باتشات (السليم أو المحجوب) على فرق معيّن.
     *
     * @param  \Illuminate\Support\Collection<int, Batch>  $batches
     */
    private static function adjustPool(Warehouse $warehouse, Product $product, $batches, int $delta, bool $blocked): void
    {
        if ($delta > 0) {
            self::addTo($warehouse, $product, $delta, $blocked);

            return;
        }

        // نقص: FEFO — الأقرب انتهاءً بيتخصم الأول، ومن أرففه كمان
        $left = -$delta;

        foreach ($batches as $batch) {
            if ($left <= 0) {
                break;
            }

            $take = min($left, (int) $batch->qty_remaining);

            if ($take <= 0) {
                continue;
            }

            $batch->update([
                'qty_remaining' => (int) $batch->qty_remaining - $take,
                // فرق يدوي بالسالب = عجز متسجّل، مش رقم بيتبخّر
                'qty_damaged' => (int) $batch->qty_damaged + $take,
            ]);

            self::drainLocations($batch, $take);
            $left -= $take;
        }
    }

    /** زيادة: باتش تسوية بينضاف ويترصّف على رف سحب فوراً */
    private static function addTo(Warehouse $warehouse, Product $product, int $qty, bool $blocked): void
    {
        // باتش تسوية واحد لكل (صنف، مخزن، حالة) في اليوم — مش باتش لكل ضغطة حفظ
        $batchNo = ($blocked ? 'ADJ-H-' : 'ADJ-').today()->format('Ymd');

        $batch = Batch::firstOrCreate(
            [
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'batch_no' => $batchNo,
            ],
            [
                'produced_on' => today(),
                // ⚠️ `expires_on` NOT NULL — من عمر الصنف
                'expires_on' => $product->expiryFrom(today()),
                'qty_received' => 0, 'qty_remaining' => 0,
                'qty_issued' => 0, 'qty_damaged' => 0,
                'cost' => (float) $product->cost,
                'blocked' => $blocked,
                'notes' => __('stock.manual_adjust_note'),
            ],
        );

        $batch->update([
            'qty_received' => (int) $batch->qty_received + $qty,
            'qty_remaining' => (int) $batch->qty_remaining + $qty,
        ]);

        // ⚠️ **الترصيف فوراً على رف سحب** — ده جوهر الإصلاح كله.
        // باتش من غير رف = «متاح 0» في تسليم العهدة وأوامر التجهيز.
        $err = BatchLocation::putAway($batch->fresh(), self::pickShelf($warehouse), $qty);

        if ($err !== null) {
            // السعة اتملت أو غيره — نرمي عشان الترانزاكشن كلها ترجع،
            // مش نسيب باتش من غير رف في صمت ونرجع لنفس المشكلة
            throw new \App\Exceptions\Rejected($err);
        }
    }

    /** رف السحب اللي التسوية بتترصّف عليه */
    public static function pickShelf(Warehouse $warehouse): Location
    {
        $shelf = Location::where('warehouse_id', $warehouse->id)
            ->where('active', true)
            ->orderByDesc('is_pick_face')
            ->orderBy('stand')->orderBy('level')
            ->first();

        // ⚠️ رف التسوية من غير `capacity` (null = من غير حد) — رف
        // بسعة كان هيرفض الرصيد الافتتاحي الكبير
        return $shelf ?? Location::create([
            'warehouse_id' => $warehouse->id,
            'code' => self::SHELF_CODE,
            'stand' => 'A', 'level' => 1,
            'is_pick_face' => true, 'active' => true,
        ]);
    }

    /** خصم كمية من أرفف باتش (زي عجز الجرد) */
    private static function drainLocations(Batch $batch, int $qty): void
    {
        $rows = BatchLocation::where('batch_id', $batch->id)
            ->lockForUpdate()->orderBy('id')->get();

        foreach ($rows as $row) {
            if ($qty <= 0) {
                break;
            }

            $take = min($qty, (int) $row->qty);
            $row->update(['qty' => (int) $row->qty - $take]);
            $qty -= $take;
        }
    }
}
