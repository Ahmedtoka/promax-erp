<?php

namespace Database\Seeders\Concerns;

use App\Models\BatchLocation;
use App\Models\Custody;
use App\Models\PickOrder;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;

/**
 * ═══════════════════════════════════════════════════════════════
 * تحميل عربية في السيدرز — من المخزن بأمر تجهيز، مش من العدم
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ السيدرز كانت بتكتب `custody_items.assigned` مباشرة من غير باتش ولا
 * مخزن: بضاعة بتظهر في العربية والمخزن مايتحرّكش — عكس عقيدة «أي بضاعة
 * خرجت من مخزن عدّت على `PickOrder` بالـFEFO». هنا نفس مسار الواقع
 * بالحرف: `PickOrder::issueDirect` (تخطيط FEFO من أرفف المعادي → تجهيز →
 * جاهز) ثم `handOver` للموظف، فالعهدة بباتشاتها والأرفف نقصت فعلاً.
 *
 * ⚠️ لازم `WarehouseSeeder` يكون اتشغّل قبل — من غير أرفف مترصّفة مفيش
 * بضاعة تتحمّل، والسيدر بيطبع تحذير ويرجّع `null` بدل ما يزرع عهدة كاذبة.
 *
 * الكمية بتتقص على المتاح على الرف عشان الديمو مايقعش لو المخزون
 * الافتتاحي أقل من الخطة — والفرق بيتطبع عشان مايتخبّاش.
 */
trait LoadsVanFromWarehouse
{
    /**
     * @param  array<string|int, int>  $codeQty  كود الصنف ⇒ الكمية
     */
    protected function loadVanFromWarehouse(User $user, array $codeQty): ?Custody
    {
        // إعادة السيد مابتحمّلش تاني — عهدة النهارده بأصنافها موجودة
        $existing = Custody::where('user_id', $user->id)->whereDate('date', today())->first();

        if ($existing && $existing->items()->exists()) {
            return $existing;
        }

        $warehouse = Warehouse::where('code', 'MAADI')->first();

        if ($warehouse === null) {
            $this->command->warn('   ! مخزن المعادي مش موجود — WarehouseSeeder لازم قبل تحميل العربيات');

            return null;
        }

        $qtyByProduct = [];

        foreach ($codeQty as $code => $qty) {
            $product = Product::where('code', (string) $code)->first();

            if ($product === null) {
                continue;
            }

            $available = (int) BatchLocation::query()
                ->where('batch_locations.product_id', $product->id)
                ->inWarehouse($warehouse->id)
                ->sellable()
                ->sum('batch_locations.qty');

            $take = min((int) $qty, $available);

            if ($take < (int) $qty) {
                $this->command->warn("   ⚠️  {$code}: المطلوب {$qty} والمتاح على الرف {$available}");
            }

            if ($take > 0) {
                $qtyByProduct[$product->id] = $take;
            }
        }

        if ($qtyByProduct === []) {
            $this->command->warn('   ! مفيش بضاعة على أرفف المعادي — عربية '.$user->code.' فضلت فاضية');

            return null;
        }

        $admin = User::where('role', 'admin')->orderBy('id')->first();
        $issued = PickOrder::issueDirect($warehouse, $user, $qtyByProduct, [], $admin);

        if ($issued['error'] !== null) {
            $this->command->warn('   ! أمر تجهيز '.$user->code.': '.$issued['error']);

            return null;
        }

        if ($error = $issued['order']->handOver($user)) {
            $this->command->warn('   ! تسليم عهدة '.$user->code.': '.$error);

            return null;
        }

        return $user->todayCustody();
    }
}
