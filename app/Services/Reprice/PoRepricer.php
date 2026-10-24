<?php

namespace App\Services\Reprice;

use App\Models\Client;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Services\Pricing;
use App\Services\Tax;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * إعادة تسعير أمر توريد واحد بتسعيرة العميل الحالية — الجوهر
 * ═══════════════════════════════════════════════════════════════
 *
 * منقول من `OpsController` (٢٤/١٠) **من غير أي تغيير في السلوك**:
 *
 *   • `fillItems()` — هي `fillPoItems` القديمة بالحرف (بناء البنود
 *     وتسعيرها وإجمالياته) — الإنشاء والتعديل والرفع كلهم بينادوها.
 *   • `blocker()` — اللي يمنع إعادة التسعير الجماعية: ملغي / مُسلَّم /
 *     من غير عميل / سعر صافي متفق عليه (`price_mode` = old/new، نفس
 *     استثناء شاشة الفحص `repriceCheck`).
 *   • `plan()` — قراءة بس، نفس حسبة `expectedPoTotals` بالحرف.
 *   • `apply()` — جسم `repricePoCore` بعد فحص الحالة: البنود بتتبني
 *     تاني **بنفس الكميات** عبر `fillItems`.
 *
 * ⚠️⚠️ **إعادة التسعير ماترجّعش الأمر للحسابات** (قرار المالك ٢٤/٨) —
 * السعر بيتغير في مكانه، والحالة والموافقة والتجهيز ماحدش يلمسهم.
 */
class PoRepricer
{
    /** فرق أقل من كده = نفس الرقم (قرشين مقرّبين) */
    private const EPS = 0.005;

    /**
     * أول سبب يمنع إعادة التسعير الجماعية — أو null.
     *
     * @return array{key: string, detail: null}|null
     */
    public static function blocker(PurchaseOrder $po): ?array
    {
        if ($po->status === 'cancelled') {
            return ['key' => 'po_cancelled', 'detail' => null];
        }

        // بعد التسليم القيود اتسجلت على السعر القديم — التصحيح
        // ساعتها مرتجع/تسوية مش إعادة تسعير صامتة
        if ($po->status === 'delivered') {
            return ['key' => 'po_delivered', 'detail' => null];
        }

        if ($po->client_id === null || $po->client === null) {
            return ['key' => 'no_client', 'detail' => null];
        }

        // أسعار صافية متفق عليها مع السلسلة عن قصد — مش تسعيرة عميل تتحدث
        if (in_array((string) $po->price_mode, ['old', 'new'], true)) {
            return ['key' => 'po_fixed_price', 'detail' => null];
        }

        return null;
    }

    /**
     * الأرقام المتوقعة لو الأمر اتسعّر بتسعيرة عميله الحالية — **قراءة بس**.
     *
     * @return array{
     *   not_priced: ?string, changed: bool,
     *   before: array{net: float, tax: float, grand: float},
     *   after: array{net: float, tax: float, grand: float},
     *   lines: list<array<string, mixed>>
     * }
     */
    public static function plan(PurchaseOrder $po): array
    {
        $po->loadMissing(['client', 'items.product']);

        $plan = self::planLines($po->client, $po->items);

        $plan['before'] = [
            'net' => (float) $po->total,
            'tax' => (float) $po->tax_total,
            'grand' => (float) $po->grand_total,
        ];

        $plan['changed'] = $plan['not_priced'] === null && self::differs($plan);

        return $plan;
    }

