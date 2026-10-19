<?php

namespace App\Services\Mfg;

use App\Models\Batch;
use App\Models\Client;
use App\Models\CustodyItem;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ProductionIssue;
use App\Models\ProductionOrder;
use App\Models\User;
use App\Support\DateRange;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * جينيالوجي اللوطات والسحب (التصنيع ١٩/١٠، spec §3.5 — مهمة ٧).
 *
 * ⚠️ **التوائم:** التحويل بين المخازن (المصنع ← المعادي) بيعمل صف باتش جديد
 * بنفس `product_id` + `batch_no`. كل الدوال هنا شغالة على كل التوائم —
 * اللوط هو المنتج + رقم الباتش، مش صف واحد.
 * ⚠️ السحب على مستوى الشركة كلها: الشاشات دي داخلية
 * (`role:admin,production_manager,quality`)، والرولز دي مالهاش مفهوم نطاق
 * عملاء — فكل العملاء بيظهروا للكل (السحب لازم يوصل لكل اللي استلم).
 */
class Genealogy
{
    /** صفوف نفس اللوط في كل المخازن (المنتج + رقم الباتش) */
    public static function twinIds(Batch $b): array
    {
        return Batch::query()->where('product_id', $b->product_id)
            ->where('batch_no', $b->batch_no)->pluck('id')->all();
    }

    /**
     * باتش تام ← لوطات الخام: الصافي (صرف + زيادة − مرتجع) لكل باتش خام
     * على الأوامر اللي طلّعت اللوط ده.
     *
     * @return Collection<int, array{batch: Batch, product: \App\Models\Product|null, qty: float, value: float}>
     */
    public static function rawLotsFor(Batch $fg): Collection
    {
        $twins = self::twinIds($fg);

        $orderIds = ProductionOrder::query()
            ->where(fn ($q) => $q->whereIn('fg_batch_id', $twins)
                ->orWhere(fn ($w) => $w->where('product_id', $fg->product_id)
                    ->where('batch_no', $fg->batch_no)->where('status', 'closed')))
            ->pluck('id');

        if ($orderIds->isEmpty()) {
            return collect();
        }

        $net = ProductionIssue::query()->whereIn('production_order_id', $orderIds)
            ->groupBy('batch_id')
            ->select('batch_id',
                DB::raw("SUM(CASE WHEN kind = 'return' THEN -qty ELSE qty END) AS net_qty"),
                DB::raw("SUM(CASE WHEN kind = 'return' THEN -value ELSE value END) AS net_value"))
            ->get()
            ->filter(fn ($r) => (float) $r->net_qty > 0.0005);

        $batches = Batch::query()->with('product')->whereIn('id', $net->pluck('batch_id'))->get()->keyBy('id');

        return $net->map(fn ($r) => isset($batches[$r->batch_id]) ? [
            'batch' => $batches[$r->batch_id],
            'product' => $batches[$r->batch_id]->product,
            'qty' => round((float) $r->net_qty, 3),
            'value' => round((float) $r->net_value, 2),
        ] : null)->filter()
            ->sortBy(fn ($l) => [$l['product']?->code, $l['batch']->batch_no])
            ->values();
    }

    /**
     * لوط خام ← أوامر الإنتاج اللي صافي صرفها منه > صفر (ومعاها باتشها التام).
     * `lot_qty` على كل أمر = الصافي اللي اتصرف من اللوط (للعرض بس — مش عمود).
     *
     * @return Collection<int, ProductionOrder>
     */
    public static function finishedFor(Batch $raw): Collection
    {
        $net = ProductionIssue::query()->whereIn('batch_id', self::twinIds($raw))
            ->groupBy('production_order_id')
            ->select('production_order_id', DB::raw("SUM(CASE WHEN kind = 'return' THEN -qty ELSE qty END) AS net_qty"))
            ->pluck('net_qty', 'production_order_id')
            ->map(fn ($v) => round((float) $v, 3))
            ->filter(fn (float $v) => $v > 0.0005);

        if ($net->isEmpty()) {
            return collect();
        }

        return ProductionOrder::query()->with(['product', 'fgBatch'])
            ->whereIn('id', $net->keys())
            ->orderByDesc('produced_on')->orderByDesc('id')
            ->get()
            ->each(fn (ProductionOrder $o) => $o->setAttribute('lot_qty', $net[$o->id]));
    }

