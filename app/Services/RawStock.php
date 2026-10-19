<?php

// STUB — Lane 6 placeholder; Lane 5 owns RawStock. Expect a merge conflict: keep Lane 5's body, keep this signature.

namespace App\Services;

use App\Exceptions\Rejected;
use App\Models\Batch;
use App\Models\BatchLocation;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;

/**
 * صرف خامة من مخزن — FEFO على باتشاتها (التصنيع ١٩/١٠).
 *
 * ⚠️ **لازم يتنده جوه `DB::transaction`** — مابيفتحش ترانزاكشن لوحده،
 * عشان صرف الخامة وصف `production_issues` يقعوا أو ينجحوا مع بعض.
 * الباتشات بتتقفل بـ`lockForUpdate` قبل حساب المتاح: صرفين على آخر لوط
 * مايعدّوش الاتنين.
 *
 * ⚠️ الرف مصدر الحقيقة للكمية الفعلية — الكمية بتتسحب من صفوف
 * `batch_locations` بتاعة الباتش (بالترتيب) ومن الباتش نفسه، فيفضل
 * `qty_remaining` = مجموع الرفوف، وبعدين `StockCounting::resync`.
 */
class RawStock
{
    /**
     * @return list<array{batch_id:int, qty:float, cost:float}>  `cost` = تكلفة **الوحدة** في الباتش
     */
    public static function issue(Warehouse $w, Product $p, float $qty, Model $for): array
    {
        if ($qty <= 0) {
            throw new Rejected(__('mfg.qty_positive'));
        }

        // الباتشات أعداد صحيحة النهارده (D2) — لحد ما الخط ٥ ينزّل الوحدات
        if (abs($qty - round($qty)) > 0.0000001) {
            throw new Rejected(__('mfg.raw_whole_units'));
        }

        $need = (int) round($qty);

        $batches = Batch::query()
            ->where('product_id', $p->id)
            ->where('warehouse_id', $w->id)
            ->where('blocked', false)
            ->where('qty_remaining', '>', 0)
            ->whereDate('expires_on', '>=', now()->toDateString())
            ->orderBy('expires_on')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $available = (int) $batches->sum('qty_remaining');

        // ⚠️ الرفض **قبل** أي حركة — مفيش صرف جزئي
        if ($available < $need) {
            throw new Rejected(__('mfg.raw_short', [
                'product' => $p->displayName(),
                'available' => $available,
                'need' => $need,
            ]));
        }

        $lines = [];
        $left = $need;

        foreach ($batches as $batch) {
            if ($left <= 0) {
                break;
            }

            $take = min($left, (int) $batch->qty_remaining);

            // الرفوف الأول بالترتيب — اللي مش مترصّف بيتسحب من الباتش بس
            $fromShelves = $take;
            $rows = BatchLocation::query()->where('batch_id', $batch->id)
                ->where('qty', '>', 0)->orderBy('id')->lockForUpdate()->get();

            foreach ($rows as $row) {
                if ($fromShelves <= 0) {
                    break;
                }
                $n = min($fromShelves, (int) $row->qty);
                if ($err = $row->take($n)) {
                    throw new Rejected($err);
                }
                $fromShelves -= $n;
            }

            if ($err = $batch->issue($take)) {
                throw new Rejected($err);
            }

            $lines[] = ['batch_id' => (int) $batch->id, 'qty' => (float) $take, 'cost' => (float) $batch->cost];
            $left -= $take;
        }

        StockCounting::resync($p->id, $w->id);

        return $lines;
    }
}
