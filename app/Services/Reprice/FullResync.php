<?php

namespace App\Services\Reprice;

use App\Models\Client;
use App\Models\ClientReturn;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PurchaseOrder;
use App\Models\ReturnItem;
use App\Models\Transaction;
use App\Services\Pricing;
use App\Services\Tax;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * إعادة تسعير كاملة في المكان — للمستندات اللي PriceChange بيسيبها
 * (قرار أحمد ١٠/١١/٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * «مفيش حاجة اترفعت ضريبي، والهدف السيستم يطابق الورق — عدّل كل حاجة».
 * محرك PriceChange بيسيب عن قصد:
 *   • أوامر التوريد اللي اتسلّمت أو مع السواق (قيدها اتسجّل بالسعر القديم)
 *   • الفواتير اللي عليها مرتجع (المرتجع اتحسب بالأسعار القديمة)
 *   • المرتجعات نفسها
 * الكلاس ده بيعيد تسعيرهم **في المكان** من نفس `Pricing`/`Tax::line`:
 *   - الكميات (والمسلَّم فعلاً) ماتتلمسش أبداً — البنود بتتعدّل مش بتتبني تاني
 *   - قيد البيع/المرتجع بيتعدّل لنفس الإجمالي الجديد
 *   - التحصيل والمرتجع النقدي (refund) فلوس اتدفعت فعلاً — ماتتلمسش،
 *     فالفرق بيروح على رصيد العميل (زي PriceChange بالظبط)
 *
 * ⚠️ مفيش recalculate هنا — المنادي بيعمله مرة واحدة للعملاء اللي اتلمسوا.
 * كل دالة بترجّع سجل قبل/بعد (بالسطور) أو null لو مفيش تغيير.
 */
class FullResync
{
    private const EPS = 0.005;

    /** فاتورة عليها مرتجع — نفس InvoiceRepricer من غير حارس المرتجع */
    public static function invoice(Invoice $inv): ?array
    {
        if (in_array((string) $inv->eta_status, ['exported', 'submitted'], true)) {
            return ['skip' => 'eta_locked'];
        }
        $plan = InvoiceRepricer::plan($inv);
        if ($plan['not_priced'] !== null) {
            return ['skip' => 'not_priced: '.$plan['not_priced']];
        }
        if (! $plan['changed']) {
            return null;
        }
        InvoiceRepricer::apply($inv, $plan, adjustCollection: false, recalc: false);

        return ['before' => $plan['before'], 'after' => $plan['after'], 'lines' => $plan['lines']];
    }

    /**
     * أمر توريد اتسلّم / مع السواق / بسعر ثابت — تعديل البنود في المكان
     * (`delivered_qty` محفوظ) + قيد البيع بالمسلَّم فعلاً.
     */
    public static function po(PurchaseOrder $po): ?array
    {
        if ($po->status === 'cancelled') {
            return null;
        }
        $po->load(['client', 'items.product']);
        $client = $po->client;
        if ($client === null) {
            return ['skip' => 'no_client'];
        }

        $plan = PoRepricer::planLines($client, $po->items);
        if ($plan['not_priced'] !== null) {
            return ['skip' => 'not_priced: '.$plan['not_priced']];
        }

        $before = ['net' => (float) $po->total, 'tax' => (float) $po->tax_total, 'grand' => (float) $po->grand_total];
        $byItem = collect($plan['lines'])->keyBy('item_id');
        $changed = false;
        foreach ($po->items as $it) {
            $l = $byItem[$it->id] ?? null;
            if ($l && (abs((float) $it->price - $l['price']) >= 0.00005 || abs((float) $it->total - $l['total']) >= self::EPS
                || abs((float) $it->tax - $l['tax']) >= self::EPS || abs((float) $it->list_price - $l['list_price']) >= 0.00005)) {
                $changed = true;
            }
        }
        $saleTx = Transaction::where('source_type', PurchaseOrder::class)->where('source_id', $po->id)->where('kind', 'sale')->first();
        if (! $changed && ! self::poSaleOff($po, $saleTx, $byItem)) {
            return null;
        }

        DB::transaction(function () use ($po, $byItem, $plan, $saleTx) {
            foreach ($po->items as $it) {
                if (! ($l = $byItem[$it->id] ?? null)) {
                    continue;
                }
                $it->forceFill([
                    'list_price' => $l['list_price'], 'price' => $l['price'], 'discount_pct' => $l['discount_pct'],
                    'total' => $l['total'], 'tax_rate' => $l['tax_rate'], 'tax' => $l['tax'],
                ])->save();
            }
            $po->forceFill([
                'total' => $plan['after']['net'], 'tax_total' => $plan['after']['tax'], 'grand_total' => $plan['after']['grand'],
                'price_mode' => $po->client->priceList(),
            ])->save();

            if ($po->status === 'delivered' && $saleTx) {
                [$net, $tax] = self::deliveredSums($po->fresh('items'));
                $saleTx->forceFill(['debit' => round($net + $tax, 2), 'tax' => $tax])->save();
            }
        });

        return ['before' => $before, 'after' => $plan['after'], 'lines' => $plan['lines'],
            'sale_before' => $saleTx ? (float) $saleTx->getOriginal('debit') : null, 'sale_after' => $saleTx ? (float) $saleTx->fresh()->debit : null];
    }

    /** مرتجع — بسعر سطر الفاتورة المربوطة (بعد إعادة تسعيرها)، وإلا سعر العميل الحالي */
    public static function clientReturn(ClientReturn $ret): ?array
    {
        $ret->load(['items.product']);
        $client = Client::find($ret->client_id);
        if ($client === null) {
            return ['skip' => 'no_client'];
        }
        $before = ['net' => (float) $ret->total, 'tax' => (float) $ret->tax_total, 'grand' => (float) $ret->grand_total];
        $lines = [];
        $sub = $net = $tax = 0.0;
        $updates = [];

        foreach ($ret->items as $ri) {
            if ($ri->product === null) {
                continue;
            }
            $src = $ri->invoice_item_id ? InvoiceItem::find($ri->invoice_item_id) : null;
            if ($src) {
                $price = (float) $src->price; $list = (float) $src->list_price; $rate = (float) $src->tax_rate;
            } else {
                $price = Pricing::unitPrice($client, $ri->product);
                $list = Pricing::listPriceFor($client, $ri->product);
                $rate = Tax::rate($client, $ri->product);
            }
            if ($price <= 0) {
                return ['skip' => 'not_priced: '.$ri->product->displayName()];
            }
            $calc = Tax::line($price, (int) $ri->qty, $rate);
            $updates[$ri->id] = ['list_price' => $list, 'price' => $price, 'tax_rate' => $rate, 'total' => $calc['net'], 'tax' => $calc['tax']];
            $lines[] = ['item_id' => $ri->id, 'product_id' => $ri->product_id, 'qty' => (int) $ri->qty,
                'old_price' => (float) $ri->price, 'price' => $price, 'old_total' => (float) $ri->total, 'total' => $calc['net'],
                'old_tax' => (float) $ri->tax, 'tax' => $calc['tax']];
            $sub += round($list * (int) $ri->qty, 2); $net += $calc['net']; $tax += $calc['tax'];
        }
        $after = ['net' => round($net, 2), 'tax' => round($tax, 2), 'grand' => round($net + $tax, 2)];
        $diff = false;
        foreach ($ret->items as $ri) {
            $u = $updates[$ri->id] ?? null;
            if ($u && (abs((float) $ri->total - $u['total']) >= self::EPS || abs((float) $ri->tax - $u['tax']) >= self::EPS || abs((float) $ri->price - $u['price']) >= 0.00005)) {
                $diff = true;
            }
        }
        if (! $diff && abs($before['grand'] - $after['grand']) < self::EPS) {
            return null;
        }

        DB::transaction(function () use ($ret, $updates, $sub, $after) {
            foreach ($updates as $id => $u) {
                ReturnItem::whereKey($id)->update($u);
            }
            $ret->forceFill([
                'subtotal' => round($sub, 2), 'discount' => round($sub - $after['net'], 2),
                'total' => $after['net'], 'tax_total' => $after['tax'], 'grand_total' => $after['grand'],
            ])->save();
            Transaction::where('source_type', ClientReturn::class)->where('source_id', $ret->id)->where('kind', 'return')
                ->update(['credit' => $after['grand'], 'tax' => $after['tax']]);
        });

        return ['before' => $before, 'after' => $after, 'lines' => $lines];
    }

    /** المسلَّم فعلاً: السطر الكامل بأرقامه، والجزئي بـ`Tax::line` (نفس كود التسليم) */
    private static function deliveredSums(PurchaseOrder $po): array
    {
        $net = $tax = 0.0;
        foreach ($po->items as $it) {
            $dq = $it->delivered_qty === null ? (int) $it->qty : (int) $it->delivered_qty;
            if ($dq === (int) $it->qty) {
                $net += (float) $it->total; $tax += (float) $it->tax;
            } else {
                $p = Tax::line((float) $it->price, $dq, (float) ($it->tax_rate ?? 0));
                $net += $p['net']; $tax += $p['tax'];
            }
        }

        return [round($net, 2), round($tax, 2)];
    }

    private static function poSaleOff(PurchaseOrder $po, ?Transaction $saleTx, $byItem): bool
    {
        if ($po->status !== 'delivered' || $saleTx === null) {
            return false;
        }
        [$n, $t] = self::deliveredSums($po);

        return abs((float) $saleTx->debit - round($n + $t, 2)) >= self::EPS;
    }
}