    /**
     * مين استلم اللوط (كل التوائم): فواتير المناديب + أوامر الشراء المسلّمة
     * (عن طريق أمر التجهيز) + العينات المجانية لعميل.
     * التاريخ = تاريخ الفاتورة / `delivered_at`، والفلتر عليه.
     *
     * @return Collection<int, array{client: Client, qty: int, via: 'invoice'|'sales_order'|'gift', ref: string, date: \Illuminate\Support\Carbon|null}>
     */
    public static function recipientsFor(Batch $fg, ?DateRange $range = null): Collection
    {
        $twins = self::twinIds($fg);

        $items = InvoiceItem::query()->whereIn('batch_id', $twins)
            ->whereHas('invoice', function ($q) use ($range) {
                // الفواتير مالهاش عمود حالة النهارده؛ لو اتزوّد، الملغي برّه
                if (self::invoicesHaveStatus()) {
                    $q->where('status', '!=', 'cancelled');
                }
                $range?->apply($q, 'created_at');
            })
            ->with('invoice.client')->get();

        $fromInvoices = $items->groupBy('invoice_id')->map(function (Collection $lines) {
            $inv = $lines->first()->invoice;

            return [
                'client' => $inv->client,
                'qty' => (int) $lines->sum('qty'),
                'via' => 'invoice',
                'ref' => (string) $inv->number,
                'date' => $inv->created_at,
            ];
        });

        // ⚠️ تسليم أمر التوريد بيخصم العهدة بـ`deduct()` من غير ما يسجّل
        // باتش على البند — `purchase_order_items.batch_id` مابيتكتبش في أي
        // مسار النهارده. الباتش الحقيقي متسجّل على **أمر التجهيز** اللي حمّل
        // بضاعة الأمر (`pick_orders.purchase_order_id` + `qty_received`) —
        // نفس الربط اللي `CustodySource` بيستخدمه. الكمية = اللي اتحمّل للأمر
        // من اللوط (للسحب: الحد الأعلى اللي ممكن يكون وصل للعميل).
        $viaPick = DB::table('pick_order_items as pi')
            ->join('pick_orders as pk', 'pk.id', '=', 'pi.pick_order_id')
            ->join('purchase_orders as o', 'o.id', '=', 'pk.purchase_order_id')
            ->whereIn('pi.batch_id', $twins)
            ->where('pi.qty_received', '>', 0)
            ->where('o.status', 'delivered')
            ->groupBy('o.id', 'o.number', 'o.client_id', 'o.delivered_at')
            ->select('o.id', 'o.number', 'o.client_id', 'o.delivered_at', DB::raw('SUM(pi.qty_received) AS qty'));
        $range?->apply($viaPick, 'o.delivered_at');
        $poRows = $viaPick->get()->keyBy('id');

        // ولو بند الأمر نفسه عليه باتش (بيانات قديمة أو مسار مستقبلي) — من غير تكرار نفس الأمر
        $direct = DB::table('purchase_order_items as i')
            ->join('purchase_orders as o', 'o.id', '=', 'i.purchase_order_id')
            ->whereIn('i.batch_id', $twins)
            ->where('o.status', 'delivered')
            ->groupBy('o.id', 'o.number', 'o.client_id', 'o.delivered_at')
            ->select('o.id', 'o.number', 'o.client_id', 'o.delivered_at', DB::raw('SUM(i.delivered_qty) AS qty'));
        $range?->apply($direct, 'o.delivered_at');
        foreach ($direct->get() as $r) {
            if (! $poRows->has($r->id)) {
                $poRows->put($r->id, $r);
            }
        }

        // العينات المجانية لعميل بعينه (`gift_handouts` — العميل اختياري)
        $gifts = DB::table('gift_handouts as g')
            ->whereIn('g.batch_id', $twins)
            ->whereNotNull('g.client_id')
            ->groupBy('g.client_id', DB::raw('DATE(g.created_at)'))
            ->select('g.client_id', DB::raw('DATE(g.created_at) AS day'), DB::raw('SUM(g.qty) AS qty'));
        $range?->apply($gifts, 'g.created_at');
        $giftRows = $gifts->get();

        $clients = Client::query()
            ->whereIn('id', $poRows->pluck('client_id')->concat($giftRows->pluck('client_id'))->unique())
            ->get()->keyBy('id');

        $fromOrders = $poRows->values()->map(fn ($r) => isset($clients[$r->client_id]) ? [
            'client' => $clients[$r->client_id],
            'qty' => (int) $r->qty,
            'via' => 'sales_order',
            'ref' => (string) $r->number,
            'date' => $r->delivered_at ? \Illuminate\Support\Carbon::parse($r->delivered_at) : null,
        ] : null)->filter();

        $fromGifts = $giftRows->map(fn ($r) => isset($clients[$r->client_id]) ? [
            'client' => $clients[$r->client_id],
            'qty' => (int) $r->qty,
            'via' => 'gift',
            'ref' => '—',
            'date' => \Illuminate\Support\Carbon::parse($r->day),
        ] : null)->filter();

        return $fromInvoices->values()->concat($fromOrders->values())->concat($fromGifts->values())
            ->filter(fn ($r) => $r['client'] !== null && $r['qty'] > 0)
            ->sortByDesc(fn ($r) => $r['date']?->timestamp ?? 0)
            ->values();
    }

    /**
     * اللي لسه في عربيات المناديب: عهدة مفتوحة، الباقي
     * (المحمّل − المباع − المرتجع − المحوّل، `CustodyItem::remaining`) لكل مندوب.
     *
     * @return Collection<int, array{rep: User, qty: int, date: \Illuminate\Support\Carbon|null}>
     */
    public static function inVans(Batch $fg): Collection
    {
        $items = CustodyItem::query()->whereIn('batch_id', self::twinIds($fg))
            ->whereHas('custody', fn ($q) => $q->where('status', 'open'))
            ->with('custody.user')->get();

        return $items->groupBy(fn (CustodyItem $i) => $i->custody->user_id)
            ->map(fn (Collection $rows) => [
                'rep' => $rows->first()->custody->user,
                // الباقي للبيع + العينات اللي لسه ماتوزّعتش (`remaining()` مابيعدّش الهدايا)
                'qty' => (int) $rows->sum(fn (CustodyItem $i) => max(0, $i->remaining()) + $i->giftLeft()),
                'date' => $rows->max(fn (CustodyItem $i) => $i->custody->date),
            ])
            ->filter(fn ($r) => $r['rep'] !== null && $r['qty'] > 0)
            ->sortByDesc('qty')
            ->values();
    }

    private static ?bool $invoiceStatus = null;

    private static function invoicesHaveStatus(): bool
    {
        return self::$invoiceStatus ??= Schema::hasColumn((new Invoice)->getTable(), 'status');
    }
}
