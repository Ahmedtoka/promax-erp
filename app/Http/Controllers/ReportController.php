<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientReturn;
use App\Models\GiftHandout;
use App\Models\Invoice;
use App\Models\PriceList;
use App\Models\PurchaseOrder;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * مركز التقارير (٢١ أغسطس ٢٠٢٦) — «عاوز أعرف كل معلومة في السيستم»
 * ═══════════════════════════════════════════════════════════════
 *
 * ١٤ تقرير على محرك واحد: كل تقرير بيرجّع نفس الشكل (KPIs + أعمدة
 * + صفوف + إجماليات + فلاتر) والفيو المشترك بيرسمه — ونفس الداتا
 * بالظبط بتتصدّر CSV بضغطة (بتفتح في إكسيل بالعربي سليم بالـBOM).
 *
 * ⚠️ **عقيدة الأرقام**: المبيعات من `invoices` (`total` صافي /
 * `tax_total` / `grand_total` الشامل)، التحصيل قيد دائن نوعه
 * `collection`، المرتجعات من مستنداتها، وقيمة العهدة من
 * `CustodyValue` (القايمة الافتراضية). مفيش رقم متألف هنا.
 *
 * ⚠️ سقف الصفوف التفصيلية 2000 — التقرير شاشة مراجعة مش أرشيف،
 * والتصدير بياخد نفس السقف. التضييق بالفلاتر.
 */
class ReportController extends Controller
{
    /** التقارير المتاحة: المفتاح ⇐ الأيقونة */
    public const REPORTS = [
        'sales_docs' => '🧾',
        'sales_by_rep' => '🧑‍💼',
        'sales_by_client' => '👥',
        'sales_by_product' => '📦',
        'sales_by_channel' => '🎯',
        'collections' => '💵',
        'returns_docs' => '↩️',
        'debts' => '⏳',
        'reps_overview' => '📊',
        'visits_log' => '🚪',
        'gifts_log' => '🎁',
        'pos_status' => '🚚',
        'inactive_clients' => '😴',
        'new_clients' => '✨',
    ];

    private const MAX_ROWS = 2000;

    public function index()
    {
        return view('erp.report_hub', ['reports' => self::REPORTS]);
    }

    public function show(Request $request, string $key)
    {
        abort_unless(isset(self::REPORTS[$key]), 404);

        $method = 'r'.str_replace(' ', '', ucwords(str_replace('_', ' ', $key)));
        $data = $this->{$method}($request);

        $data += [
            'key' => $key,
            'icon' => self::REPORTS[$key],
            'title' => __('rpt.'.$key),
            'repOptions' => User::whereIn('role', User::FIELD_WORK_ROLES)
                ->where('active', true)->orderBy('name')->get(['id', 'name', 'name_en']),
            'channelOptions' => \App\Models\Channel::orderBy('id')->get(),
        ];

        // ═══ تصدير CSV — نفس الصفوف بالظبط، بالـBOM عشان إكسيل عربي ═══
        if ($request->boolean('export')) {
            return $this->csv($data);
        }

        return view('erp.report', $data);
    }

    // ═══════════════════ أدوات مشتركة ═══════════════════

    /** الفترة — الافتراضي من أول الشهر للنهاردة */
    private function range(Request $r): array
    {
        try {
            $from = $r->filled('from') ? Carbon::parse($r->input('from')) : today()->startOfMonth();
        } catch (\Throwable) {
            $from = today()->startOfMonth();
        }

        try {
            $to = $r->filled('to') ? Carbon::parse($r->input('to')) : today();
        } catch (\Throwable) {
            $to = today();
        }

        return [$from->startOfDay(), $to->endOfDay()];
    }

    private function m(float|int|null $n): string
    {
        return number_format((float) $n, 2);
    }

    private function f0(float|int|null $n): string
    {
        return number_format((float) $n);
    }

