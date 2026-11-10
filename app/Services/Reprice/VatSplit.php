<?php

namespace App\Services\Reprice;

use App\Models\ClientReturn;
use App\Models\Invoice;
use App\Models\PurchaseOrder;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * فصل الضريبة من غير ما المبلغ يتغيّر — الكاش فان وبونجور (قرار ١٠/١١/٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * أحمد: «كل الناس خاضعة للضريبة… لما باخد ٨٠ جنيه دي شاملة الضريبة».
 * العملاء دول اتباعلهم بقايمة أسعارها شاملة ومن غير ضريبة ظاهرة، فالمبلغ
 * اللي دفعوه **هو الشامل**. التصليح بيفصل الضريبة جوه نفس المبلغ:
 *
 *   السطر شامل = اللي اتدفع (total + tax القديم)        ← مابيتغيرش
 *   السطر صافي = round(الشامل ÷ (1 + 14٪)، 2)
 *   الضريبة    = الشامل − الصافي
 *   سعر الوحدة/القايمة غير شامل = القديم ÷ 1.14 (٤ أرقام)
 *
 * ⚠️ القيد (debit/credit) والرصيد **مابيتغيروش** — بس عمود الضريبة على القيد.
 * ⚠️ السطر اللي عليه ضريبة أصلاً بيتساب زي ما هو (اتفصل قبل كده).
 */
class VatSplit
{
    private const RATE = 0.14;

    /** @return array|null سجل قبل/بعد أو null لو مفيش تغيير */
    public static function invoice(Invoice $inv): ?array
    {
        return self::doc($inv, $inv->items()->get(), Transaction::where('source_type', Invoice::class)->where('source_id', $inv->id)->where('kind', 'sale'));
    }

    public static function po(PurchaseOrder $po): ?array
    {
        if ($po->status === 'cancelled') {
            return null;
        }

        return self::doc($po, $po->items()->get(), Transaction::where('source_type', PurchaseOrder::class)->where('source_id', $po->id)->where('kind', 'sale'));
    }

    public static function clientReturn(ClientReturn $ret): ?array
    {
        return self::doc($ret, $ret->items()->get(), Transaction::where('source_type', ClientReturn::class)->where('source_id', $ret->id)->where('kind', 'return'));
    }

    private static function doc($doc, $items, $txQuery): ?array
    {
        $before = ['net' => (float) $doc->total, 'tax' => (float) $doc->tax_total, 'grand' => (float) $doc->grand_total];
        $lines = [];
        foreach ($items as $it) {
            if ((float) $it->tax > 0 || (int) $it->qty === 0) {
                continue; // اتفصل قبل كده
            }
            $incl = round((float) $it->total, 2);
            $net = round($incl / (1 + self::RATE), 2);
            $lines[$it->id] = [
                'item' => $it, 'qty' => (int) $it->qty, 'old_price' => (float) $it->price, 'old_total' => (float) $it->total, 'old_tax' => 0.0,
                'price' => round((float) $it->price / (1 + self::RATE), 4),
                'list_price' => $it->list_price !== null ? round((float) $it->list_price / (1 + self::RATE), 4) : null,
                'total' => $net, 'tax' => round($incl - $net, 2), 'incl' => $incl,
            ];
        }
        if ($lines === []) {
            return null;
        }

        DB::transaction(function () use ($doc, $items, $lines, $txQuery) {
            foreach ($lines as $l) {
                $f = ['price' => $l['price'], 'total' => $l['total'], 'tax' => $l['tax'], 'tax_rate' => self::RATE];
                if ($l['list_price'] !== null) {
                    $f['list_price'] = $l['list_price'];
                }
                $l['item']->forceFill($f)->save();
            }
            $fresh = $items->map(fn ($it) => $it->fresh());
            $net = round($fresh->sum(fn ($i) => (float) $i->total), 2);
            $tax = round($fresh->sum(fn ($i) => (float) $i->tax), 2);
            $hdr = ['total' => $net, 'tax_total' => $tax];
            if ($doc->getAttribute('subtotal') !== null) {
                $sub = round($fresh->sum(fn ($i) => round((float) ($i->list_price ?? $i->price) * (int) $i->qty, 2)), 2);
                $hdr['subtotal'] = $sub;
                if ($doc->getAttribute('discount') !== null) {
                    $hdr['discount'] = round($sub - $net, 2);
                }
            }
            // ⚠️ grand_total مابيتغيرش — نفس المبلغ اللي اتدفع
            $doc->forceFill($hdr)->save();
            $txQuery->update(['tax' => $tax]);
        });

        $doc->refresh();

        return ['before' => $before, 'after' => ['net' => (float) $doc->total, 'tax' => (float) $doc->tax_total, 'grand' => (float) $doc->grand_total],
            'lines' => array_values(array_map(fn ($l) => collect($l)->except('item')->all() + ['item_id' => $l['item']->id], $lines))];
    }
}