    /**
     * الحسبة نفسها على عميل + بنود — اللي `expectedPoTotals` بتناديها.
     * نفس حسبة `fillItems` بالحرف (وضع channel) من غير أي كتابة.
     *
     * لو صنف مش متسعّر: `not_priced` = اسمه، و`after`/`lines` فاضيين.
     *
     * @param  iterable<\App\Models\PurchaseOrderItem>  $items
     */
    public static function planLines(Client $client, iterable $items): array
    {
        $rows = [];
        $lines = [];

        foreach ($items as $item) {
            $product = $item->product;

            if ($product === null) {
                continue;
            }

            $price = $client->priceFor($product);

            if ((float) $price <= 0) {
                return ['not_priced' => $product->displayName(), 'changed' => false, 'after' => [], 'lines' => []];
            }

            $lineTotal = round((int) $item->qty * $price, 2);
            $lineTax = Tax::on($lineTotal, $client, $product);

            // سعر القايمة قبل الخصم — نفس احتياطي `fillItems`
            $listPrice = Pricing::listPriceFor($client, $product);
            if ($listPrice <= 0) {
                $listPrice = (float) $price;
            }

            $lines[] = [
                'item_id' => $item->id,
                'product_id' => $product->id,
                'name' => $product->displayName(),
                'qty' => (int) $item->qty,
                'old_list' => (float) $item->list_price,
                'old_price' => (float) $item->price,
                'old_total' => (float) $item->total,
                'old_tax' => (float) $item->tax,
                'old_discount_pct' => (float) $item->discount_pct,
                'list_price' => $listPrice,
                'price' => (float) $price,
                'total' => $lineTotal,
                'tax_rate' => Tax::rate($client, $product),
                'tax' => $lineTax,
                'discount_pct' => $client->effectiveDiscount(),
            ];

            $rows[] = ['total' => $lineTotal, 'tax' => $lineTax];
        }

        $sums = Tax::totals($rows);

        return [
            'not_priced' => null,
            'changed' => false,
            'after' => ['net' => (float) $sums['net'], 'tax' => (float) $sums['tax'], 'grand' => (float) $sums['grand']],
            'lines' => $lines,
        ];
    }

    /**
     * الكتابة — جسم `repricePoCore` بعد فحص الحالة. بترجّع رسالة
     * الرفض أو null. السكوب وفحص الحالة مسؤولية المنادي.
     */
    public static function apply(PurchaseOrder $po): ?string
    {
        $client = $po->client;

        if ($client === null) {
            return __('ops.po_needs_rep_wh');
        }

        // الكميات بالقطع زي ما هي — إعادة التسعير ماتلمسش الكميات
        $qty = $po->items->pluck('qty', 'product_id')
            ->map(fn ($q) => (int) $q)->all();

        try {
            DB::transaction(function () use ($po, $client, $qty) {
                // بنود جديدة بالكامل — نفس قاعدة التعديل: إعادة بناء مش ترقيع
                $po->items()->delete();
                self::fillItems($po, $client, $qty, 'channel');

                $po->update([
                    'price_mode' => $client->priceList(),
                    'was_edited' => true,
                    'edited_by' => auth()->id(),
                    'edited_at' => now(),
                ]);
            });
        } catch (\App\Exceptions\Rejected $e) {
            return $e->getMessage();
        }

        return null;
    }

    /** فيه فرق فلوس؟ — كل سطر + الإجماليات المخزّنة على الأمر */
    private static function differs(array $plan): bool
    {
        foreach ($plan['lines'] as $l) {
            if (abs($l['old_list'] - $l['list_price']) >= self::EPS
                || abs($l['old_price'] - $l['price']) >= self::EPS
                || abs($l['old_total'] - $l['total']) >= self::EPS
                || abs($l['old_tax'] - $l['tax']) >= self::EPS
                || abs(round($l['old_discount_pct'], 4) - round($l['discount_pct'], 4)) >= 0.00005) {
                return true;
            }
        }

        return abs($plan['before']['net'] - $plan['after']['net']) >= self::EPS
            || abs($plan['before']['tax'] - $plan['after']['tax']) >= self::EPS
            || abs($plan['before']['grand'] - $plan['after']['grand']) >= self::EPS;
    }

