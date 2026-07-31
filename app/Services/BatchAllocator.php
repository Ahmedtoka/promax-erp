<?php

namespace App\Services;

use App\Models\Batch;

/**
 * FEFO — First Expired, First Out.
 * الأقرب انتهاءً يخرج الأول. ده المكان الوحيد اللي بيقرر
 * أنهي باتش يخرج، عشان الويب والأبلكيشن والسيدر كلهم يمشوا بنفس القاعدة.
 *
 * الاستخدام:
 *   $plan = BatchAllocator::plan($productId, 120);
 *   if ($plan->shortage > 0) → مش كفاية
 *   foreach ($plan->lines as [$batch, $qty]) → ...
 *
 *   BatchAllocator::commit($plan);   // بيخصم فعلياً
 */
class BatchAllocator
{
    /**
     * بيحسب من أنهي باتشات هتتاخد الكمية — من غير ما يخصم حاجة.
     */
    public static function plan(int $productId, int $qty): AllocationPlan
    {
        $lines = [];
        $left = $qty;

        $batches = Batch::query()
            ->where('product_id', $productId)
            ->sellable()
            ->get();

        foreach ($batches as $batch) {
            if ($left <= 0) {
                break;
            }

            $take = min($left, (int) $batch->qty_remaining);
            if ($take <= 0) {
                continue;
            }

            $lines[] = [$batch, $take];
            $left -= $take;
        }

        return new AllocationPlan($productId, $qty, $lines, max($left, 0));
    }

    /**
     * بيخطط لكذا منتج مرة واحدة ويرجّع أول نقص يقابله.
     *
     * @param  array<int, int>  $wanted  [product_id => qty]
     * @return array{plans: array<int, AllocationPlan>, error: ?string}
     */
    public static function planMany(array $wanted): array
    {
        $plans = [];

        foreach ($wanted as $productId => $qty) {
            $plan = self::plan((int) $productId, (int) $qty);

            if ($plan->shortage > 0) {
                return [
                    'plans' => [],
                    'error' => __('stock.not_enough_for_product', [
                        'product' => $plan->productName(),
                        'short' => $plan->shortage,
                    ]),
                ];
            }

            $plans[(int) $productId] = $plan;
        }

        return ['plans' => $plans, 'error' => null];
    }

    /**
     * بينفّذ الخصم فعلياً. لازم يتنادى جوه DB::transaction.
     * بيرجع رسالة خطأ لو أي باتش اتغيّر بين التخطيط والتنفيذ.
     */
    public static function commit(AllocationPlan $plan): ?string
    {
        foreach ($plan->lines as [$batch, $qty]) {
            // بنعمل refresh عشان لو حد تاني خصم في نفس اللحظة
            $batch->refresh();

            if ($error = $batch->issue($qty)) {
                return $error;
            }
        }

        return null;
    }
}
