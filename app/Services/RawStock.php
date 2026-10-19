<?php

namespace App\Services;

use App\Exceptions\Rejected;
use App\Models\Batch;
use App\Models\BatchLocation;
use App\Models\Product;
use App\Models\RawStockMove;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * صرف الخامات والتعبئة بالـ FEFO (الأقرب انتهاءً الأول) — الواجهة اللي
 * التصنيع (لين ٦) بينادي عليها.
 * FEFO issue API for raw/packaging stock; Lane 6 (manufacturing) calls it.
 *
 * - FEFO: الباتشات بترتيب expires_on ثم id (نفس scopeSellable)، والمنتهي
 *   والموقوف والفاضي مابيتلمسوش.
 * - D1: المخزون أعداد صحيحة في كل الأعمدة، فالكمية لازم تكون عدد صحيح
 *   حتى لو التوقيع float (الخامات بالكيلو تتعرّف بالجرام).
 * - D8: الصرف مايحتاجش ترصيف. من كل باتش بنسحب الجزء غير المترصّف الأول،
 *   وبعدين صفوف الأرفف (رف السحب الأول) عن طريق BatchLocation::take()،
 *   فيفضل qty_remaining >= مجموع batch_locations.
 * - الأقفال: lockForUpdate على الباتشات بترتيب id ثابت جوه الترانزاكشن،
 *   فصرفين متزامنين على آخر باتش ماينفعش ينزّلوه تحت الصفر.
 * - للخامات والتعبئة بس (raw|packaging)؛ المنتج التام يفضل على PickOrder.
 */
class RawStock
{
    /**
     * @return list<array{batch_id:int, qty:float, cost:float}>
     *
     * @throws Rejected
     */
    public static function issue(Warehouse $w, Product $p, float $qty, Model $for): array
    {
        if (! in_array($p->kind, ['raw', 'packaging'], true)) {
            throw new Rejected(__('stock.raw_kind_only'));
        }
        if ($qty <= 0) {
            throw new Rejected(__('stock.qty_must_be_positive'));
        }
        if (abs($qty - round($qty)) > 1e-9) {
            throw new Rejected(__('stock.raw_qty_not_whole'));
        }
        $need = (int) round($qty);

        return DB::transaction(function () use ($w, $p, $need, $for) {
            // ⚠️ القفل بترتيب id ثابت (مش expires_on) عشان صرفين متزامنين
            // ياخدوا الأقفال بنفس الترتيب ومايحصلش ديد لوك؛ وبنرتّب FEFO في الذاكرة.
            $batches = Batch::where('warehouse_id', $w->id)
                ->where('product_id', $p->id)
                ->sellable()
                ->reorder('id')
                ->lockForUpdate()
                ->get()
                ->sortBy(fn (Batch $b) => $b->expires_on->format('Y-m-d').'#'.str_pad((string) $b->id, 12, '0', STR_PAD_LEFT))
                ->values();

            $available = (int) $batches->sum('qty_remaining');
            if ($need > $available) {
                throw new Rejected(__('stock.raw_not_enough', [
                    'product' => $p->name,
                    'available' => $available,
                ]));
            }

            $taken = [];
            $left = $need;

            foreach ($batches as $batch) {
                if ($left <= 0) {
                    break;
                }

                $take = min($left, (int) $batch->qty_remaining);

                // الجزء غير المترصّف الأول، والباقي من الأرفف
                $shelves = BatchLocation::join('locations', 'locations.id', '=', 'batch_locations.location_id')
                    ->where('batch_locations.batch_id', $batch->id)
                    ->where('batch_locations.qty', '>', 0)
                    ->orderByDesc('locations.is_pick_face')
                    ->orderBy('batch_locations.id')
                    ->select('batch_locations.*')
                    ->lockForUpdate()
                    ->get();

                $unshelved = max((int) $batch->qty_remaining - (int) $shelves->sum('qty'), 0);
                $fromShelves = max($take - $unshelved, 0);

                foreach ($shelves as $row) {
                    if ($fromShelves <= 0) {
                        break;
                    }
                    $n = min($fromShelves, (int) $row->qty);
                    if ($err = $row->take($n)) {
                        throw new Rejected($err);
                    }
                    $fromShelves -= $n;
                }

                $batch->decrement('qty_remaining', $take);
                $batch->increment('qty_issued', $take);

                $cost = (float) ($batch->cost ?? 0);

                RawStockMove::create([
                    'warehouse_id' => $w->id,
                    'product_id' => $p->id,
                    'batch_id' => $batch->id,
                    'qty' => $take,
                    'unit_cost' => $cost,
                    'for_type' => $for->getMorphClass(),
                    'for_id' => $for->getKey(),
                    'created_by' => auth()->id(),
                    'moved_at' => now(),
                ]);

                $taken[] = ['batch_id' => (int) $batch->id, 'qty' => (float) $take, 'cost' => $cost];
                $left -= $take;
            }

            StockCounting::resync($p->id, $w->id);

            return $taken;
        });
    }

    /** المتاح للصرف: مجموع الباتشات الغير موقوفة وغير المنتهية */
    public static function available(Warehouse $w, Product $p): int
    {
        return (int) Batch::where('warehouse_id', $w->id)
            ->where('product_id', $p->id)
            ->sellable()
            ->sum('qty_remaining');
    }
}
