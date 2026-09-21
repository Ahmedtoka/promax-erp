<?php

namespace App\Services;

use App\Models\ClientReturn;
use App\Models\Invoice;
use App\Models\PurchaseOrder;
use App\Models\Visit;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * مصدر أرقام الفترة الموحّد — **من كشف الحساب** (قرار المالك ٢١ سبتمبر ٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * «مش عاوز أي رقم مش مساوي التاني». الداشبورد كانت بتجمع المستندات
 * (إجمالي الفاتورة/الأمر بتاريخ المستند) وصفحة العملاء والتارجيت
 * بيجمعوا `transactions` — فأغسطس طلع 1.377 مليون في شاشة و932 ألف
 * في التانية: توريدات الأمانة (قيدها `consignment` بصفر) والتسليم
 * الجزئي وتواريخ القيود كانوا الفرق.
 *
 * القاعدة من دلوقتي، وهي نفس `Client::recalculate()` و`TargetProgress`:
 *
 *   المبيعات  = Σ debit   للقيود `sale`        بتاريخ القيد (`date`)
 *   المرتجعات = Σ credit  للقيود `return`      بتاريخ القيد
 *   التحصيل   = Σ credit  للقيود `collection`  بتاريخ القيد
 *
 * المستند (فاتورة/أمر توريد) بيدّي القيد **صفاته** بس — مندوبه، كاش
 * ولا آجل، وبنوده — مش قيمته. بضاعة الأمانة مش مبيعات لحد ما تتباع.
 *
 *   docs()        قيد بيع لكل صف: kind (invoice|po|entry) · doc_id ·
 *                 client_id · user_id · doc_at · total · tax_total ·
 *                 grand_total · payment (cash|credit|po|entry)
 *   lines()       بنود مستندات قيود الفترة: kind · doc_id · client_id ·
 *                 user_id · product_id · qty · total · tax · cost
 *   returns()     قيد مرتجع لكل صف: doc_id · client_id · user_id · doc_at · amount
 *   collections() قيد تحصيل لكل صف: src (invoice|visit|po|office) ·
 *                 client_id · user_id · doc_at · amount · method
 *
 * ⚠️ `user_id`: صاحب المستند (مندوب الفاتورة / اللي سلّم الأمر / صاحب
 * الزيارة)، والقيد اللي مالوش مستند بيتحسب لمندوب العميل — نفس قاعدة
 * التجميع في التارجيت. تحصيل المكتب مالوش مندوب.
 * ⚠️ بند أمر التوريد بقيمة **المسلَّم** بنفس قاعدة قيد التسليم بالحرف
 * (السطر الكامل بأرقامه المخزنة، والجزئي `delivered_qty × price`).
 * ⚠️ القيد اللي مالوش بنود (استيراد/قيد يدوي) مش بيظهر في `lines()` —
 * تقارير الأصناف بتعرض الفرق ده في سطر «قيود بلا بنود».
 */
final class SalesSource
{
    /** قيود الفترة لنوع معيّن، مربوطة بمستنداتها وبالعميل */
    private static function ledger(string $kind, Carbon $a, Carbon $b): Builder
    {
        return DB::table('transactions as t')
            ->join('clients as c', 'c.id', '=', 't.client_id')
            ->where('t.kind', $kind)
            ->whereBetween('t.date', [$a->toDateString(), $b->toDateString()]);
    }

    private static function joinDocs(Builder $q): Builder
    {
        return $q
            ->leftJoin('invoices as i', fn ($j) => $j->on('i.id', '=', 't.source_id')
                ->where('t.source_type', '=', Invoice::class))
            ->leftJoin('purchase_orders as p', fn ($j) => $j->on('p.id', '=', 't.source_id')
                ->where('t.source_type', '=', PurchaseOrder::class));
    }

    public static function docs(Carbon $a, Carbon $b, ?array $repIds = null): Builder
    {
        $q = self::joinDocs(self::ledger('sale', $a, $b))
            ->when($repIds, fn ($w) => $w->whereIn(DB::raw('COALESCE(i.user_id, p.assigned_to, c.rep_id)'), $repIds))
            ->selectRaw("CASE WHEN i.id IS NOT NULL THEN 'invoice' WHEN p.id IS NOT NULL THEN 'po' ELSE 'entry' END AS kind,
                t.source_id AS doc_id, t.client_id, COALESCE(i.user_id, p.assigned_to, c.rep_id) AS user_id,
                t.date AS doc_at, (t.debit - COALESCE(t.tax, 0)) AS total, COALESCE(t.tax, 0) AS tax_total,
                t.debit AS grand_total,
                CASE WHEN i.id IS NOT NULL THEN i.payment WHEN p.id IS NOT NULL THEN 'po' ELSE 'entry' END AS payment");

        return DB::query()->fromSub($q, 's');
    }

    public static function lines(Carbon $a, Carbon $b, ?array $repIds = null): Builder
    {
        $inv = self::ledger('sale', $a, $b)
            ->join('invoices as i', fn ($j) => $j->on('i.id', '=', 't.source_id')
                ->where('t.source_type', '=', Invoice::class))
            ->join('invoice_items as ii', 'ii.invoice_id', '=', 'i.id')
            ->when($repIds, fn ($w) => $w->whereIn('i.user_id', $repIds))
            ->selectRaw("'invoice' AS kind, i.id AS doc_id, t.client_id, i.user_id AS user_id, ii.product_id,
                ii.qty AS qty, ii.total AS total, ii.tax AS tax, (ii.qty * ii.unit_cost) AS cost");

        $full = 'pi.delivered_qty IS NULL OR pi.delivered_qty = pi.qty';
        $po = self::ledger('sale', $a, $b)
            ->join('purchase_orders as p', fn ($j) => $j->on('p.id', '=', 't.source_id')
                ->where('t.source_type', '=', PurchaseOrder::class))
            ->join('purchase_order_items as pi', 'pi.purchase_order_id', '=', 'p.id')
            ->join('products as pr', 'pr.id', '=', 'pi.product_id')
            ->when($repIds, fn ($w) => $w->whereIn('p.assigned_to', $repIds))
            ->selectRaw("'po' AS kind, p.id AS doc_id, t.client_id, p.assigned_to AS user_id, pi.product_id,
                COALESCE(pi.delivered_qty, pi.qty) AS qty,
                CASE WHEN $full THEN pi.total ELSE ROUND(pi.delivered_qty * pi.price, 2) END AS total,
                CASE WHEN $full THEN pi.tax
                     ELSE ROUND(ROUND(pi.delivered_qty * pi.price, 2) * COALESCE(pi.tax_rate, 0), 2) END AS tax,
                (COALESCE(pi.delivered_qty, pi.qty) * COALESCE(pr.cost, 0)) AS cost");

        return DB::query()->fromSub($inv->unionAll($po), 's');
    }

    public static function returns(Carbon $a, Carbon $b, ?array $repIds = null): Builder
    {
        $q = self::ledger('return', $a, $b)
            ->leftJoin('returns as r', fn ($j) => $j->on('r.id', '=', 't.source_id')
                ->where('t.source_type', '=', ClientReturn::class))
            ->when($repIds, fn ($w) => $w->whereIn(DB::raw('COALESCE(r.user_id, c.rep_id)'), $repIds))
            ->selectRaw('t.source_id AS doc_id, t.client_id, COALESCE(r.user_id, c.rep_id) AS user_id,
                t.date AS doc_at, t.credit AS amount');

        return DB::query()->fromSub($q, 's');
    }

    public static function collections(Carbon $a, Carbon $b, ?array $repIds = null): Builder
    {
        $owner = 'COALESCE(i.user_id, v.user_id, p.assigned_to)';

        $q = self::joinDocs(self::ledger('collection', $a, $b))
            ->leftJoin('visits as v', fn ($j) => $j->on('v.id', '=', 't.source_id')
                ->where('t.source_type', '=', Visit::class))
            ->when($repIds, fn ($w) => $w->whereIn(DB::raw($owner), $repIds))
            ->selectRaw("CASE WHEN i.id IS NOT NULL THEN 'invoice' WHEN v.id IS NOT NULL THEN 'visit'
                     WHEN p.id IS NOT NULL THEN 'po' ELSE 'office' END AS src,
                t.client_id, $owner AS user_id, t.date AS doc_at, t.credit AS amount, t.method");

        return DB::query()->fromSub($q, 's');
    }
}
