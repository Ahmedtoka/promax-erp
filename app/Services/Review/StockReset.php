<?php

namespace App\Services\Review;

use App\Exceptions\Rejected;
use App\Models\Batch;
use App\Models\BatchLocation;
use App\Models\Product;
use App\Models\ReviewStockMove;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\OpeningStock;
use App\Services\StockCounting;
use Illuminate\Support\Facades\DB;

/**
 * المرحلة ٣ من مراجعة إعادة التشغيل: تصفير مخزن، وإدخال رصيده من الورق بالباتش.
 *
 * ⚠️ التصفير **مش عجز ولا توالف** — `qty_remaining` بيتصفّر من غير ما يتكتب
 * `qty_damaged`، لأن الرصيد القديم غلط مش بضاعة باظت. الكمية القديمة لكل
 * باتش بتتسجل في `review_stock_moves` (kind=zero) عشان الأثر والتراجع.
 *
 * ⚠️ الإدخال بيعمل باتش بالرقم والصلاحية اللي على الورق (أو يزوّد على
 * نفس الباتش لو موجود في نفس المخزن)، وبيحطه على رف السحب زي `OpeningStock`.
 *
 * ⚠️ مفيش قيد دفتر — زي الجرد والتسوية النهارده. قيمة المخزون الافتتاحية
 * بتتحسب في معالج الدفتر (InventoryOpening) من الباتشات نفسها بعد كده.
 */
class StockReset
{
    /** @return int عدد الباتشات اللي اتصفّرت */
    public static function zeroWarehouse(Warehouse $warehouse, User $by): int
    {
        return DB::transaction(function () use ($warehouse, $by) {
            $batches = Batch::where('warehouse_id', $warehouse->id)
                ->where('qty_remaining', '>', 0)
                ->lockForUpdate()->orderBy('id')->get();

            foreach ($batches as $b) {
                ReviewStockMove::create([
                    'kind' => 'zero', 'warehouse_id' => $warehouse->id, 'product_id' => $b->product_id,
                    'batch_id' => $b->id, 'batch_no' => $b->batch_no, 'expires_on' => $b->expires_on,
                    'qty' => (int) $b->qty_remaining, 'cost' => $b->cost, 'user_id' => $by->id,
                ]);

                $b->update(['qty_remaining' => 0]);
                BatchLocation::where('batch_id', $b->id)->update(['qty' => 0]);
            }

            foreach ($batches->pluck('product_id')->unique() as $productId) {
                StockCounting::resync((int) $productId, $warehouse->id);
            }

            return $batches->count();
        });
    }

    public static function addBatch(Warehouse $warehouse, Product $product, string $batchNo, string $expiresOn, int $qty, ?float $cost, User $by): Batch
    {
        if ($qty <= 0) {
            throw new Rejected(__('review.qty_positive'));
        }

        return DB::transaction(function () use ($warehouse, $product, $batchNo, $expiresOn, $qty, $cost, $by) {
            $cost ??= (float) $product->cost;

            $batch = Batch::where('warehouse_id', $warehouse->id)->where('product_id', $product->id)
                ->where('batch_no', $batchNo)->lockForUpdate()->first();

            if ($batch === null) {
                $batch = Batch::create([
                    'product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'batch_no' => $batchNo,
                    'expires_on' => $expiresOn,
                    'qty_received' => $qty, 'qty_remaining' => $qty, 'qty_issued' => 0, 'qty_damaged' => 0,
                    'cost' => $cost, 'blocked' => false, 'notes' => __('review.opening_batch_note'),
                ]);
            } else {
                // ⚠️ نفس الباتش بصلاحية مختلفة = غلطة كتابة — بنرفض بدل ما نلبّس الكمية صلاحية غلط
                if ($batch->expires_on?->toDateString() !== $expiresOn) {
                    throw new Rejected(__('review.batch_expiry_mismatch', ['batch' => $batchNo, 'date' => $batch->expires_on?->toDateString()]));
                }
                $batch->update([
                    'qty_received' => (int) $batch->qty_received + $qty,
                    'qty_remaining' => (int) $batch->qty_remaining + $qty,
                ]);
            }

            $shelf = OpeningStock::pickShelf($warehouse);
            $loc = BatchLocation::firstOrNew(['batch_id' => $batch->id, 'location_id' => $shelf->id], ['product_id' => $product->id, 'qty' => 0]);
            $loc->qty = (int) $loc->qty + $qty;
            $loc->save();

            ReviewStockMove::create([
                'kind' => 'opening', 'warehouse_id' => $warehouse->id, 'product_id' => $product->id,
                'batch_id' => $batch->id, 'batch_no' => $batchNo, 'expires_on' => $expiresOn,
                'qty' => $qty, 'cost' => $cost, 'user_id' => $by->id,
            ]);

            StockCounting::resync($product->id, $warehouse->id);

            return $batch;
        });
    }

    /** رصيد المندوب الحالي بالقطعة — من عهده المفتوحة */
    public static function repRemaining(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return DB::table('custody_items')
            ->join('custodies', 'custodies.id', '=', 'custody_items.custody_id')
            ->whereIn('custodies.user_id', $userIds)
            ->where(fn ($q) => $q->whereNull('custodies.status')->orWhere('custodies.status', '<>', 'closed'))
            ->groupBy('custodies.user_id')
            ->selectRaw('custodies.user_id AS uid, SUM(custody_items.assigned - custody_items.sold - custody_items.returned - COALESCE(custody_items.transferred_out, 0)) AS q')
            ->pluck('q', 'uid')->map(fn ($q) => (int) $q)->all();
    }
}