    private function csv(array $d)
    {
        $name = $d['key'].'-'.now()->format('Y-m-d-Hi').'.csv';

        return response()->streamDownload(function () use ($d) {
            $out = fopen('php://output', 'w');
            // BOM — من غيره إكسيل بيفتح العربي طلاسم
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_map(fn ($c) => $c[0], $d['columns']));

            foreach ($d['rows'] as $row) {
                fputcsv($out, $row);
            }

            if (! empty($d['totals'])) {
                fputcsv($out, $d['totals']);
            }

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** فلترة نص البحث على أعمدة معيّنة في كويري */
    private function like($q, Request $r, array $cols): void
    {
        if (! $r->filled('q')) {
            return;
        }

        $s = '%'.$r->string('q')->trim().'%';
        $q->where(function ($w) use ($cols, $s) {
            foreach ($cols as $c) {
                str_contains($c, '.')
                    ? $w->orWhereHas(explode('.', $c)[0],
                        fn ($h) => $h->where(explode('.', $c)[1], 'like', $s))
                    : $w->orWhere($c, 'like', $s);
            }
        });
    }

    // ═══════════════════ ١. الفواتير تفصيلي ═══════════════════

    private function rSalesDocs(Request $r): array
    {
        [$a, $b] = $this->range($r);

        $q = Invoice::with(['client.group', 'user'])
            ->whereBetween('created_at', [$a, $b])
            ->when($r->filled('user_id'), fn ($w) => $w->where('user_id', $r->integer('user_id')))
            ->when($r->filled('payment'), fn ($w) => $w->where('payment', $r->input('payment')));

        $this->like($q, $r, ['number', 'paper_ref', 'client.name']);

        $all = (clone $q)->get(['id', 'payment', 'total', 'tax_total', 'grand_total']);
        $rows = $q->latest()->take(self::MAX_ROWS)->get();

        return [
            'filters' => ['range', 'rep', 'payment', 'q'],
            'kpis' => [
                [__('rpt.k_count'), $this->f0($all->count()), ''],
                [__('rpt.k_net'), $this->m($all->sum('total')), ''],
                [__('rpt.k_tax'), $this->m($all->sum('tax_total')), ''],
                [__('rpt.k_grand'), $this->m($all->sum('grand_total')), 'pos'],
                [__('rpt.k_cash'), $this->m($all->where('payment', 'cash')->sum('grand_total')), ''],
                [__('rpt.k_credit'), $this->m($all->where('payment', 'credit')->sum('grand_total')), 'mid'],
            ],
            'columns' => [
                [__('rpt.c_date')], [__('rpt.c_number')], [__('rpt.c_paper')],
                [__('rpt.c_rep')], [__('rpt.c_client')], [__('rpt.c_payment')],
                [__('rpt.k_net'), 'num'], [__('rpt.k_tax'), 'num'], [__('rpt.k_grand'), 'num'],
            ],
            'rows' => $rows->map(fn ($i) => [
                $i->created_at->format('Y-m-d'),
                $i->number,
                $i->paper_ref ?? '—',
                $i->user?->displayName() ?? '—',
                $i->client?->fullName() ?? '—',
                $i->payment === 'cash' ? __('rpt.cash') : __('rpt.credit'),
                $this->m($i->total),
                $this->m($i->tax_total),
                $this->m($i->grand_total),
            ])->all(),
            'totals' => ['', '', '', '', '', __('common.total'),
                $this->m($all->sum('total')), $this->m($all->sum('tax_total')), $this->m($all->sum('grand_total'))],
        ];
    }

    // ═══════════════════ ٢. المبيعات بالمندوب ═══════════════════

    private function rSalesByRep(Request $r): array
    {
        [$a, $b] = $this->range($r);

        $reps = User::whereIn('role', User::FIELD_WORK_ROLES)->where('active', true)
            ->orderBy('name')->get();

        $inv = Invoice::whereBetween('created_at', [$a, $b])
            ->selectRaw('user_id, COUNT(*) c, SUM(total) net, SUM(tax_total) tax, SUM(grand_total) g,
                SUM(CASE WHEN payment = "cash" THEN grand_total ELSE 0 END) cash_g')
            ->groupBy('user_id')->get()->keyBy('user_id');

        $rets = ClientReturn::whereBetween('created_at', [$a, $b])
            ->selectRaw('user_id, COUNT(*) c, SUM(grand_total) g')
            ->groupBy('user_id')->get()->keyBy('user_id');

        $gifts = GiftHandout::whereBetween('created_at', [$a, $b])
            ->selectRaw('user_id, SUM(qty) q')->groupBy('user_id')->pluck('q', 'user_id');

        $visits = Visit::whereBetween('created_at', [$a, $b])
            ->selectRaw('user_id, COUNT(*) c')->groupBy('user_id')->pluck('c', 'user_id');

        $rows = [];
        $T = ['c' => 0, 'net' => 0, 'tax' => 0, 'g' => 0, 'cash' => 0, 'ret' => 0, 'gift' => 0, 'vis' => 0];

        foreach ($reps as $rep) {
            $i = $inv->get($rep->id);
            $rt = $rets->get($rep->id);

            if ($i === null && $rt === null && ! $visits->has($rep->id)) {
                continue;
            }

            $rows[] = [
                $rep->displayName(),
                $this->f0($i->c ?? 0),
                $this->m($i->net ?? 0),
                $this->m($i->tax ?? 0),
                $this->m($i->g ?? 0),
                $this->m($i->cash_g ?? 0),
                $this->m(($i->g ?? 0) - ($i->cash_g ?? 0)),
                $this->m($rt->g ?? 0),
                $this->f0($gifts[$rep->id] ?? 0),
                $this->f0($visits[$rep->id] ?? 0),
            ];

            $T['c'] += $i->c ?? 0; $T['net'] += $i->net ?? 0; $T['tax'] += $i->tax ?? 0;
            $T['g'] += $i->g ?? 0; $T['cash'] += $i->cash_g ?? 0;
            $T['ret'] += $rt->g ?? 0; $T['gift'] += $gifts[$rep->id] ?? 0;
            $T['vis'] += $visits[$rep->id] ?? 0;
        }

        return [
            'filters' => ['range'],
            'kpis' => [
                [__('rpt.k_reps'), $this->f0(count($rows)), ''],
                [__('rpt.k_count'), $this->f0($T['c']), ''],
                [__('rpt.k_grand'), $this->m($T['g']), 'pos'],
                [__('rpt.k_returns'), $this->m($T['ret']), 'neg'],
                [__('rpt.k_visits'), $this->f0($T['vis']), ''],
            ],
            'columns' => [
                [__('rpt.c_rep')], [__('rpt.k_count'), 'num'], [__('rpt.k_net'), 'num'],
                [__('rpt.k_tax'), 'num'], [__('rpt.k_grand'), 'num'], [__('rpt.k_cash'), 'num'],
                [__('rpt.k_credit'), 'num'], [__('rpt.k_returns'), 'num'],
                [__('rpt.k_gifts'), 'num'], [__('rpt.k_visits'), 'num'],
            ],
            'rows' => $rows,
            'totals' => [__('common.total'), $this->f0($T['c']), $this->m($T['net']),
                $this->m($T['tax']), $this->m($T['g']), $this->m($T['cash']),
                $this->m($T['g'] - $T['cash']), $this->m($T['ret']),
                $this->f0($T['gift']), $this->f0($T['vis'])],
        ];
    }

    // ═══════════════════ ٣. المبيعات بالعميل ═══════════════════

    private function rSalesByClient(Request $r): array
    {
        [$a, $b] = $this->range($r);

        $inv = Invoice::whereBetween('created_at', [$a, $b])
            ->when($r->filled('user_id'), fn ($w) => $w->where('user_id', $r->integer('user_id')))
            ->selectRaw('client_id, COUNT(*) c, SUM(grand_total) g')
            ->groupBy('client_id')->get()->keyBy('client_id');

        $rets = ClientReturn::whereBetween('created_at', [$a, $b])
            ->selectRaw('client_id, SUM(grand_total) g')->groupBy('client_id')->pluck('g', 'client_id');

        $colls = Transaction::where('kind', 'collection')
            ->whereBetween('created_at', [$a, $b])
            ->selectRaw('client_id, SUM(credit) g')->groupBy('client_id')->pluck('g', 'client_id');

        $clients = Client::with(['group', 'channel'])
            ->whereIn('id', $inv->keys()->merge($rets->keys())->merge($colls->keys())->unique())
            ->when($r->filled('channel_id'), fn ($w) => $w->where('channel_id', $r->integer('channel_id')))
            ->get()
            ->sortByDesc(fn ($c) => (float) ($inv->get($c->id)->g ?? 0))
            ->take(self::MAX_ROWS);

        if ($r->filled('q')) {
            $s = mb_strtolower($r->string('q')->trim());
            $clients = $clients->filter(fn ($c) => str_contains(mb_strtolower($c->fullName().' '.$c->name_en), $s));
        }

        $tg = 0; $tr = 0; $tc = 0; $tb = 0;

        $rows = $clients->map(function ($c) use ($inv, $rets, $colls, &$tg, &$tr, &$tc, &$tb) {
            $g = (float) ($inv->get($c->id)->g ?? 0);
            $tg += $g; $tr += (float) ($rets[$c->id] ?? 0);
            $tc += (float) ($colls[$c->id] ?? 0); $tb += (float) $c->balance;

            return [
                $c->fullName(),
                $c->channel?->displayName() ?? '—',
                $this->f0($inv->get($c->id)->c ?? 0),
                $this->m($g),
                $this->m($rets[$c->id] ?? 0),
                $this->m($colls[$c->id] ?? 0),
                $this->m($c->balance),
            ];
        })->values()->all();

        return [
            'filters' => ['range', 'rep', 'channel', 'q'],
            'kpis' => [
                [__('rpt.k_clients'), $this->f0(count($rows)), ''],
                [__('rpt.k_grand'), $this->m($tg), 'pos'],
                [__('rpt.k_returns'), $this->m($tr), 'neg'],
                [__('rpt.k_collected'), $this->m($tc), ''],
                [__('rpt.k_balance'), $this->m($tb), 'mid'],
            ],
            'columns' => [
                [__('rpt.c_client')], [__('rpt.c_channel')], [__('rpt.k_count'), 'num'],
                [__('rpt.k_grand'), 'num'], [__('rpt.k_returns'), 'num'],
                [__('rpt.k_collected'), 'num'], [__('rpt.k_balance'), 'num'],
            ],
            'rows' => $rows,
            'totals' => [__('common.total'), '', '', $this->m($tg), $this->m($tr), $this->m($tc), $this->m($tb)],
        ];
    }

    // ═══════════════════ ٤. المبيعات بالصنف ═══════════════════

    private function rSalesByProduct(Request $r): array
    {
        [$a, $b] = $this->range($r);
        $seeCost = $r->user()?->isAdmin() ?? false;

        $lines = DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->whereBetween('invoices.created_at', [$a, $b])
            ->when($r->filled('user_id'), fn ($w) => $w->where('invoices.user_id', $r->integer('user_id')))
            ->selectRaw('invoice_items.product_id, SUM(invoice_items.qty) q,
                SUM(invoice_items.total) net, SUM(invoice_items.tax) tax,
                SUM(invoice_items.qty * invoice_items.unit_cost) cost')
            ->groupBy('invoice_items.product_id')
            ->get()->keyBy('product_id');

        $products = \App\Models\Product::whereIn('id', $lines->keys())->orderBy('name')->get();

        if ($r->filled('q')) {
            $s = mb_strtolower($r->string('q')->trim());
            $products = $products->filter(fn ($p) => str_contains(
                mb_strtolower($p->displayName().' '.$p->code), $s));
        }

        $tq = 0; $tn = 0; $tt = 0; $tc = 0;
        $rows = [];

        foreach ($products->sortByDesc(fn ($p) => (float) $lines[$p->id]->net) as $p) {
            $l = $lines[$p->id];
            $tq += $l->q; $tn += $l->net; $tt += $l->tax; $tc += $l->cost;

            $row = [
                $p->code, $p->displayName(), $this->f0($l->q),
                $this->m($l->net), $this->m($l->tax), $this->m($l->net + $l->tax),
            ];

            if ($seeCost) {
                $row[] = $this->m($l->cost);
                $row[] = $this->m($l->net - $l->cost);
            }

            $rows[] = $row;
        }

        $columns = [
            [__('common.code')], [__('rpt.c_product')], [__('rpt.k_qty'), 'num'],
            [__('rpt.k_net'), 'num'], [__('rpt.k_tax'), 'num'], [__('rpt.k_grand'), 'num'],
        ];
        $totals = [__('common.total'), '', $this->f0($tq), $this->m($tn), $this->m($tt), $this->m($tn + $tt)];

        if ($seeCost) {
            $columns[] = [__('rpt.k_cost'), 'num'];
            $columns[] = [__('rpt.k_profit'), 'num'];
            $totals[] = $this->m($tc);
            $totals[] = $this->m($tn - $tc);
        }

        return [
            'filters' => ['range', 'rep', 'q'],
            'kpis' => array_filter([
                [__('rpt.k_products'), $this->f0(count($rows)), ''],
                [__('rpt.k_qty'), $this->f0($tq), ''],
                [__('rpt.k_grand'), $this->m($tn + $tt), 'pos'],
                $seeCost ? [__('rpt.k_profit'), $this->m($tn - $tc), 'mid'] : null,
            ]),
            'columns' => $columns,
            'rows' => $rows,
            'totals' => $totals,
        ];
    }

    // ═══════════════════ ٥. المبيعات بالقناة ═══════════════════

    private function rSalesByChannel(Request $r): array
    {
        [$a, $b] = $this->range($r);

        $agg = Invoice::join('clients', 'clients.id', '=', 'invoices.client_id')
            ->whereBetween('invoices.created_at', [$a, $b])
            ->selectRaw('clients.channel_id ch, COUNT(*) c, SUM(invoices.grand_total) g')
            ->groupBy('clients.channel_id')->get();

        $total = (float) $agg->sum('g') ?: 1;
        $channels = \App\Models\Channel::all()->keyBy('id');

        $rows = $agg->sortByDesc('g')->map(fn ($x) => [
            $channels->get($x->ch)?->displayName() ?? '—',
            $this->f0($x->c),
            $this->m($x->g),
            number_format($x->g / $total * 100, 1).'%',
        ])->values()->all();

        return [
            'filters' => ['range'],
            'kpis' => [
                [__('rpt.k_count'), $this->f0($agg->sum('c')), ''],
                [__('rpt.k_grand'), $this->m($agg->sum('g')), 'pos'],
            ],
            'columns' => [
                [__('rpt.c_channel')], [__('rpt.k_count'), 'num'],
                [__('rpt.k_grand'), 'num'], [__('rpt.k_share'), 'num'],
            ],
            'rows' => $rows,
            'totals' => [__('common.total'), $this->f0($agg->sum('c')), $this->m($agg->sum('g')), '100%'],
        ];
    }

    // ═══════════════════ ٦. التحصيلات ═══════════════════

    private function rCollections(Request $r): array
    {
        [$a, $b] = $this->range($r);

        $q = Transaction::with('client.group')
            ->where('kind', 'collection')
            ->whereBetween('created_at', [$a, $b]);

        $this->like($q, $r, ['memo', 'reference', 'client.name']);

        $all = (clone $q)->get(['id', 'credit', 'method', 'source_type']);
        $rows = $q->latest()->take(self::MAX_ROWS)->get();

        $byMethod = fn ($m) => $this->m($all->where('method', $m)->sum('credit'));

        // ═══ المصدر (٢٦/٨ — «مش عارف أفرق الكاش من الميداني»):
        // نفس تقسيمة بوكس الداشبورد بالظبط — كاش الفواتير (قيد
        // أوتوماتيك مع فاتورة الكاش) · ميداني (من زيارة) · توريدات
        // (تسليم PO لعميل كاش) · مكتب (مسجّل يدوي من الـERP) ═══
        $srcKey = fn ($t) => match ($t->source_type) {
            \App\Models\Invoice::class => 'src_invoice',
            \App\Models\Visit::class => 'src_visit',
            \App\Models\PurchaseOrder::class => 'src_po',
            default => 'src_office',
        };
        $bySrc = fn ($cls) => $this->m($all->where('source_type', $cls)->sum('credit'));

        return [
            'filters' => ['range', 'q'],
            'kpis' => [
                [__('rpt.k_count'), $this->f0($all->count()), ''],
                [__('rpt.k_collected'), $this->m($all->sum('credit')), 'pos'],
                // تقسيمة المصدر — لازم تطابق بوكس التحصيل في الداشبورد
                [__('rpt.src_invoice'), $bySrc(\App\Models\Invoice::class), ''],
                [__('rpt.src_visit'), $bySrc(\App\Models\Visit::class), ''],
                [__('rpt.src_po'), $bySrc(\App\Models\PurchaseOrder::class), ''],
                // تقسيمة الطريقة — للمطابقة المحاسبية
                [__('rpt.m_cash'), $byMethod('cash'), ''],
                [__('rpt.m_card'), $byMethod('card'), ''],
                [__('rpt.m_cheque'), $byMethod('cheque'), ''],
                [__('rpt.m_transfer'), $byMethod('transfer'), ''],
            ],
            'columns' => [
                [__('rpt.c_date')], [__('rpt.c_client')], [__('rpt.c_source')], [__('rpt.c_memo')],
                [__('rpt.c_method')], [__('rpt.c_ref')], [__('rpt.k_amount'), 'num'],
            ],
            'rows' => $rows->map(fn ($t) => [
                $t->date instanceof \DateTimeInterface ? $t->date->format('Y-m-d') : (string) $t->date,
                $t->client?->fullName() ?? '—',
                __('rpt.'.$srcKey($t)),
                (string) $t->memo,
                $t->method ? __('rpt.m_'.$t->method) : __('rpt.m_with_invoice'),
                $t->reference ?? '—',
                $this->m($t->credit),
            ])->all(),
            'totals' => ['', '', '', '', '', __('common.total'), $this->m($all->sum('credit'))],
        ];
    }

    // ═══════════════════ ٧. المرتجعات ═══════════════════

    private function rReturnsDocs(Request $r): array
    {
        [$a, $b] = $this->range($r);

        $q = ClientReturn::with(['client.group', 'user'])
            ->whereBetween('created_at', [$a, $b])
            ->when($r->filled('user_id'), fn ($w) => $w->where('user_id', $r->integer('user_id')));

        $this->like($q, $r, ['number', 'client.name']);

        $all = (clone $q)->get(['id', 'grand_total', 'good_units', 'damaged_units']);
        $rows = $q->latest()->take(self::MAX_ROWS)->get();

        return [
            'filters' => ['range', 'rep', 'q'],
            'kpis' => [
                [__('rpt.k_count'), $this->f0($all->count()), ''],
                [__('rpt.k_returns'), $this->m($all->sum('grand_total')), 'neg'],
                [__('rpt.k_good'), $this->f0($all->sum('good_units')), ''],
                [__('rpt.k_damaged'), $this->f0($all->sum('damaged_units')), 'neg'],
            ],
            'columns' => [
                [__('rpt.c_date')], [__('rpt.c_number')], [__('rpt.c_client')],
                [__('rpt.c_rep')], [__('rpt.c_policy')],
                [__('rpt.k_good'), 'num'], [__('rpt.k_damaged'), 'num'], [__('rpt.k_amount'), 'num'],
            ],
            'rows' => $rows->map(fn ($d) => [
                $d->created_at->format('Y-m-d'),
                $d->number,
                $d->client?->fullName() ?? '—',
                $d->user?->displayName() ?? '—',
                $d->policyLabel(),
                $this->f0($d->good_units),
                $this->f0($d->damaged_units),
                $this->m($d->grand_total),
            ])->all(),
            'totals' => ['', '', '', '', __('common.total'),
                $this->f0($all->sum('good_units')), $this->f0($all->sum('damaged_units')),
                $this->m($all->sum('grand_total'))],
        ];
    }

    // ═══════════════════ ٨. المديونيات ═══════════════════

    private function rDebts(Request $r): array
    {
        $clients = Client::with(['group', 'channel', 'zone', 'rep'])
            ->where('status', 'active')
            ->where('balance', '>', 0)
            ->when($r->filled('channel_id'), fn ($w) => $w->where('channel_id', $r->integer('channel_id')))
            ->orderByDesc('balance')
            ->take(self::MAX_ROWS)
            ->get();

        if ($r->filled('q')) {
            $s = mb_strtolower($r->string('q')->trim());
            $clients = $clients->filter(fn ($c) => str_contains(mb_strtolower($c->fullName().' '.$c->name_en), $s));
        }

        // آخر تحصيل لكل عميل — كويري مجمّع واحد
        $lastColl = Transaction::where('kind', 'collection')
            ->whereIn('client_id', $clients->pluck('id'))
            ->selectRaw('client_id, MAX(created_at) t')
            ->groupBy('client_id')->pluck('t', 'client_id');

        $rows = $clients->map(function ($c) use ($lastColl) {
            $t = $lastColl->get($c->id);

            return [
                $c->fullName(),
                $c->channel?->displayName() ?? '—',
                $c->zone?->displayName() ?? '—',
                $c->rep?->displayName() ?? '—',
                $this->m($c->balance),
                $t ? Carbon::parse($t)->format('Y-m-d') : '—',
                $t ? $this->f0(Carbon::parse($t)->diffInDays(now())) : '—',
            ];
        })->values()->all();

        return [
            'filters' => ['channel', 'q'],
            'kpis' => [
                [__('rpt.k_clients'), $this->f0($clients->count()), ''],
                [__('rpt.k_balance'), $this->m($clients->sum('balance')), 'neg'],
                [__('rpt.k_max_debt'), $this->m($clients->max('balance')), ''],
                [__('rpt.k_avg_debt'), $this->m($clients->avg('balance')), ''],
            ],
            'columns' => [
                [__('rpt.c_client')], [__('rpt.c_channel')], [__('rpt.c_zone')],
                [__('rpt.c_rep')], [__('rpt.k_balance'), 'num'],
                [__('rpt.c_last_coll')], [__('rpt.c_days'), 'num'],
            ],
            'rows' => $rows,
            'totals' => [__('common.total'), '', '', '', $this->m($clients->sum('balance')), '', ''],
        ];
    }

    // ═══════════════════ ٩. المناديب الشامل ═══════════════════

    private function rRepsOverview(Request $r): array
    {
        [$a, $b] = $this->range($r);

        $reps = User::whereIn('role', User::FIELD_WORK_ROLES)->where('active', true)
            ->orderBy('name')->get();

        $inv = Invoice::whereBetween('created_at', [$a, $b])
            ->selectRaw('user_id, COUNT(*) c, SUM(grand_total) g,
                SUM(CASE WHEN payment = "cash" THEN grand_total ELSE 0 END) cash_g')
            ->groupBy('user_id')->get()->keyBy('user_id');

        // التحصيل الميداني — قيوده مصدرها الزيارة، والزيارة ليها مندوب
        // ⚠️ `transactions.created_at` مؤهّلة — الجوين مع visits خلّى
        // العمود ambiguous ورمى 500 (بلاغ ٢١/٨)
        $fieldColl = Transaction::where('kind', 'collection')
            ->where('source_type', Visit::class)
            ->whereBetween('transactions.created_at', [$a, $b])
            ->join('visits', 'visits.id', '=', 'transactions.source_id')
            ->selectRaw('visits.user_id uid, SUM(transactions.credit) g')
            ->groupBy('visits.user_id')->pluck('g', 'uid');

        $rets = ClientReturn::whereBetween('created_at', [$a, $b])
            ->selectRaw('user_id, SUM(grand_total) g')->groupBy('user_id')->pluck('g', 'user_id');

        $gifts = GiftHandout::whereBetween('created_at', [$a, $b])
            ->selectRaw('user_id, SUM(qty) q')->groupBy('user_id')->pluck('q', 'user_id');

        $vis = Visit::whereBetween('created_at', [$a, $b])
            ->selectRaw('user_id, COUNT(*) c, COUNT(DISTINCT client_id) cl')
            ->groupBy('user_id')->get()->keyBy('user_id');

        $rows = [];

        foreach ($reps as $rep) {
            $i = $inv->get($rep->id);
            $v = $vis->get($rep->id);

            // العهدة الحالية — الوحدات والقيمة بسعر المستهلك الافتراضي
            $custody = $rep->currentCustody();
            $units = 0;
            $value = 0.0;

            if ($custody !== null) {
                $custody->loadMissing('items.product');
                foreach ($custody->items as $it) {
                    $units += $it->remaining();
                }
                $totals = \App\Support\CustodyValue::remainingTotals($custody);
                $value = (float) (reset($totals)['total'] ?? 0);
            }

            // الكاش المفروض معاه = فواتير كاش + تحصيل ميداني
            $cash = (float) ($i->cash_g ?? 0) + (float) ($fieldColl[$rep->id] ?? 0);

            $rows[] = [
                $rep->displayName(),
                $rep->roleLabel(),
                $this->f0($i->c ?? 0),
                $this->m($i->g ?? 0),
                $this->m($fieldColl[$rep->id] ?? 0),
                $this->m($cash),
                $this->m($rets[$rep->id] ?? 0),
                $this->f0($gifts[$rep->id] ?? 0),
                $this->f0($v->c ?? 0),
                $this->f0($v->cl ?? 0),
                $this->f0($units),
                $this->m($value),
            ];
        }

        return [
            'filters' => ['range'],
            'kpis' => [
                [__('rpt.k_reps'), $this->f0($reps->count()), ''],
                [__('rpt.k_grand'), $this->m($inv->sum('g')), 'pos'],
                [__('rpt.k_field_coll'), $this->m($fieldColl->sum()), ''],
                [__('rpt.k_custody_val'), $this->m(collect($rows)->sum(fn ($x) => (float) str_replace(',', '', $x[11]))), 'mid'],
            ],
            'columns' => [
                [__('rpt.c_rep')], [__('rpt.c_role')], [__('rpt.k_count'), 'num'],
                [__('rpt.k_grand'), 'num'], [__('rpt.k_field_coll'), 'num'],
                [__('rpt.k_cash_due'), 'num'], [__('rpt.k_returns'), 'num'],
                [__('rpt.k_gifts'), 'num'], [__('rpt.k_visits'), 'num'],
                [__('rpt.k_clients'), 'num'], [__('rpt.k_custody_units'), 'num'],
                [__('rpt.k_custody_val'), 'num'],
            ],
            'rows' => $rows,
            'totals' => null,
        ];
    }

    // ═══════════════════ ١٠. سجل الزيارات ═══════════════════

    private function rVisitsLog(Request $r): array
    {
        [$a, $b] = $this->range($r);

        $q = Visit::with(['client.group', 'user'])
            ->whereBetween('created_at', [$a, $b])
            ->when($r->filled('user_id'), fn ($w) => $w->where('user_id', $r->integer('user_id')));

        $this->like($q, $r, ['client.name']);

        $rows = $q->latest()->take(self::MAX_ROWS)->get();

        $withInvoice = Invoice::whereIn('visit_id', $rows->pluck('id'))
            ->distinct()->pluck('visit_id')->flip();

        $closed = $rows->whereNotNull('checked_out_at');
        $avgMin = $closed->isEmpty() ? 0 : $closed->avg(
            fn ($v) => $v->checked_out_at->diffInMinutes($v->checked_in_at));

        return [
            'filters' => ['range', 'rep', 'q'],
            'kpis' => [
                [__('rpt.k_visits'), $this->f0($rows->count()), ''],
                [__('rpt.k_closed'), $this->f0($closed->count()), ''],
                [__('rpt.k_with_invoice'), $this->f0($withInvoice->count()), 'pos'],
                [__('rpt.k_avg_min'), $this->f0($avgMin), ''],
            ],
            'columns' => [
                [__('rpt.c_date')], [__('rpt.c_rep')], [__('rpt.c_client')],
                [__('rpt.c_in')], [__('rpt.c_out')], [__('rpt.c_minutes'), 'num'],
                [__('rpt.c_invoiced')],
            ],
            'rows' => $rows->map(fn ($v) => [
                $v->created_at->format('Y-m-d'),
                $v->user?->displayName() ?? '—',
                $v->client?->fullName() ?? '—',
                $v->checked_in_at?->format('h:i A') ?? '—',
                $v->checked_out_at?->format('h:i A') ?? '—',
                $v->checked_out_at && $v->checked_in_at
                    ? $this->f0($v->checked_out_at->diffInMinutes($v->checked_in_at)) : '—',
                $withInvoice->has($v->id) ? '✓' : '—',
            ])->all(),
            'totals' => null,
        ];
    }

    // ═══════════════════ ١١. الهدايا ═══════════════════

    private function rGiftsLog(Request $r): array
    {
        [$a, $b] = $this->range($r);

        // ⚠️ علاقة المندوب على الهدايا اسمها `rep` مش `user` (٢١/٨)
        $q = GiftHandout::with(['client.group', 'rep', 'product'])
            ->whereBetween('created_at', [$a, $b])
            ->when($r->filled('user_id'), fn ($w) => $w->where('user_id', $r->integer('user_id')));

        $this->like($q, $r, ['client.name', 'product.name']);

        $rows = $q->latest()->take(self::MAX_ROWS)->get();

        return [
            'filters' => ['range', 'rep', 'q'],
            'kpis' => [
                [__('rpt.k_count'), $this->f0($rows->count()), ''],
                [__('rpt.k_qty'), $this->f0($rows->sum('qty')), 'mid'],
            ],
            'columns' => [
                [__('rpt.c_date')], [__('rpt.c_rep')], [__('rpt.c_client')],
                [__('rpt.c_product')], [__('rpt.k_qty'), 'num'], [__('rpt.c_reason')],
            ],
            'rows' => $rows->map(fn ($g) => [
                $g->created_at->format('Y-m-d'),
                $g->rep?->displayName() ?? '—',
                $g->client?->fullName() ?? '—',
                $g->product?->displayName() ?? '—',
                $this->f0($g->qty),
                (string) ($g->reason ?? '—'),
            ])->all(),
            'totals' => ['', '', '', __('common.total'), $this->f0($rows->sum('qty')), ''],
        ];
    }

    // ═══════════════════ ١٢. أوامر التوريد ═══════════════════

    private function rPosStatus(Request $r): array
    {
        [$a, $b] = $this->range($r);

        $q = PurchaseOrder::with(['client.group', 'courier'])
            ->whereBetween('created_at', [$a, $b])
            ->when($r->filled('user_id'), fn ($w) => $w->where('assigned_to', $r->integer('user_id')))
            ->when($r->filled('status'), fn ($w) => $w->where('status', $r->input('status')));

        $this->like($q, $r, ['number', 'client.name']);

        $rows = $q->latest()->take(self::MAX_ROWS)->get();

        $open = $rows->whereIn('status', ['pending', 'arrived']);
        $delivered = $rows->where('status', 'delivered');

        return [
            'filters' => ['range', 'rep', 'status', 'q'],
            'kpis' => [
                [__('rpt.k_count'), $this->f0($rows->count()), ''],
                [__('rpt.k_open'), $this->f0($open->count()).' · '.$this->m($open->sum('grand_total')), 'mid'],
                [__('rpt.k_delivered'), $this->f0($delivered->count()).' · '.$this->m($delivered->sum('grand_total')), 'pos'],
                [__('rpt.k_cancelled'), $this->f0($rows->where('status', 'cancelled')->count()), 'neg'],
                [__('rpt.k_late'), $this->f0($rows->filter(fn ($p) => $p->isLate())->count()), 'neg'],
            ],
            'columns' => [
                [__('rpt.c_date')], [__('rpt.c_number')], [__('rpt.c_client')],
                [__('rpt.c_rep')], [__('common.status')], [__('rpt.c_due')],
                [__('rpt.c_delivered_at')], [__('rpt.k_amount'), 'num'],
            ],
            'rows' => $rows->map(fn ($p) => [
                $p->created_at->format('Y-m-d'),
                $p->number,
                $p->client?->fullName() ?? '—',
                $p->courier?->displayName() ?? '—',
                $p->statusLabel().($p->isLate() ? ' ⏰' : ''),
                $p->due_at?->format('Y-m-d h:i A') ?? '—',
                $p->delivered_at?->format('Y-m-d h:i A') ?? '—',
                $this->m($p->grand_total),
            ])->all(),
            'totals' => ['', '', '', '', '', '', __('common.total'), $this->m($rows->sum('grand_total'))],
        ];
    }

    // ═══════════════════ ١٣. عملاء من غير زيارة ═══════════════════

    private function rInactiveClients(Request $r): array
    {
        $days = max(1, $r->integer('days') ?: 14);
        $cut = now()->subDays($days);

        $lastVisits = Visit::whereNotNull('checked_in_at')
            ->selectRaw('client_id, MAX(checked_in_at) t')
            ->groupBy('client_id')->pluck('t', 'client_id');

        $clients = Client::with(['group', 'channel', 'zone', 'rep'])
            ->where('status', 'active')
            ->when($r->filled('channel_id'), fn ($w) => $w->where('channel_id', $r->integer('channel_id')))
            ->get()
            ->filter(function ($c) use ($lastVisits, $cut) {
                $t = $lastVisits->get($c->id);

                return $t === null || Carbon::parse($t)->lt($cut);
            })
            ->sortByDesc('balance')
            ->take(self::MAX_ROWS);

        if ($r->filled('q')) {
            $s = mb_strtolower($r->string('q')->trim());
            $clients = $clients->filter(fn ($c) => str_contains(mb_strtolower($c->fullName().' '.$c->name_en), $s));
        }

        $rows = $clients->map(function ($c) use ($lastVisits) {
            $t = $lastVisits->get($c->id);

            return [
                $c->fullName(),
                $c->channel?->displayName() ?? '—',
                $c->zone?->displayName() ?? '—',
                $c->rep?->displayName() ?? '—',
                $t ? Carbon::parse($t)->format('Y-m-d') : __('rpt.never'),
                $t ? $this->f0(Carbon::parse($t)->diffInDays(now())) : '∞',
                $this->m($c->balance),
            ];
        })->values()->all();

        return [
            'filters' => ['days', 'channel', 'q'],
            'kpis' => [
                [__('rpt.k_clients'), $this->f0(count($rows)), 'neg'],
                [__('rpt.k_balance'), $this->m($clients->sum('balance')), 'mid'],
            ],
            'columns' => [
                [__('rpt.c_client')], [__('rpt.c_channel')], [__('rpt.c_zone')],
                [__('rpt.c_rep')], [__('rpt.c_last_visit')], [__('rpt.c_days'), 'num'],
                [__('rpt.k_balance'), 'num'],
            ],
            'rows' => $rows,
            'totals' => null,
        ];
    }

    // ═══════════════════ ١٤. العملاء الجدد ═══════════════════

    private function rNewClients(Request $r): array
    {
        [$a, $b] = $this->range($r);

        $clients = Client::with(['group', 'channel', 'zone', 'rep'])
            ->whereBetween('created_at', [$a, $b])
            ->when($r->filled('channel_id'), fn ($w) => $w->where('channel_id', $r->integer('channel_id')))
            ->latest()->take(self::MAX_ROWS)->get();

        $sales = Invoice::whereIn('client_id', $clients->pluck('id'))
            ->selectRaw('client_id, SUM(grand_total) g')->groupBy('client_id')->pluck('g', 'client_id');

        $rows = $clients->map(fn ($c) => [
            $c->created_at->format('Y-m-d'),
            $c->fullName(),
            $c->channel?->displayName() ?? '—',
            $c->zone?->displayName() ?? '—',
            $c->rep?->displayName() ?? '—',
            $this->m($sales[$c->id] ?? 0),
            $this->m($c->balance),
        ])->all();

        return [
            'filters' => ['range', 'channel'],
            'kpis' => [
                [__('rpt.k_clients'), $this->f0($clients->count()), 'pos'],
                [__('rpt.k_grand'), $this->m($sales->sum()), ''],
            ],
            'columns' => [
                [__('rpt.c_date')], [__('rpt.c_client')], [__('rpt.c_channel')],
                [__('rpt.c_zone')], [__('rpt.c_rep')],
                [__('rpt.k_sales_since'), 'num'], [__('rpt.k_balance'), 'num'],
            ],
            'rows' => $rows,
            'totals' => ['', __('common.total'), '', '', '', $this->m($sales->sum()), $this->m($clients->sum('balance'))],
        ];
    }

    // ═══════════════════ الكوتيشن ═══════════════════

    public function quotation(Request $request)
    {
        return view('erp.quotation_form', $this->quotationFormData($request));
    }

    /**
     * داتا فورم الكوتيشن — مشتركة بين الإنشاء والتعديل (٢٣/٨).
     *
     * ⚠️ **كل القوايم النشطة مش الافتراضية بس** — الفورم فيه دروب
     * داون قوايم والافتراضية متعلّمة أوتوماتيك، وتغيير القايمة بيعيد
     * تسعير الصفوف في المتصفح من prices المحمّلة.
     */
    private function quotationFormData(Request $request): array
    {
        $lists = PriceList::where('active', true)->orderBy('id')->get();
        $default = PriceList::default();

        // الأسعار من items المحمّلة — من غير كويري لكل صنف×قايمة
        $lists->load('items');

        $products = \App\Models\Product::sellable()->orderBy('code')->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'code' => (string) $p->code,
                'name' => $p->displayName(),
                'name_ar' => (string) $p->name,
                'name_en' => (string) $p->name_en,
                'image' => $p->imageSrc(),
                'units' => $p->unitFactors(),
                'prices' => $lists->mapWithKeys(
                    fn ($l) => [$l->id => \App\Services\Pricing::listPrice($p, $l)]
                ),
            ])
            // صنف مالوش سعر في **أي** قايمة مالوش مكان في عرض سعر —
            // والفلترة بالقايمة المختارة بتحصل في هوك المنتقي
            ->filter(fn ($p) => collect($p['prices'])->contains(fn ($v) => $v > 0))
            ->values();

        return [
            'products' => $products,
            'lists' => $lists,
            'defaultListId' => $default?->id,
            'clients' => Client::visibleTo(Client::query()->where('status', 'active'), $request->user())
                ->with('group')->orderBy('name')->get(['id', 'name', 'name_en', 'group_id']),
            'taxPct' => 0.0,   // قرار المالك ٢٣/٨: الضريبة صفر وهو بيكتبها لو فيه
        ];
    }

    /**
     * ═══ سجل عروض الأسعار (٢١/٨) — «أشوف كل العروض اللي طلعت» ═══
     *
     * ⚠️ **السكوب**: الأدمن الكل + فلتر بمين طلّعه، وغير الأدمن
     * عروضه هو بس — `Quotation::visibleTo`.
     */
    public function quotationsIndex(Request $request)
    {
        [$a, $b] = $this->range($request);

        // ⚠️ السكوب بينداه على الكويري نفسها — لارافيل بتحقن الـbuilder
        // أول حجة لوحدها (إصلاح ٢٢/٨: النداء الستاتيك كان بيبعت
        // builder مكان الـuser ويرمي TypeError)
        $q = \App\Models\Quotation::with(['creator', 'items'])
            ->visibleTo($request->user())
            ->whereBetween('created_at', [$a, $b])
            // فلتر «مين طلّعه» — للأدمن بس، غيره مقفول على نفسه أصلاً
            ->when($request->user()?->isAdmin() && $request->filled('creator_id'),
                fn ($w) => $w->where('created_by', $request->integer('creator_id')));

        if ($request->filled('q')) {
            $s = '%'.$request->string('q')->trim().'%';
            $q->where(fn ($w) => $w->where('number', 'like', $s)
                ->orWhere('client_name', 'like', $s));
        }

        $all = (clone $q)->get(['id', 'grand', 'created_by']);
        $rows = $q->latest()->take(self::MAX_ROWS)->get();

        return view('erp.quotations', [
            'rows' => $rows,
            'kCount' => number_format($all->count()),
            'kValue' => $this->m($all->sum('grand')),
            'kMonth' => number_format(
                \App\Models\Quotation::query()
                    ->visibleTo($request->user())
                    ->where('created_at', '>=', today()->startOfMonth())->count()
            ),
            // فلتر المُصدِر — اللي عملوا عروض فعلاً (أدمن ومديرين)
            'creators' => $request->user()?->isAdmin()
                ? User::whereIn('id', \App\Models\Quotation::query()->select('created_by'))
                    ->orderBy('name')->get(['id', 'name', 'name_en'])
                : collect(),
        ]);
    }

    /**
     * حفظ العرض وفتح صفحته للطباعة — بقى مستند بسجل (٢١/٨) بدل
     * ورقة stateless بتضيع أول ما التاب يتقفل.
     */
    public function quotationStore(Request $request)
    {
        $data = $request->validate($this->quotationRules());

        [$lines, $subtotal, $discount, $net, $tax] = $this->quotationPayload($data);

        $quotation = DB::transaction(function () use ($request, $data, $lines, $subtotal, $discount, $net, $tax) {
            $list = isset($data['price_list_id'])
                ? PriceList::find((int) $data['price_list_id'])
                : null;

            $quotation = \App\Models\Quotation::create([
                'number' => \App\Models\Quotation::nextNumber(),
                'client_name' => $data['client_name'],
                'price_list_id' => $list?->id,
                // ⚠️ مفيش displayName على PriceList — الاسم مباشرة
                'price_list_name' => $list?->name,
                'created_by' => $request->user()?->id,
                'valid_until' => today()->addDays((int) ($data['valid_days'] ?? 30)),
                'discount_pct' => (float) ($data['discount_pct'] ?? 0),
                'extra_pct' => (float) ($data['extra_pct'] ?? 0),
                'tax_pct' => (float) ($data['tax_pct'] ?? 0),
                'tax_inclusive' => $request->boolean('tax_inclusive'),
                'subtotal' => $subtotal,
                'discount' => $discount,
                'net' => $net,
                'tax' => $tax,
                'grand' => round($net + $tax, 2),
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($lines as $l) {
                \App\Models\QuotationItem::create($l + ['quotation_id' => $quotation->id]);
            }

            return $quotation;
        });

        return redirect()->route('erp.reports.quotations.show', $quotation);
    }

    /**
     * الشيرد بين إنشاء العرض وتعديله — البنود المجمّدة والتجميعة.
     *
     * ⚠️ **لقطة الوحدات بتتجمّد هنا (٢٣/٨)** — سعر القطعة من الفورم
     * (قابل للتفاوض)، والعلبة/الكرتونة = السعر × المعامل الحالي
     * للصنف. بتتخزن JSON على البند فالورقة ماتتغيرش لو المعاملات
     * اتعدلت بعدين.
     *
     * @return array{0: \Illuminate\Support\Collection, 1: float, 2: float, 3: float, 4: float}
     */
    private function quotationPayload(array $data): array
    {
        $productIds = collect($data['items'])->pluck('id')->filter()->unique();
        $productMap = \App\Models\Product::whereIn('id', $productIds)->get()->keyBy('id');

        $lines = collect($data['items'])->map(function ($i) use ($productMap) {
            $p = isset($i['id']) ? $productMap->get((int) $i['id']) : null;
            $price = round((float) $i['price'], 2);

            $units = null;

            if ($p !== null) {
                $units = collect($p->unitFactors())->map(fn ($factor, $key) => [
                    'key' => $key,
                    'factor' => (int) $factor,
                    'price' => round($price * (int) $factor, 2),
                ])->values()->all();
            }

            return [
                'product_id' => $p?->id,
                'code' => $p?->code,
                'name' => $i['name'],
                'qty' => (int) $i['qty'],
                'price' => $price,
                'total' => round((int) $i['qty'] * $price, 2),
                'units' => $units,
            ];
        });

        $subtotal = round($lines->sum('total'), 2);

        // ⚠️ الخصمين تسلسليين (٢٣/٨): الإضافي بيتحسب على المتبقي بعد
        // العادي — 10% + 5% = 14.5% مش 15%. ده المتعارف عليه تجارياً.
        $d1 = (float) ($data['discount_pct'] ?? 0);
        $d2 = (float) ($data['extra_pct'] ?? 0);
        $net = round($subtotal * (1 - $d1 / 100) * (1 - $d2 / 100), 2);
        $discount = round($subtotal - $net, 2);
        $tax = round($net * ((float) ($data['tax_pct'] ?? 0)) / 100, 2);

        return [$lines, $subtotal, $discount, $net, $tax];
    }

    /** قواعد فاليديشن العرض — واحدة للإنشاء والتعديل */
    private function quotationRules(): array
    {
        return [
            'client_name' => ['required', 'string', 'max:190'],
            'price_list_id' => ['nullable', 'exists:price_lists,id'],
            'valid_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'discount_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'extra_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'tax_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'tax_inclusive' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['nullable', 'integer', 'exists:products,id'],
            'items.*.name' => ['required', 'string', 'max:190'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:99999'],
            'items.*.price' => ['required', 'numeric', 'min:0'],
        ];
    }

    /**
     * ═══ تعديل عرض محفوظ (٢٣/٨) — نفس الفورم متملي ═══
     *
     * نفس سكوب العرض: اللي يشوف العرض يعدّله (أدمن الكل، المدير
     * عروضه وعروض فريقه). الحفظ بيحدّث نفس الرقم مش بيطلّع جديد.
     */
    public function quotationEdit(Request $request, \App\Models\Quotation $quotation)
    {
        abort_unless(
            \App\Models\Quotation::query()->visibleTo($request->user())
                ->whereKey($quotation->id)->exists(),
            403,
        );

        $quotation->load('items');

        // الأيام المتبقية — عرض منتهي بيرجع 30 من جديد بدل رقم سالب
        $daysLeft = (int) today()->diffInDays($quotation->valid_until, false);

        return view('erp.quotation_form', $this->quotationFormData($request) + [
            'edit' => $quotation,
            'editDays' => $daysLeft > 0 ? $daysLeft : 30,
            'editRows' => $quotation->items->map(fn ($i) => [
                'id' => $i->product_id,
                'code' => (string) $i->code,
                'name' => $i->name,
                'qty' => (int) $i->qty,
                'price' => (float) $i->price,
            ])->values(),
        ]);
    }

    /** حفظ التعديل — بيعيد بناء البنود واللقطات على نفس الرقم */
    public function quotationUpdate(Request $request, \App\Models\Quotation $quotation)
    {
        abort_unless(
            \App\Models\Quotation::query()->visibleTo($request->user())
                ->whereKey($quotation->id)->exists(),
            403,
        );

        $data = $request->validate($this->quotationRules());

        [$lines, $subtotal, $discount, $net, $tax] = $this->quotationPayload($data);

        DB::transaction(function () use ($quotation, $data, $lines, $subtotal, $discount, $net, $tax) {
            $list = isset($data['price_list_id'])
                ? PriceList::find((int) $data['price_list_id'])
                : null;

            $quotation->update([
                'client_name' => $data['client_name'],
                'price_list_id' => $list?->id,
                'price_list_name' => $list?->name,
                'valid_until' => today()->addDays((int) ($data['valid_days'] ?? 30)),
                'discount_pct' => (float) ($data['discount_pct'] ?? 0),
                'extra_pct' => (float) ($data['extra_pct'] ?? 0),
                'tax_pct' => (float) ($data['tax_pct'] ?? 0),
                'tax_inclusive' => request()->boolean('tax_inclusive'),
                'subtotal' => $subtotal,
                'discount' => $discount,
                'net' => $net,
                'tax' => $tax,
                'grand' => round($net + $tax, 2),
                'notes' => $data['notes'] ?? null,
            ]);

            // البنود بتتبني من الأول — أبسط وأضمن من مطابقة صف بصف
            $quotation->items()->delete();

            foreach ($lines as $l) {
                \App\Models\QuotationItem::create($l + ['quotation_id' => $quotation->id]);
            }
        });

        return redirect()->route('erp.reports.quotations.show', $quotation);
    }

    /** صفحة العرض A4 — بتتعاد طباعتها في أي وقت من السجل */
    public function quotationShow(Request $request, \App\Models\Quotation $quotation)
    {
        // نفس سكوب الليستة بالحرف — من غير تكرار منطق الفريق هنا
        // (السكوب بقى بيسمح للمدير بعروض فريقه — ٢٣/٨)
        abort_unless(
            \App\Models\Quotation::query()->visibleTo($request->user())
                ->whereKey($quotation->id)->exists(),
            403,
        );

        // الصورة لايف من الصنف (مرساة product_id) — الباقي كله مجمّد
        $quotation->load('items.product');

        return view('erp.quotation_print', [
            'co' => \App\Models\Setting::docHeader(),
            'quotation' => $quotation,
            'number' => $quotation->number,
            'clientName' => $quotation->client_name,
            'priceListName' => $quotation->price_list_name,
            'validUntil' => $quotation->valid_until,
            'notes' => $quotation->notes,
            'lines' => $quotation->items->map(fn ($i) => [
                'name' => $i->name,
                'code' => $i->code,
                'image' => $i->product?->imageSrc(),
                'units' => $i->units,
                'qty' => (int) $i->qty,
                'price' => (float) $i->price,
                'total' => (float) $i->total,
            ]),
            'subtotal' => (float) $quotation->subtotal,
            'discountPct' => (float) $quotation->discount_pct,
            'extraPct' => (float) ($quotation->extra_pct ?? 0),
            'taxInclusive' => (bool) ($quotation->tax_inclusive ?? true),
            'discount' => (float) $quotation->discount,
            'net' => (float) $quotation->net,
            'taxPct' => (float) $quotation->tax_pct,
            'tax' => (float) $quotation->tax,
            'grand' => (float) $quotation->grand,
        ]);
    }

    /**
     * ═══ تصدير عرض السعر إكسيل (٢٨/٨/٢٠٢٦ — طلب المالك) ═══
     *
     * ملف xlsx حقيقي منسّق بـ`App\Services\SheetWriter` (مفيش
     * PhpSpreadsheet على السيرفر — الرايتر مكتوب بالإيد زي القارئ).
     *
     * ⚠️ **نفس أرقام الورقة المطبوعة بالحرف** — أي اختلاف بين
     * الإكسيل والـPDF بلاغ «الرقم مش مطابق» جاهز:
     *   • سعر المستهلك = السعر المجمّد قبل أي خصم
     *   • سعر العرض = بعد الخصمين **تسلسلياً** (نفس `$dp`)
     *   • أسعار العلبة/الكرتونة مخصومة بنفس `$dp` وعدد القطع جنبها
     *   • عمود الوزن مقسوم من الاسم عند أول رقم (نفس `$splitWeight`)
     *   • **مفيش كمية ولا إجمالي** — ده عرض أسعار مش أوردر (قرار
     *     المالك ٢٣/٨، واتأكد تاني ٢٨/٨)
     *
     * ⚠️ الصورة مش بتتحط في الإكسيل: الرايتر مابيدعمش `drawings`،
     * والكود موجود عمود مستقل — العميل بيعرف الصنف منه.
     */
    public function quotationExcel(Request $request, \App\Models\Quotation $quotation)
    {
        // نفس حارس `quotationShow` بالحرف — التصدير مايفتحش باب أوسع
        abort_unless(
            \App\Models\Quotation::query()->visibleTo($request->user())
                ->whereKey($quotation->id)->exists(),
            403,
        );

        // `items.product` مش `items` — الفولباك على الاسم الحي محتاجه
        $quotation->load('items.product');
        $co = \App\Models\Setting::docHeader();

        $dp = (1 - ((float) $quotation->discount_pct) / 100)
            * (1 - ((float) ($quotation->extra_pct ?? 0)) / 100);

        $unit = function ($item, string $key): ?array {
            foreach ((array) ($item->units ?? []) as $u) {
                if (($u['key'] ?? '') === $key) {
                    return $u;
                }
            }

            return null;
        };

        $hasBox = $quotation->items->contains(fn ($i) => $unit($i, 'box') !== null);
        $hasCase = $quotation->items->contains(fn ($i) => $unit($i, 'case') !== null);

        // الاسم/الوزن — نفس ريجيكس الورقة (السبليت عند أول رقم)
        $splitWeight = function (string $name): array {
            if (preg_match('/^([^0-9٠-٩]+?)[\s\-·،]*([0-9٠-٩].*)$/u', trim($name), $m)) {
                $base = trim($m[1], " \t-·،");
                $weight = trim($m[2]);

                if ($base !== '' && $weight !== '') {
                    return [$base, $weight];
                }
            }

            return [$name, null];
        };

        $x = new \App\Services\SheetWriter(__('rpt.qt_doc_title'));

        // عرض الأعمدة: # · كود · صنف · وزن · مستهلك · عرض · علبة · كرتونة
        foreach ([0 => 5, 1 => 14, 2 => 38, 3 => 12, 4 => 14, 5 => 14, 6 => 16, 7 => 16] as $i => $w) {
            $x->width($i, $w);
        }

        $lastCol = 5 + ($hasBox ? 1 : 0) + ($hasCase ? 1 : 0);

        // ═══ هيدر المستند ═══
        $x->row([['v' => $co['name'] ?: 'PROMAX', 'style' => 'title']]);
        $x->merge(0, $lastCol);
        $x->row([['v' => __('rpt.qt_doc_title').' — '.$quotation->number, 'style' => 'value']]);
        $x->merge(0, $lastCol);
        $x->blank();

        $x->row([
            ['v' => __('rpt.qt_client'), 'style' => 'label'],
            ['v' => $quotation->client_name, 'style' => 'value'],
            null,
            ['v' => __('rpt.qt_valid_until'), 'style' => 'label'],
            ['v' => $quotation->valid_until?->format('Y-m-d'), 'style' => 'value'],
        ]);
        $x->row([
            ['v' => __('rpt.qt_list_title'), 'style' => 'label'],
            ['v' => $quotation->price_list_name, 'style' => 'value'],
            null,
            ['v' => __('rpt.c_date'), 'style' => 'label'],
            ['v' => $quotation->created_at?->format('Y-m-d'), 'style' => 'value'],
        ]);
        $x->blank();

        // ═══ رأس الجدول ═══
        $head = [
            ['v' => '#', 'style' => 'header'],
            ['v' => __('rpt.c_code'), 'style' => 'header'],
            ['v' => __('rpt.c_product'), 'style' => 'header'],
            ['v' => __('rpt.qt_weight'), 'style' => 'header'],
            ['v' => __('rpt.qt_c_consumer'), 'style' => 'header'],
            ['v' => __('rpt.qt_c_offer'), 'style' => 'header'],
        ];
        if ($hasBox) {
            $head[] = ['v' => __('rpt.qt_c_box'), 'style' => 'header'];
        }
        if ($hasCase) {
            $head[] = ['v' => __('rpt.qt_c_case'), 'style' => 'header'];
        }
        $x->row($head);

        // ═══ البنود ═══
        $pieceWord = __('stock.unit_piece');

        foreach ($quotation->items->values() as $i => $item) {
            // ⚠️ فولباك على اسم الصنف الحي لو الاسم المجمّد فاضي —
            // خلية اسم فاضية بتخلي الورقة كلها بلا معنى للعميل
            $raw = trim((string) $item->name) !== ''
                ? (string) $item->name
                : (string) ($item->product?->displayName() ?? '');

            [$name, $weight] = $splitWeight($raw);
            $price = (float) $item->price;

            $row = [
                ['v' => $i + 1, 'style' => 'center', 'num' => true],
                ['v' => $item->code, 'style' => 'center'],
                ['v' => $name, 'style' => 'center'],
                ['v' => $weight ?? '—', 'style' => 'center'],
                ['v' => round($price, 2), 'style' => 'money', 'num' => true],
                ['v' => round($price * $dp, 2), 'style' => 'money_bold', 'num' => true],
            ];

            // ⚠️ **خلية الوحدة رقم صافي، مش نص.** الورقة المطبوعة
            // بتحط بادج «١٢ قطعة» فوق السعر — هنا ممنوع: أي نص جوه
            // الخلية بيخلي إكسيل يتعامل معاها كنص فالعميل مايقدرش
            // يحسب عليها. عدد القطع بيتقال مرة واحدة في السطر
            // التوضيحي تحت الجدول.
            foreach ([['box', $hasBox], ['case', $hasCase]] as [$key, $show]) {
                if (! $show) {
                    continue;
                }

                $u = $unit($item, $key);

                $row[] = $u === null
                    ? ['v' => '—', 'style' => 'center']
                    : [
                        'v' => round(((float) $u['price']) * $dp, 2),
                        'style' => 'money',
                        'num' => true,
                    ];
            }

            $x->row($row);
        }

        // ═══ سطر «الوحدات» التوضيحي — كام قطعة في العلبة/الكرتونة ═══
        if ($hasBox || $hasCase) {
            $factors = [];
            foreach ($quotation->items as $item) {
                foreach ([['box', __('rpt.qt_c_box')], ['case', __('rpt.qt_c_case')]] as [$key, $label]) {
                    $u = $unit($item, $key);
                    $f = (int) ($u['factor'] ?? 0);

                    if ($f > 1) {
                        $factors[$label][$f] = true;
                    }
                }
            }

            $parts = [];
            foreach ($factors as $label => $set) {
                $parts[] = $label.': '.implode(' / ', array_keys($set)).' '.$pieceWord;
            }

            if ($parts !== []) {
                $x->blank();
                $x->row([['v' => implode('   •   ', $parts), 'style' => 'muted']]);
                $x->merge(0, $lastCol);
            }
        }

        // ═══ التاجات والجملة الضريبية — نفس نص الورقة ═══
        $fmtPct = fn ($p) => rtrim(rtrim(number_format((float) $p, 1), '0'), '.');
        $tags = [];
        if ((float) $quotation->discount_pct > 0) {
            $tags[] = __('rpt.qt_tag_disc', ['p' => $fmtPct($quotation->discount_pct)]);
        }
        if ((float) ($quotation->extra_pct ?? 0) > 0) {
            $tags[] = __('rpt.qt_tag_extra', ['p' => $fmtPct($quotation->extra_pct)]);
        }
        $tags[] = ($quotation->tax_inclusive ?? true) ? __('rpt.qt_incl') : __('rpt.qt_excl');

        $x->blank();
        $x->row([['v' => implode('   •   ', $tags), 'style' => 'total']]);
        $x->merge(0, $lastCol);

        if ($quotation->notes) {
            $x->blank();
            $x->row([['v' => __('rpt.qt_notes').': '.$quotation->notes, 'style' => 'muted']]);
            $x->merge(0, $lastCol);
        }

        $x->blank();
        $x->row([[
            'v' => __('rpt.qt_footer', ['date' => $quotation->valid_until?->format('Y-m-d')]),
            'style' => 'muted',
        ]]);
        $x->merge(0, $lastCol);

        // بيانات التحويل البنكي — لو مسجلة (زي الورقة بالظبط)
        $bank = array_filter($co['bank'] ?? []);
        if ($bank && ! ($co['bank_demo'] ?? false)) {
            $line = [];
            foreach ([
                'doc.bank_name' => $co['bank']['name'] ?? null,
                'doc.bank_account_no' => $co['bank']['account_no'] ?? null,
                'doc.bank_iban' => $co['bank']['iban'] ?? null,
            ] as $lk => $lv) {
                if ($lv) {
                    $line[] = __($lk).': '.$lv;
                }
            }

            if ($line !== []) {
                $x->row([['v' => '🏦 '.implode('   •   ', $line), 'style' => 'muted']]);
                $x->merge(0, $lastCol);
            }
        }

        return $x->download($quotation->number.'.xlsx');
    }
}
