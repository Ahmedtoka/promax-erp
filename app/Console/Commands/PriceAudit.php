<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\ClientReturn;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Transaction;
use App\Services\ListHistory;
use App\Services\PriceListOverview;
use App\Services\Pricing;
use App\Services\Tax;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * ═══════════════════════════════════════════════════════════════
 * تدقيق الأسعار — كل مكان فيه سعر (طلب أحمد ١٣/١١/٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * «تمشي على السيستم كله حتة حتة وتتأكد إن كله ماشي على التسعير الجديد
 * ومربوط بقوايم أسعار صح». **قراية بس — مابيكتبش أي حاجة**، وبيشتغل على
 * اللايف:
 *
 *   php artisan prices:audit [--plan=../price_plan_2026-11-12.json] [--csv=../price_audit.csv]
 *
 * المناطق: العملاء والسلاسل والعقود (قايمة شغّالة، خصم، ضريبة، ومطابقة الخطة
 * لو اتبعتت) · بنود القوايم · سطور الفواتير وأوامر التوريد والمرتجعات (السعر
 * من القايمة الصح، والسطر = الكمية × الشامل بالظبط، والإجمالي = البنود) ·
 * التحصيل والرد · قيود المستندات · رصيد العميل = قيوده · عروض الأسعار والأونلاين
 * (معلومة). كل استثناء صف في CSV؛ «خطأ» = لازم يتصلّح، «معلومة» = متوقّع.
 */
class PriceAudit extends Command
{
    protected $signature = 'prices:audit
        {--plan= : ملف الخطة — يقارن قايمة وخصم كل عميل في الشيت}
        {--csv= : مسار نسخة إضافية من الـCSV}';

    protected $description = 'تدقيق قراية بس: كل سعر في السيستم من القايمة الصح والمستندات والقيود والأرصدة مطابقة';

    private const EPS = 0.005;

    private const P4 = 0.00005;

    /** @var list<array<string, mixed>> */
    private array $rows = [];

    /** @var array<string, array{0:int,1:int}> area.check => [checked, errors] */
    private array $count = [];

    /** @var array<int, true> قوايم اتفصلت ضريبتها (الكاش فان/بونجور): مستنداتها بتحتفظ باللي اتدفع */
    private array $splitLists = [];

    public function handle(): int
    {
        DB::beginTransaction(); // شبكة أمان: أي كتابة بالغلط بترجع
        try {
            $this->splitLists = PriceList::where(fn ($q) => $q->whereIn('code', ['CashVan', 'totalfranchise'])->orWhere('notes', 'like', '%[vat-split:done]%'))
                ->pluck('id')->flip()->map(fn () => true)->all();
            $this->clients();
            $this->lists();
            $this->documents();
            $this->money();
            $this->other();
        } finally {
            DB::rollBack();
        }

        $this->report();

        return self::SUCCESS;
    }

    // ═══════════════════════ العملاء والسلاسل والعقود ═══════════════════════

    private function clients(): void
    {
        $lists = PriceList::all()->keyBy('id');
        $plan = $this->planAssign();

        foreach (Client::where('status', 'active')->with(['group', 'contract'])->get() as $c) {
            $ref = $c->code.' '.$c->name;
            $l = $lists[$c->price_list_id] ?? null;
            $this->check('client', 'has_active_list', $l && $l->active, $ref, $c, 'قايمة شغّالة', $l ? $l->code.($l->active ? '' : ' (موقوفة)') : 'مفيش');
            $this->check('client', 'taxable', (bool) $c->taxable, $ref, $c, 'خاضع', 'معفى');
            $eff = Pricing::listRowFor($c);
            if ($l && $eff && $eff->id !== $l->id) {
                $this->note('client', 'contract_overrides_list', $ref, $c, $l->code, $eff->code, 'عقد ساري بيغلب قايمة العميل في التسعير');
            }
            if ($plan && isset($plan[$c->code])) {
                [$code, $disc] = $plan[$c->code];
                $this->check('client', 'matches_plan_list', $eff && $eff->code === $code, $ref, $c, $code, $eff?->code ?? '—');
                $this->check('client', 'matches_plan_discount', abs($c->effectiveDiscount() - $disc) < 0.0001, $ref, $c, $disc, $c->effectiveDiscount());
            }
        }

        foreach (ClientGroup::where('active', true)->withCount(['clients as live' => fn ($q) => $q->where('status', 'active')])->get() as $g) {
            if ($g->live === 0) {
                continue;
            }
            $l = $lists[$g->price_list_id] ?? null;
            // سلسلة من غير قايمة وكل فروعها الشغّالة على قوايم = معلومة (الفرع هو اللي بيتسعّر)
            $branchesOk = ! Client::where('group_id', $g->id)->where('status', 'active')
                ->where(fn ($q) => $q->whereNull('price_list_id')->orWhereNotIn('price_list_id', $lists->where('active', true)->keys()))->exists();
            if (! $l && $branchesOk) {
                $this->note('chain', 'has_active_list', $g->code.' '.$g->name, null, 'قايمة شغّالة', 'مفيش', 'كل فروعها الشغّالة على قوايم شغّالة');
            } else {
                $this->check('chain', 'has_active_list', $l && $l->active, $g->code.' '.$g->name, null, 'قايمة شغّالة', $l ? $l->code.($l->active ? '' : ' (موقوفة)') : 'مفيش');
            }
            $this->check('chain', 'taxable', $g->taxable === null || (bool) $g->taxable, $g->code.' '.$g->name, null, 'خاضع', 'معفى');
        }

        foreach (Contract::where('active', true)->get() as $ct) {
            if ($ct->isExpired() || ! $ct->price_list_id) {
                continue;
            }
            $l = $lists[$ct->price_list_id] ?? null;
            $this->check('contract', 'list_active', $l && $l->active, 'عقد #'.$ct->id.' '.$ct->chain, null, 'قايمة شغّالة', $l?->code ?? '—');
        }
    }

    /** @return array<string, array{0:string,1:float}>|null كود العميل ⇒ [القايمة، الخصم] */
    private function planAssign(): ?array
    {
        if (! ($p = $this->option('plan'))) {
            return null;
        }
        $plan = json_decode((string) @file_get_contents((string) $p), true);
        if (! is_array($plan)) {
            $this->error('ملف الخطة مش موجود أو مش JSON: '.$p);

            return null;
        }
        $out = [];
        foreach ($plan['assign'] ?? [] as $a) {
            foreach ($a['clients'] ?? [] as $code) {
                $out[$code] = [$a['list'], (float) $a['discount']];
            }
        }

        return $out;
    }

    // ═══════════════════════ القوايم ═══════════════════════

    private function lists(): void
    {
        $sellable = Product::sellable()->get();
        $active = PriceList::where('active', true)->get();
        $items = DB::table('price_list_items')->get()->groupBy('price_list_id')->map(fn ($g) => $g->keyBy('product_id'));
        $newP = ($n = $active->firstWhere('code', 'new')) ? ($items[$n->id] ?? collect())->map(fn ($r) => (float) $r->price)->all() : [];
        $oldP = ($o = PriceList::where('code', 'old')->first()) ? ($items[$o->id] ?? collect())->map(fn ($r) => (float) $r->price)->all() : [];

        foreach ($active as $l) {
            $map = $items[$l->id] ?? collect();
            foreach ($sellable as $p) {
                $price = (float) ($map[$p->id]->price ?? 0);
                $this->check('list', 'item_priced', $price > 0, $l->code.' · '.$p->code, null, '> 0', $price);
                if ($price > 0) {
                    $this->check('list', 'item_4dp', abs($price - round($price, 4)) < 1e-9, $l->code.' · '.$p->code, null, '4 أرقام', $price);
                }
            }
            $prices = $map->map(fn ($r) => (float) $r->price)->filter(fn ($v) => $v > 0)->all();
            $b = PriceListOverview::basis($l, $prices, $newP, $oldP);
            $this->check('list', 'not_incl', ! $b['incl_suspect'], $l->code, null, 'غير شامل', 'شكلها شامل (≈114% من المستهلك)');
        }
        foreach ($sellable as $p) {
            if (isset($newP[$p->id])) {
                $this->check('list', 'product_price_new_synced', abs((float) $p->price_new - $newP[$p->id]) < self::P4, $p->code, null, $newP[$p->id], (float) $p->price_new);
            }
            if (isset($oldP[$p->id])) {
                $this->check('list', 'product_price_old_synced', abs((float) $p->price_old - $oldP[$p->id]) < self::P4, $p->code, null, $oldP[$p->id], (float) $p->price_old);
            }
        }
    }

    // ═══════════════════════ المستندات ═══════════════════════

    private function documents(): void
    {
        foreach (Invoice::with(['items.product', 'client'])->get() as $inv) {
            $locked = in_array((string) $inv->eta_status, ['exported', 'submitted'], true);
            $this->docLines('invoice', $inv, $inv->items, $inv->client, $locked ? 'مرفوعة ضريبي — مقفولة' : null);
        }
        foreach (PurchaseOrder::with(['items.product', 'client'])->where('status', '!=', 'cancelled')->get() as $po) {
            $delivered = $po->status === 'delivered';
            $this->docLines('po', $po, $po->items, $po->client, null,
                $delivered ? 'اتسلّم — تعديل الأسعار من الشاشة بيسيبه عن قصد؛ إعادة تسعيره في المكان قرار' : '',
                $delivered ? ($po->delivered_at ?? $po->created_at) : null);
        }
        foreach (ClientReturn::with(['items.product'])->get() as $r) {
            $this->returnLines($r);
        }
    }

    /**
     * @param  \DateTimeInterface|null  $bookedAt  أمر اتسلّم: وقت تسجيل البيع. لو قبل آخر تغيير يدوي
     *                                            لقايمة العميل (قرار ٢٧) والسطر = سعر القايمة السابقة ⇒ معلومة
     */
    private function docLines(string $kind, $doc, $items, ?Client $client, ?string $infoReason, string $why = '', ?\DateTimeInterface $bookedAt = null): void
    {
        $ref = $doc->number;
        if (! $client) {
            $this->check($kind, 'has_client', false, $ref, null, 'عميل', 'مفيش');

            return;
        }
        $split = isset($this->splitLists[(int) $client->price_list_id]);
        $change = $bookedAt ? ListHistory::lastManualChange($client) : null;
        $before = $change && $bookedAt < $change['at'] ? ListHistory::asBefore($client, $change) : null;
        $net = $tax = 0.0;
        foreach ($items as $it) {
            if (! $it->product) {
                continue;
            }
            $q = Pricing::quote($client, $it->product);
            $ok = abs((float) $it->price - (float) $q['unit_price']) < self::P4;
            if (! $ok && $before && abs((float) $it->price - ($prev = (float) Pricing::quote($before, $it->product)['unit_price'])) < self::P4) {
                // (قرار ٢٧) اتسلّم قبل تغيير القايمة وبسعر القايمة السابقة بالظبط = تاريخ مقبول
                $this->note($kind, 'line_price_from_list', $ref.' · '.$it->product->code, $client, $q['unit_price'], (float) $it->price,
                    sprintf('اتسعّر على القايمة السابقة (%s) — اتسلّم %s قبل تغييرها %s بإيد %s (قرار ٢٧)',
                        PriceList::whereKey($change['from'])->value('code'), $bookedAt->format('Y-m-d H:i'), $change['at']->format('Y-m-d H:i'), $change['by']));
            } elseif ($ok || (! $split && ! $infoReason)) {
                $this->check($kind, 'line_price_from_list', $ok, $ref.' · '.$it->product->code, $client, $q['unit_price'], (float) $it->price, $why);
            } else {
                $this->note($kind, 'line_price_from_list', $ref.' · '.$it->product->code, $client, $q['unit_price'], (float) $it->price,
                    $infoReason ?? 'كاش فان/بونجور: اتفصلت ضريبتها بنفس المبلغ اللي اتدفع (قرار ٢٢)');
            }
            $calc = Tax::line((float) $it->price, (int) $it->qty, (float) $it->tax_rate);
            $this->check($kind, 'line_paper_exact', abs($calc['net'] - (float) $it->total) < self::EPS && abs($calc['tax'] - (float) $it->tax) < self::EPS,
                $ref.' · '.$it->product->code, $client, $calc['net'].' + '.$calc['tax'], $it->total.' + '.$it->tax);
            $this->check($kind, 'line_tax_rate', abs((float) $it->tax_rate - Tax::rate($client, $it->product)) < 0.0001,
                $ref.' · '.$it->product->code, $client, Tax::rate($client, $it->product), (float) $it->tax_rate);
            $net += (float) $it->total;
            $tax += (float) $it->tax;
        }
        $this->check($kind, 'total_equals_lines', abs(round($net + $tax, 2) - (float) $doc->grand_total) < 0.011, $ref, $client,
            round($net + $tax, 2), (float) $doc->grand_total);
    }

    private function returnLines(ClientReturn $r): void
    {
        $client = Client::find($r->client_id);
        $ref = $r->number;
        $net = $tax = 0.0;
        foreach ($r->items as $ri) {
            if (! $ri->product || ! $client) {
                continue;
            }
            $src = $ri->invoice_item_id ? InvoiceItem::find($ri->invoice_item_id) : null;
            $expected = $src ? (float) $src->price : Pricing::unitPrice($client, $ri->product);
            $ok = abs((float) $ri->price - $expected) < self::P4;
            if ($ok || ! isset($this->splitLists[(int) $client->price_list_id])) {
                $this->check('return', 'line_price', $ok, $ref.' · '.$ri->product->code, $client, $expected, (float) $ri->price);
            } else {
                $this->note('return', 'line_price', $ref.' · '.$ri->product->code, $client, $expected, (float) $ri->price, 'كاش فان/بونجور: فصل ضريبة');
            }
            $calc = Tax::line((float) $ri->price, (int) $ri->qty, (float) $ri->tax_rate);
            if (! isset($this->splitLists[(int) $client->price_list_id])) {
                $this->check('return', 'line_paper_exact', abs($calc['net'] - (float) $ri->total) < self::EPS && abs($calc['tax'] - (float) $ri->tax) < self::EPS,
                    $ref.' · '.$ri->product->code, $client, $calc['net'].' + '.$calc['tax'], $ri->total.' + '.$ri->tax);
            }
            $net += (float) $ri->total;
            $tax += (float) $ri->tax;
        }
        if ($r->items->isNotEmpty()) {
            $this->check('return', 'total_equals_lines', abs(round($net + $tax, 2) - (float) $r->grand_total) < 0.011, $ref, $client, round($net + $tax, 2), (float) $r->grand_total);
        }
    }

    // ═══════════════════════ التحصيل والقيود والأرصدة ═══════════════════════

    private function money(): void
    {
        $tx = DB::table('transactions')->whereIn('source_type', [Invoice::class, PurchaseOrder::class, ClientReturn::class])
            ->groupBy('source_type', 'source_id', 'kind')
            ->selectRaw('source_type, source_id, kind, SUM(debit) d, SUM(credit) c, COUNT(*) n')->get()
            ->groupBy(fn ($r) => $r->source_type.'#'.$r->source_id);
        $get = fn ($k, $kind) => ($tx[$k] ?? collect())->firstWhere('kind', $kind);
        $clients = Client::get(['id', 'code', 'name', 'balance'])->keyBy('id');

        foreach (Invoice::get(['id', 'number', 'client_id', 'payment', 'grand_total']) as $i) {
            $k = Invoice::class.'#'.$i->id;
            if ($get($k, 'consignment')) {
                continue;
            }
            $c = $clients[$i->client_id] ?? null;
            $sale = $get($k, 'sale');
            $this->check('ledger', 'invoice_sale_equals_total', $sale && abs((float) $sale->d - (float) $i->grand_total) < self::EPS, $i->number, $c, (float) $i->grand_total, $sale ? (float) $sale->d : 'مفيش قيد');
            $coll = $get($k, 'collection');
            if ($i->payment === 'cash') {
                $this->check('collection', 'cash_invoice_collected_equals_total', $coll && abs((float) $coll->c - (float) $i->grand_total) < self::EPS,
                    $i->number, $c, (float) $i->grand_total, $coll ? (float) $coll->c : 'مفيش تحصيل');
            } elseif ($coll) {
                $this->check('collection', 'credit_invoice_has_no_linked_collection', false, $i->number, $c, 'مفيش', (float) $coll->c);
            }
        }

        foreach (PurchaseOrder::where('status', 'delivered')->with('items')->get() as $po) {
            $k = PurchaseOrder::class.'#'.$po->id;
            $net = $tax = 0.0;
            foreach ($po->items as $it) {
                $dq = $it->delivered_qty === null ? (int) $it->qty : (int) $it->delivered_qty;
                if ($dq === (int) $it->qty) {
                    $net += (float) $it->total; $tax += (float) $it->tax;
                } else {
                    $p = Tax::line((float) $it->price, $dq, (float) $it->tax_rate);
                    $net += $p['net']; $tax += $p['tax'];
                }
            }
            $sale = $get($k, 'sale');
            $this->check('ledger', 'delivered_po_sale_equals_delivered', $sale && abs((float) $sale->d - round($net + $tax, 2)) < 0.011,
                $po->number, $clients[$po->client_id] ?? null, round($net + $tax, 2), $sale ? (float) $sale->d : 'مفيش قيد');
        }

        foreach (ClientReturn::get() as $r) {
            if ((float) $r->grand_total <= 0) {
                continue;
            }
            $k = ClientReturn::class.'#'.$r->id;
            $c = $clients[$r->client_id] ?? null;
            $credit = $get($k, 'return');
            $this->check('ledger', 'return_credit_equals_total', $credit && abs((float) $credit->c - (float) $r->grand_total) < self::EPS, $r->number, $c, (float) $r->grand_total, $credit ? (float) $credit->c : 'مفيش قيد');
            if ($r->policy === ClientReturn::POLICY_CASH && $r->refund_transaction_id) {
                $refund = (float) Transaction::whereKey($r->refund_transaction_id)->value('debit');
                $this->check('collection', 'cash_return_refund_equals_total', abs($refund - (float) $r->grand_total) < self::EPS, $r->number, $c, (float) $r->grand_total, $refund);
            }
        }

        $sums = DB::table('transactions')->groupBy('client_id')->selectRaw('client_id, COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0) s')->pluck('s', 'client_id');
        foreach ($clients as $c) {
            $this->check('balance', 'balance_equals_ledger', abs((float) ($sums[$c->id] ?? 0) - (float) $c->balance) < 0.011, $c->code.' '.$c->name, $c, round((float) ($sums[$c->id] ?? 0), 2), (float) $c->balance);
        }
    }

    // ═══════════════════════ معلومة ═══════════════════════

    private function other(): void
    {
        $q = DB::table('quotations')->count();
        $incl = DB::table('quotations')->where('tax_inclusive', true)->count();
        if ($incl > 0) {
            $this->note('quotation', 'typed_price_incl', $incl.' من '.$q.' عرض', null, 'غير شامل', 'شامل',
                'عرض السعر بيتكتب بإيد المستخدم (قابل للتفاوض) ومتعلّم شامل/غير شامل — مابيترحّلش ولا ليه قيود');
        }
        $online = DB::table('online_orders')->count();
        if ($online > 0) {
            $this->note('online', 'shopify_retail_price', $online.' طلب', null, '—', '—', 'أونلاين = سعر شوبيفاي للمستهلك (شامل) — مش من قوايم العملاء');
        }
    }

    // ═══════════════════════ أدوات ═══════════════════════

    private function check(string $area, string $check, bool $ok, string $ref, $client, $expected, $actual, string $why = ''): void
    {
        $key = $area.'.'.$check;
        $this->count[$key] ??= [0, 0, 0];
        $this->count[$key][0]++;
        if (! $ok) {
            $this->count[$key][1]++;
            $this->rows[] = ['severity' => 'error', 'area' => $area, 'check' => $check, 'ref' => $ref,
                'client' => $client ? $client->code.' '.$client->name : '', 'expected' => $expected, 'actual' => $actual, 'note' => $why];
        }
    }

    private function note(string $area, string $check, string $ref, $client, $expected, $actual, string $note): void
    {
        $key = $area.'.'.$check;
        $this->count[$key] ??= [0, 0, 0];
        $this->count[$key][0]++;
        $this->count[$key][2]++;
        $this->rows[] = ['severity' => 'info', 'area' => $area, 'check' => $check, 'ref' => $ref,
            'client' => $client ? $client->code.' '.$client->name : '', 'expected' => $expected, 'actual' => $actual, 'note' => $note];
    }

    private function report(): void
    {
        $this->info2('══ تدقيق الأسعار · DB: '.DB::connection()->getDatabaseName().' · قراية بس ══');
        ksort($this->count);
        $this->table(['الفحص', 'اتفحص', 'أخطاء', 'معلومة'], collect($this->count)->map(fn ($v, $k) => [$k, $v[0], $v[1] ?: '✓', $v[2] ?: ''])->values()->all());
        $err = collect($this->count)->sum(fn ($v) => $v[1]);
        $inf = collect($this->count)->sum(fn ($v) => $v[2]);

        $path = 'price-audit/audit-'.now()->format('Ymd-His').'.csv';
        $fh = fopen('php://temp', 'w+');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, ['severity', 'area', 'check', 'ref', 'client', 'expected', 'actual', 'note']);
        foreach ($this->rows as $r) {
            fputcsv($fh, array_map(fn ($v) => is_float($v) ? round($v, 4) : $v, array_values($r)));
        }
        rewind($fh);
        $body = stream_get_contents($fh);
        Storage::put($path, $body);
        if ($extra = $this->option('csv')) {
            file_put_contents((string) $extra, $body);
        }
        $this->line(sprintf('أخطاء: %d · معلومة: %d · CSV: storage/app/%s%s', $err, $inf, $path, $this->option('csv') ? ' (+ '.$this->option('csv').')' : ''));
        $err === 0 ? $this->info2('✓ مفيش أخطاء.') : $this->warn('فيه أخطاء — شوف الـCSV.');
    }

    private function info2(string $s): void
    {
        $this->output->writeln('<info>'.$s.'</info>');
    }
}
