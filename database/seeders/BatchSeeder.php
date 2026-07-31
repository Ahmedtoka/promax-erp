<?php

namespace Database\Seeders;

use App\Models\Batch;
use App\Models\GoodsReceipt;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * بيحوّل المخزون الحالي (اللي كان رقم واحد بدون صلاحية) لباتشات حقيقية.
 *
 * القاعدة: كل منتج بياخد باتشين — واحد قديم شوية وواحد جديد —
 * عشان الشاشات تبان فيها حالات الصلاحية المختلفة من أول تشغيل،
 * ومجموعهم = الكمية الموجودة في stocks عشان الأرقام تفضل متطابقة.
 *
 * ⚠️ ماتشغلوش على داتا حقيقية فيها باتشات مسجلة — بيتخطى أي منتج
 * ليه باتشات بالفعل.
 */
class BatchSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('role', 'admin')->first();

        $receipt = GoodsReceipt::firstOrCreate(
            ['number' => 'GRN-1001'],
            [
                'received_on' => now()->subMonths(4)->startOfMonth(),
                'supplier' => 'PROMAX Production Line',
                'reference' => 'OPENING',
                'created_by' => $admin?->id,
                'notes' => 'رصيد افتتاحي — تحويل المخزون القديم لباتشات',
            ],
        );

        $made = 0;

        Product::with('stocks')->where('active', true)->get()
            ->each(function (Product $product) use ($receipt, &$made) {
                // لو ليه باتشات خلاص، سيبه
                if ($product->batches()->exists()) {
                    return;
                }

                $onHand = $product->qtyTotal();
                if ($onHand <= 0) {
                    return;
                }

                $shelfLife = $product->shelfLife();

                // الباتش القديم = 40% من الكمية، اتنتج من زمان
                // فتاريخ انتهاءه قريب → بيظهر في تنبيهات الصلاحية
                $oldQty = (int) round($onHand * 0.4);
                $newQty = $onHand - $oldQty;

                $oldProduced = Carbon::now()->subMonths(max($shelfLife - 2, 1));
                $newProduced = Carbon::now()->subMonths(1);

                if ($oldQty > 0) {
                    $this->make($product, $receipt, $oldProduced, $oldQty, 'A');
                    $made++;
                }
                if ($newQty > 0) {
                    $this->make($product, $receipt, $newProduced, $newQty, 'B');
                    $made++;
                }
            });

        $this->command->info("   • $made باتش اتعمل من المخزون الحالي");
    }

    private function make(Product $product, GoodsReceipt $receipt, Carbon $producedOn, int $qty, string $suffix): void
    {
        // رقم الباتش بالشكل المتعارف عليه في المصانع: PMX-YYMM-A
        $batchNo = 'PMX-'.$producedOn->format('ym').'-'.$suffix;

        Batch::updateOrCreate(
            ['product_id' => $product->id, 'batch_no' => $batchNo],
            [
                'goods_receipt_id' => $receipt->id,
                'produced_on' => $producedOn->toDateString(),
                'expires_on' => $product->expiryFrom($producedOn)->toDateString(),
                'qty_received' => $qty,
                'qty_remaining' => $qty,
                'qty_issued' => 0,
                'qty_damaged' => 0,
                // التكلفة تقريبية: 55% من سعر الكاش فان
                'cost' => (float) $product->cost > 0
                    ? (float) $product->cost
                    : round($product->sellingPrice() * 0.55, 2),
                'blocked' => false,
            ],
        );
    }
}