    /**
     * بناء بنود الأمر وتسعيرها وإجمالياته — مشترك بين الإنشاء والتعديل.
     *
     * ⚠️ channel = سعر العميل بخصمه (زي الفاتورة). old/new = سعر قائمة
     * بدون خصم — مقصود لسلاسل بتتحاسب بسعر صافي متفق عليه.
     *
     * ⚠️ **سعر صفر = رفض الأمر كله** (نفس دوكترين الفاتورة «الصنف مش
     * متسعّر») — أمر PO-2001 دخل فعلاً بصنف بسعر 0.00 وكان هيتقيّد
     * على الفرع ناقص (اتشاف 2026-08-04).
     */
    public static function fillItems(PurchaseOrder $po, Client $client, array $qtyByProduct, string $priceMode): void
    {
        $rows = [];

        foreach ($qtyByProduct as $productId => $qty) {
            $qty = (int) $qty;
            if ($qty <= 0) {
                continue;
            }
            $product = Product::find($productId);
            if (! $product) {
                continue;
            }

            // ⚠️ **الصنف الدرافت بيترفض بصوت عالي مش `continue`** (١٧/٨).
            // التخطي في صمت هنا كان هيخلّي أمر التوريد يتحفظ **ناقص
            // سطر** والمستخدم فاكر إنه اتحفظ كامل — والعميل يستلم أقل
            // مما اتفق عليه. الرفض بيوقف الحفظ كله ويقول اسم الصنف.
            if ($reason = $product->notSellableReason()) {
                throw new \App\Exceptions\Rejected($reason);
            }

            $price = $priceMode === 'channel'
                ? $client->priceFor($product)
                : $product->priceFor($priceMode);

            if ((float) $price <= 0) {
                throw new \App\Exceptions\Rejected(
                    __('stock.po_not_priced', ['name' => $product->displayName()])
                );
            }

            // ⚠️ **الخصم بيتسجّل على السطر مش بيتقرا وقت الطباعة.**
            // خصم العميل بيتغيّر (عقد بينتهي، نسبة بتتظبط)، والورقة
            // اللي الفرع بيمضي عليها لازم تفضل شاهدة على اللحظة اللي
            // الأمر اتسعّر فيها. `list_price` قبل الخصم، و`price`
            // فضل بعد الخصم زي ما هو عشان `total` مايتحركش.
            //
            // ⚠️ وضع `old`/`new` **مالوش خصم أصلاً** — ده سعر قايمة
            // صافي متفق عليه مع السلسلة. اشتقاق النسبة من الفرق
            // بين القايمتين كان هيطبع «خصم 8%» على أمر محدش اتفق
            // فيه على خصم.
            //
            // ⚠️ **النسبة بتتاخد من العميل مباشرة، مش بتتعكس من السعر.**
            // `Pricing::unitPrice` بتقرّب لقرشين قبل ما نقسم، فعكسها
            // (`1 - price/list`) كان بيطلّع 9.98% لعقد 10% على صنف
            // سعره 13.33 — ومستند بيتمضى عليه بيقول نسبة مختلفة عن
            // العقد وعن فاتورة نفس العميل (اللي بتخزّن الكسر الصح).
            $discountPct = $priceMode === 'channel' ? $client->effectiveDiscount() : 0.0;

            $listPrice = $priceMode === 'channel'
                ? \App\Services\Pricing::listPriceFor($client, $product)
                : (float) $price;

            // القايمة الناقصة بترجّع 0 — ساعتها السعر المطبوع هو المتاح
            if ($listPrice <= 0) {
                $listPrice = (float) $price;
            }

            $lineTotal = round($qty * $price, 2);

            // الضريبة سطر بسطر من `Tax` — نفس قاعدة الفاتورة بالظبط
            $taxRate = \App\Services\Tax::rate($client, $product);
            $lineTax = \App\Services\Tax::on($lineTotal, $client, $product);

            PurchaseOrderItem::create([
                'purchase_order_id' => $po->id,
                'product_id' => $product->id,
                'qty' => $qty,
                'price' => $price,
                'list_price' => $listPrice,
                'discount_pct' => $discountPct,
                'total' => $lineTotal,
                'tax_rate' => $taxRate,
                'tax' => $lineTax,
            ]);

            $rows[] = ['total' => $lineTotal, 'tax' => $lineTax];
        }

        // `total` صافي المبيعات، و`grand_total` اللي بيتقيّد عند التسليم
        $sums = \App\Services\Tax::totals($rows);

        $po->update([
            'total' => $sums['net'],
            'tax_total' => $sums['tax'],
            'grand_total' => $sums['grand'],
        ]);
    }
}
