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
        // المنسقين (٢١ سبتمبر ٢٠٢٦) — من جرد الرف وزيارات الرفوف
        'shelf_expiry' => '⏰',
        'merch_performance' => '🛍️',
        // تقارير القرار (٢١ سبتمبر ٢٠٢٦)
        'client_products' => '🧺',
        'sales_decline' => '📉',
        'target_vs_actual' => '🎯',
        'oos_frequency' => '🕳️',
        'profitability' => '💹',
        // مطابقة: ليه رقم الداشبورد مش هو رقم كشف الحساب (٢١ سبتمبر ٢٠٢٦)
        'sales_reconcile' => '⚖️',
        // أرقام كانت في الداتا ومالهاش شاشة (مراجعة ٢٢ سبتمبر ٢٠٢٦)
        'discounts_given' => '🏷️',
        'returns_quality' => '🧯',
        'gifts_balance' => '🎁',
        'contract_schedule' => '📜',
    ];

    /** تقارير فيها تكلفة وربح — للأدمن بس، زي عمود التكلفة في «المبيعات بالصنف» */
    private const ADMIN_ONLY = ['profitability', 'sales_reconcile'];

    private const MAX_ROWS = 2000;

    public function index()
    {
        $admin = request()->user()?->isAdmin() ?? false;

        return view('erp.report_hub', ['reports' => array_filter(self::REPORTS,
            fn ($k) => $admin || ! in_array($k, self::ADMIN_ONLY, true), ARRAY_FILTER_USE_KEY)]);
    }

    public function show(Request $request, string $key)
    {
        abort_unless(isset(self::REPORTS[$key]), 404);
        abort_if(in_array($key, self::ADMIN_ONLY, true) && ! ($request->user()?->isAdmin() ?? false), 403);

        $method = 'r'.str_replace(' ', '', ucwords(str_replace('_', ' ', $key)));
        $data = $this->{$method}($request);

        $data += [
            'key' => $key,
            'icon' => self::REPORTS[$key],
            'title' => __('rpt.'.$key),
            'repOptions' => User::fieldVisibleTo(User::whereIn('role', User::FIELD_WORK_ROLES), $request->user())
                ->where('active', true)->orderBy('name')->get(['id', 'name', 'name_en']),
            'channelOptions' => \App\Models\Channel::orderBy('id')->get(),
        ];

        // ═══ الفترة ووقت السحب (٢١/٩) — على الشاشة وفي أول سطور الملف ═══
        $hasRange = in_array('range', $data['filters'] ?? [], true);
        [$pa, $pb] = $hasRange ? $this->range($request) : [null, null];
        $data['periodFrom'] = $pa?->toDateString();
        $data['periodTo'] = $pb?->toDateString();
        $data['generatedAt'] = now()->format('Y-m-d h:i A');

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

    // ═══ اللينكات (٢٢/٩) — «كل اسم سجل بيفتحه» ═══
    // الخلية إما نص أو `['text' => …, 'url' => …]`؛ الفيو بيرسم اللينك
    // والتصدير بياخد النص بس (`cellText`).

    private function lk(?string $text, ?string $url): array|string
    {
        $text = (string) $text;

        if ($text === '' || $text === '—') {
            return '—';
        }

        return $url === null ? $text : ['text' => $text, 'url' => $url];
    }

    private function cClient(?Client $c): array|string
    {
        return $c === null ? '—' : $this->lk($c->fullName(), route('erp.clients.show', $c->id));
    }

    private function cRep(?User $u): array|string
    {
        return $u === null ? '—' : $this->lk($u->displayName(), route('ops.rep', $u->id));
    }

    private function cProduct(?\App\Models\Product $p, ?string $text = null): array|string
    {
        return $p === null ? ($text ?? '—') : $this->lk($text ?? $p->displayName(), route('erp.products.show', $p->id));
    }

    private static function cellText(mixed $cell): string
    {
        return is_array($cell) ? (string) ($cell['text'] ?? '') : (string) $cell;
    }

    /** لينك كارت لتقرير تاني بنفس الفترة والمندوب والقناة */
    private function to(string $key, array $extra = []): string
    {
        $keep = array_filter(request()->only(['from', 'to', 'user_id', 'channel_id']), fn ($v) => is_string($v) && $v !== '');

        return route('erp.reports.show', ['key' => $key] + array_filter($extra + $keep, fn ($v) => $v !== null));
    }

    /**
     * لينك كارت لشاشة تانية. ⚠️ الفترة بتتبعت **صريحة** — الفترة الفاضية
     * هنا معناها الشهر الحالي، وفي باقي الشاشات معناها «كل الفترات».
     */
    private function scr(string $route, array $extra = [], bool $withRange = true): string
    {
        if ($withRange) {
            [$a, $b] = $this->range(request());
            $extra += ['from' => $a->toDateString(), 'to' => $b->toDateString()];
        }

        return route($route, $extra);
    }

    /** كارت = فلتر على نفس التقرير: `[url, on]` — دوسة تانية بتشيل الفلتر */
    private function flt(string $param, string $value): array
    {
        $on = (string) request($param) === $value;

        return [request()->fullUrlWithQuery([$param => $on ? null : $value, 'export' => null]), $on];
    }

    private function csv(array $d)
    {
        $name = $d['key'].'-'.now()->format('Y-m-d-Hi').'.csv';

        return response()->streamDownload(function () use ($d) {
            $out = fopen('php://output', 'w');
            // BOM — من غيره إكسيل بيفتح العربي طلاسم
            fwrite($out, "\xEF\xBB\xBF");

            foreach (\App\Support\Csv::meta($d['title'], $d['periodFrom'] ?? null, $d['periodTo'] ?? null) as $m) {
                fputcsv($out, $m);
            }
            fputcsv($out, []);
            fputcsv($out, array_map(fn ($c) => $c[0], $d['columns']));

            // الخلايا اللي بلينك بتنزل بنصّها، وأعمدة الأرقام من غير فاصلة الآلاف
            // عشان إكسيل يجمعها كأرقام (نفس قاعدة `Support\Csv`)
            $plain = function (array $row) use ($d) {
                foreach ($row as $i => $cell) {
                    $t = self::cellText($cell);
                    $row[$i] = ($d['columns'][$i][1] ?? null) === 'num' && preg_match('/^-?[\d,]+(\.\d+)?$/', $t)
                        ? str_replace(',', '', $t) : $t;
                }

                return $row;
            };

            foreach ($d['rows'] as $row) {
                fputcsv($out, $plain($row));
            }

            if (! empty($d['totals'])) {
                fputcsv($out, $plain($d['totals']));
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

        // ⚠️ الفاتورة بتدخل الفترة **بتاريخ قيدها في كشف الحساب** (٢١/٩) —
        // نفس قاعدة الداشبورد وصفحة العملاء، فمجموع الفواتير هنا هو نفس
        // رقم «كاش + آجل» فوق. فاتورة من غير قيد بيع مش مبيعات.
        $q = Invoice::with(['client.group', 'user'])
            ->whereIn('id', Transaction::where('kind', 'sale')->where('source_type', Invoice::class)
                ->whereBetween('date', [$a->toDateString(), $b->toDateString()])->select('source_id'))
            ->when($r->filled('user_id'), fn ($w) => $w->where('user_id', $r->integer('user_id')))
            ->when($r->filled('payment'), fn ($w) => $w->where('payment', $r->input('payment')));

        $this->like($q, $r, ['number', 'paper_ref', 'client.name']);

        $all = (clone $q)->get(['id', 'payment', 'total', 'tax_total', 'grand_total']);
        $rows = $q->latest()->take(self::MAX_ROWS)->get();

        return [
            'filters' => ['range', 'rep', 'payment', 'q'],
            'kpis' => [
                [__('rpt.k_count'), $this->f0($all->count()), '', $this->scr('ops.invoices', array_filter([
                    'user' => $r->input('user_id'), 'pay' => $r->input('payment')]))],
                [__('rpt.k_net'), $this->m($all->sum('total')), '', $this->to('sales_by_product')],
                [__('rpt.k_tax'), $this->m($all->sum('tax_total')), '', $this->to('sales_by_product')],
                [__('rpt.k_grand'), $this->m($all->sum('grand_total')), 'pos', $this->to('sales_by_client')],
                [__('rpt.k_cash'), $this->m($all->where('payment', 'cash')->sum('grand_total')), '', ...$this->flt('payment', 'cash')],
                [__('rpt.k_credit'), $this->m($all->where('payment', 'credit')->sum('grand_total')), 'mid', ...$this->flt('payment', 'credit')],
            ],
            'columns' => [
                [__('rpt.c_date')], [__('rpt.c_number')], [__('rpt.c_paper')],
                [__('rpt.c_rep')], [__('rpt.c_client')], [__('rpt.c_payment')],
                [__('rpt.k_net'), 'num'], [__('rpt.k_tax'), 'num'], [__('rpt.k_grand'), 'num'],
            ],
            'rows' => $rows->map(fn ($i) => [
                $i->created_at->format('Y-m-d'),
                $this->lk($i->number, route('ops.invoice', $i->id)),
                $this->lk($i->paper_ref, route('ops.invoices', ['paper' => $i->paper_ref])),
                $this->cRep($i->user),
                $this->cClient($i->client),
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

        // ⚠️ سكوب الفريق — التقرير كان بيعرض مناديب كل المديرين لأي مدير
        $reps = User::fieldVisibleTo(User::whereIn('role', User::FIELD_WORK_ROLES), $r->user())
            ->where('active', true)->orderBy('name')->get();

        // ⚠️ **فواتير + أوامر توريد مسلّمة** (٢١/٩) — كان فواتير بس، فالسواق
        // والمندوب اللي شغله توريد كانوا بيطلعوا بصفر مبيعات. نفس تعريف
        // الداشبورد (`SalesSource`)، و`po_g` عمود مستقل عشان يبان المصدر.
        $inv = \App\Services\SalesSource::docs($a, $b)
            ->selectRaw("user_id, COUNT(*) c, SUM(total) net, SUM(tax_total) tax, SUM(grand_total) g,
                SUM(CASE WHEN payment = 'cash' THEN grand_total ELSE 0 END) cash_g,
                SUM(CASE WHEN kind = 'po' THEN grand_total ELSE 0 END) po_g, MAX(doc_at) last_at")
            ->groupBy('user_id')->get()->keyBy('user_id');

        $rets = \App\Services\SalesSource::returns($a, $b)
            ->selectRaw('user_id, COUNT(*) c, SUM(amount) g')
            ->groupBy('user_id')->get()->keyBy('user_id');

        $gifts = GiftHandout::whereBetween('created_at', [$a, $b])
            ->selectRaw('user_id, SUM(qty) q')->groupBy('user_id')->pluck('q', 'user_id');

        $visits = Visit::whereBetween('created_at', [$a, $b])
            ->selectRaw('user_id, COUNT(*) c')->groupBy('user_id')->pluck('c', 'user_id');

        $rows = [];
        $T = ['c' => 0, 'net' => 0, 'tax' => 0, 'g' => 0, 'cash' => 0, 'po' => 0, 'ret' => 0, 'gift' => 0, 'vis' => 0];

        foreach ($reps as $rep) {
            $i = $inv->get($rep->id);
            $rt = $rets->get($rep->id);

            if ($i === null && $rt === null && ! $visits->has($rep->id)) {
                continue;
            }

            $rows[] = [
                $this->cRep($rep),
                $this->f0($i->c ?? 0),
                $this->m($i->net ?? 0),
                $this->m($i->tax ?? 0),
                $this->m($i->g ?? 0),
                $this->m($i->cash_g ?? 0),
                $this->m(($i->g ?? 0) - ($i->cash_g ?? 0) - ($i->po_g ?? 0)),
                $this->m($i->po_g ?? 0),
                $this->m($rt->g ?? 0),
                $this->f0($gifts[$rep->id] ?? 0),
                $this->f0($visits[$rep->id] ?? 0),
                substr((string) ($i->last_at ?? ''), 0, 10) ?: '—',
            ];

            $T['c'] += $i->c ?? 0; $T['net'] += $i->net ?? 0; $T['tax'] += $i->tax ?? 0;
            $T['g'] += $i->g ?? 0; $T['cash'] += $i->cash_g ?? 0; $T['po'] += $i->po_g ?? 0;
            $T['ret'] += $rt->g ?? 0; $T['gift'] += $gifts[$rep->id] ?? 0;
            $T['vis'] += $visits[$rep->id] ?? 0;
        }

        return [
            'filters' => ['range'],
            'kpis' => [
                [__('rpt.k_reps'), $this->f0(count($rows)), '', $this->to('reps_overview')],
                [__('rpt.k_count'), $this->f0($T['c']), '', $this->to('sales_docs')],
                [__('rpt.k_grand'), $this->m($T['g']), 'pos', $this->to('sales_by_client')],
                [__('rpt.k_returns'), $this->m($T['ret']), 'neg', $this->to('returns_docs')],
                [__('rpt.k_visits'), $this->f0($T['vis']), '', $this->to('visits_log')],
            ],
            'columns' => [
                [__('rpt.c_rep')], [__('rpt.k_count'), 'num'], [__('rpt.k_net'), 'num'],
                [__('rpt.k_tax'), 'num'], [__('rpt.k_grand'), 'num'], [__('rpt.k_cash'), 'num'],
                [__('rpt.k_credit'), 'num'], [__('rpt.k_po_delivered'), 'num'], [__('rpt.k_returns'), 'num'],
                [__('rpt.k_gifts'), 'num'], [__('rpt.k_visits'), 'num'], [__('rpt.c_last_op')],
            ],
            'rows' => $rows,
            'totals' => [__('common.total'), $this->f0($T['c']), $this->m($T['net']),
                $this->m($T['tax']), $this->m($T['g']), $this->m($T['cash']),
                $this->m($T['g'] - $T['cash'] - $T['po']), $this->m($T['po']), $this->m($T['ret']),
                $this->f0($T['gift']), $this->f0($T['vis']), ''],
        ];
    }

    // ═══════════════════ ٣. المبيعات بالعميل ═══════════════════

    private function rSalesByClient(Request $r): array
    {
        [$a, $b] = $this->range($r);

        // ⚠️ **المصدر كشف الحساب مش جدول الفواتير** (إصلاح ٢٠ سبتمبر ٢٠٢٦).
        // النسخة القديمة كانت بتجمع `invoices` بس، فأوامر التوريد المسلّمة
        // — ومعظم مبيعات الكي أكاونت منها — ماكانتش بتتحسب، ورقم الشهر
        // بيطلع أقل من الحقيقي. دلوقتي نفس تعريف `Client::recalculate()`
        // (sale.debit · return.credit · collection.credit) وعلى عمود `date`،
        // فالرقم هنا = الرقم في صفحة العملاء بفلتر الفترة = كشف الحساب.
        $day = [$a->toDateString(), $b->toDateString()];
        $repId = $r->filled('user_id') ? $r->integer('user_id') : null;

        $inv = Transaction::where('kind', 'sale')->whereBetween('date', $day)
            // فلتر المندوب على صاحب المستند: فاتورته هو، أو أمر توريد متسلّمله
            ->when($repId, fn ($w) => $w->where(fn ($x) => $x
                ->whereHasMorph('source', [Invoice::class], fn ($m) => $m->where('user_id', $repId))
                ->orWhereHasMorph('source', [PurchaseOrder::class], fn ($m) => $m->where('assigned_to', $repId))))
            ->selectRaw('client_id, COUNT(*) c, SUM(debit) g, SUM(COALESCE(tax, 0)) x, MAX(date) last_at')
            ->groupBy('client_id')->get()->keyBy('client_id');

        $rets = Transaction::where('kind', 'return')->whereBetween('date', $day)
            ->selectRaw('client_id, SUM(credit) g')->groupBy('client_id')->pluck('g', 'client_id');

        $colls = Transaction::where('kind', 'collection')->whereBetween('date', $day)
            ->selectRaw('client_id, SUM(credit) g')->groupBy('client_id')->pluck('g', 'client_id');

        // ⚠️ `visibleTo` — التقرير كان بيعرض عملاء كل الفرق لأي مدير
        $clients = Client::visibleTo(Client::query()->with(['group', 'channel']), $r->user())
            ->whereIn('clients.id', $inv->keys()->merge($rets->keys())->merge($colls->keys())->unique())
            ->when($r->filled('channel_id'), fn ($w) => $w->where('channel_id', $r->integer('channel_id')))
            ->get()
            ->sortByDesc(fn ($c) => (float) ($inv->get($c->id)->g ?? 0))
            ->take(self::MAX_ROWS);

        if ($r->filled('q')) {
            $s = mb_strtolower($r->string('q')->trim());
            $clients = $clients->filter(fn ($c) => str_contains(mb_strtolower($c->fullName().' '.$c->name_en), $s));
        }

        $tg = 0; $tr = 0; $tc = 0; $tb = 0; $tx = 0;

        $rows = $clients->map(function ($c) use ($inv, $rets, $colls, &$tg, &$tr, &$tc, &$tb, &$tx) {
            $g = (float) ($inv->get($c->id)->g ?? 0);
            $tx += (float) ($inv->get($c->id)->x ?? 0);
            $tg += $g; $tr += (float) ($rets[$c->id] ?? 0);
            $tc += (float) ($colls[$c->id] ?? 0); $tb += (float) $c->balance;

            return [
                $this->cClient($c),
                $c->channel?->displayName() ?? '—',
                $this->f0($inv->get($c->id)->c ?? 0),
                $this->m($g),
                // نصيب الضريبة من المبيعات (٢٢/٩) — كان متسجل على القيد ومش ظاهر لأي عميل
                $this->m($inv->get($c->id)->x ?? 0),
                $this->m($rets[$c->id] ?? 0),
                $this->m($colls[$c->id] ?? 0),
                $this->m($c->balance),
                substr((string) ($inv->get($c->id)->last_at ?? ''), 0, 10) ?: '—',
            ];
        })->values()->all();

        return [
            'filters' => ['range', 'rep', 'channel', 'q'],
            'kpis' => [
                [__('rpt.k_clients'), $this->f0(count($rows)), '', $this->to('client_products')],
                [__('rpt.k_grand'), $this->m($tg), 'pos', $this->to('sales_docs')],
                [__('rpt.k_returns'), $this->m($tr), 'neg', $this->to('returns_docs')],
                [__('rpt.k_collected'), $this->m($tc), '', $this->to('collections')],
                [__('rpt.k_balance'), $this->m($tb), 'mid', $this->to('debts')],
            ],
            'columns' => [
                [__('rpt.c_client')], [__('rpt.c_channel')], [__('rpt.k_count'), 'num'],
                [__('rpt.k_grand'), 'num'], [__('rpt.c_of_which_tax'), 'num'], [__('rpt.k_returns'), 'num'],
                [__('rpt.k_collected'), 'num'], [__('rpt.k_balance'), 'num'], [__('rpt.c_last_op')],
            ],
            'rows' => $rows,
            'totals' => [__('common.total'), '', '', $this->m($tg), $this->m($tx), $this->m($tr), $this->m($tc), $this->m($tb), ''],
        ];
    }

    // ═══════════════════ ٤. المبيعات بالصنف ═══════════════════

    private function rSalesByProduct(Request $r): array
    {
        [$a, $b] = $this->range($r);
        $seeCost = $r->user()?->isAdmin() ?? false;

        // ⚠️ فواتير + بنود أوامر التوريد المسلّمة (٢١/٩) — كان فواتير بس
        $lines = \App\Services\SalesSource::lines($a, $b,
                $r->filled('user_id') ? [$r->integer('user_id')] : null)
            // ⚠️ `covered`/`uncosted` (٢٢/٩) — نفس قاعدة تقرير الربحية: البند اللي تكلفته
            // صفر مالوش ربح معروف. كان الربح = الصافي − التكلفة، فمع تكلفة صفر
            // طلع «الربح» = المبيعات كلها وناقض تقرير الربحية.
            ->selectRaw('product_id, SUM(qty) q, SUM(total) net, SUM(tax) tax, SUM(cost) cost,
                SUM(CASE WHEN cost > 0 THEN total ELSE 0 END) covered,
                SUM(CASE WHEN cost > 0 THEN 0 ELSE total END) uncosted, MAX(doc_at) last_at')
            ->groupBy('product_id')
            ->get()->keyBy('product_id');

        $products = \App\Models\Product::whereIn('id', $lines->keys())->orderBy('name')->get();

        if ($r->filled('q')) {
            $s = mb_strtolower($r->string('q')->trim());
            $products = $products->filter(fn ($p) => str_contains(
                mb_strtolower($p->displayName().' '.$p->code), $s));
        }

        $tq = 0; $tn = 0; $tt = 0; $tc = 0; $tcov = 0.0; $tunc = 0.0;
        $rows = [];

        foreach ($products->sortByDesc(fn ($p) => (float) $lines[$p->id]->net) as $p) {
            $l = $lines[$p->id];
            $tq += $l->q; $tn += $l->net; $tt += $l->tax; $tc += $l->cost;
            $tcov += (float) $l->covered; $tunc += (float) $l->uncosted;

            $row = [
                $this->cProduct($p, (string) $p->code), $this->cProduct($p), $this->f0($l->q),
                $this->m($l->net), $this->m($l->tax), $this->m($l->net + $l->tax),
                substr((string) $l->last_at, 0, 10) ?: '—',
            ];

            if ($seeCost) {
                $row[] = $this->m($l->cost);
                // الربح على المبيعات المتغطية بتكلفة بس — غير كده «—»
                $row[] = $l->covered > 0 ? $this->m($l->covered - $l->cost) : '—';
                $row[] = $this->m($l->uncosted);
            }

            $rows[] = $row;
        }

        // ⚠️ قيود بيع مالهاش بنود (استيراد/قيد يدوي/فرق تقريب): سطر صريح بالفرق،
        // فإجمالي التقرير = مبيعات كشف الحساب بالظبط. بيتحسب من غير فلتر البحث.
        if (! $r->filled('q')) {
            $ledgerG = (float) \App\Services\SalesSource::docs($a, $b,
                $r->filled('user_id') ? [$r->integer('user_id')] : null)->sum('grand_total');
            $gap = round($ledgerG - ($tn + $tt), 2);

            if (abs($gap) >= 0.01) {
                $row = ['—', __('rpt.entries_without_lines'), '', $this->m($gap), $this->m(0), $this->m($gap), ''];
                if ($seeCost) {
                    $row[] = $this->m(0);
                    $row[] = '—';
                    $row[] = $this->m($gap);
                    $tunc += $gap;
                }
                $rows[] = $row;
                $tn += $gap;
            }
        }

        $columns = [
            [__('common.code')], [__('rpt.c_product')], [__('rpt.k_qty'), 'num'],
            [__('rpt.k_net'), 'num'], [__('rpt.k_tax'), 'num'], [__('rpt.k_grand'), 'num'], [__('rpt.c_last_op')],
        ];
        $totals = [__('common.total'), '', $this->f0($tq), $this->m($tn), $this->m($tt), $this->m($tn + $tt), ''];

        if ($seeCost) {
            $columns[] = [__('rpt.k_cost'), 'num'];
            $columns[] = [__('rpt.k_profit'), 'num'];
            $columns[] = [__('rpt.k_uncosted'), 'num'];
            $totals[] = $this->m($tc);
            $totals[] = $this->m($tcov - $tc);
            $totals[] = $this->m($tunc);
        }

        return [
            'filters' => ['range', 'rep', 'q'],
            'kpis' => array_filter([
                [__('rpt.k_products'), $this->f0(count($rows)), '', $this->scr('erp.stock', [], false)],
                [__('rpt.k_qty'), $this->f0($tq), '', $this->to('client_products')],
                [__('rpt.k_grand'), $this->m($tn + $tt), 'pos', $this->to('sales_docs')],
                $seeCost ? [__('rpt.k_profit'), $this->m($tcov - $tc), 'mid', $this->to('profitability')] : null,
                $seeCost ? [__('rpt.k_uncosted'), $this->m($tunc), 'neg', $this->scr('erp.stock', [], false)] : null,
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

        // ⚠️ فواتير + أوامر توريد مسلّمة (٢١/٩) — الكي أكاونت معظمه توريد،
        // فنصيبه كان بيطلع أقل من حقيقته بكتير
        $agg = \App\Services\SalesSource::docs($a, $b)
            ->join('clients', 'clients.id', '=', 's.client_id')
            ->selectRaw('clients.channel_id ch, COUNT(*) c, SUM(s.grand_total) g, MAX(s.doc_at) last_at')
            ->groupBy('clients.channel_id')->get();

        $total = (float) $agg->sum('g') ?: 1;
        $channels = \App\Models\Channel::all()->keyBy('id');

        $rows = $agg->sortByDesc('g')->map(fn ($x) => [
            // القناة بتفتح «المبيعات بالعميل» لنفس الفترة مفلترة عليها
            $this->lk($channels->get($x->ch)?->displayName(), $this->to('sales_by_client', ['channel_id' => $x->ch])),
            $this->f0($x->c),
            $this->m($x->g),
            number_format($x->g / $total * 100, 1).'%',
            substr((string) $x->last_at, 0, 10) ?: '—',
        ])->values()->all();

        return [
            'filters' => ['range'],
            'kpis' => [
                [__('rpt.k_count'), $this->f0($agg->sum('c')), '', $this->to('sales_docs')],
                [__('rpt.k_grand'), $this->m($agg->sum('g')), 'pos', $this->to('sales_by_client')],
            ],
            'columns' => [
                [__('rpt.c_channel')], [__('rpt.k_count'), 'num'],
                [__('rpt.k_grand'), 'num'], [__('rpt.k_share'), 'num'], [__('rpt.c_last_op')],
            ],
            'rows' => $rows,
            // ⚠️ خانة النسبة فاضية في الإجمالي — النِسب مابتتجمعش (٢٢/٩)
            'totals' => [__('common.total'), $this->f0($agg->sum('c')), $this->m($agg->sum('g')), '', ''],
        ];
    }

    // ═══════════════════ ٦. التحصيلات ═══════════════════

    private function rCollections(Request $r): array
    {
        [$a, $b] = $this->range($r);

        $q = Transaction::with('client.group')
            ->where('kind', 'collection')
            // ⚠️ بتاريخ القيد (`date`) مش تاريخ التسجيل — نفس رقم الداشبورد (٢١/٩)
            ->whereBetween('date', [$a->toDateString(), $b->toDateString()]);

        $this->like($q, $r, ['memo', 'reference', 'client.name']);

        // ═══ كروت التقسيمة بقت فلاتر (٢٢/٩): `source` و`method`. التقسيمة نفسها
        // بتتحسب **قبل** الفلترين دول عشان الكروت تفضل شغالة كتابات، والعدد
        // والإجمالي والجدول بعدهم. ═══
        $srcMap = ['invoice' => Invoice::class, 'visit' => Visit::class, 'po' => PurchaseOrder::class];
        $src = (string) $r->input('source');
        $method = (string) $r->input('method');

        $base = (clone $q)->get(['id', 'credit', 'method', 'source_type']);

        $q->when(isset($srcMap[$src]), fn ($w) => $w->where('source_type', $srcMap[$src]))
            ->when($src === 'office', fn ($w) => $w->where(fn ($x) => $x->whereNull('source_type')
                ->orWhereNotIn('source_type', array_values($srcMap))))
            ->when($method === 'none', fn ($w) => $w->whereNull('method'))
            ->when(in_array($method, Transaction::METHODS, true), fn ($w) => $w->where('method', $method));

        $all = (clone $q)->get(['id', 'credit', 'method', 'source_type']);
        $rows = $q->latest()->take(self::MAX_ROWS)->get();

        $byMethod = fn ($m) => $this->m($base->where('method', $m)->sum('credit'));

        // المصدر بيفتح مستنده: فاتورة / أمر توريد / زيارات اليوم ده
        $srcUrl = fn ($t) => match ($t->source_type) {
            Invoice::class => route('ops.invoice', $t->source_id),
            PurchaseOrder::class => route('ops.pos.show', $t->source_id),
            Visit::class => route('ops.visits', ['from' => $t->date?->toDateString(), 'to' => $t->date?->toDateString()]),
            User::class => route('ops.rep', $t->source_id),
            default => null,
        };

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
        $bySrc = fn ($cls) => $this->m($base->where('source_type', $cls)->sum('credit'));
        $officeSum = $base->filter(fn ($t) => ! in_array($t->source_type, array_values($srcMap), true))->sum('credit');

        return [
            'filters' => ['range', 'q'],
            'extraSelects' => [
                'source' => [__('rpt.c_source'), __('ui.all_of', ['x' => __('uib.sources')]), [
                    'invoice' => __('rpt.src_invoice'), 'visit' => __('rpt.src_visit'),
                    'po' => __('rpt.src_po'), 'office' => __('rpt.src_office')]],
                'method' => [__('ui.l_method'), __('ui.all_of', ['x' => __('uib.methods')]),
                    collect(Transaction::METHODS)->mapWithKeys(fn ($m) => [$m => __('rpt.m_'.$m)])->all()
                        + ['none' => __('rpt.m_with_invoice')]],
            ],
            'kpis' => [
                [__('uib.k_entries'), $this->f0($all->count()), '', $this->scr('erp.collections')],
                [__('rpt.k_collected'), $this->m($all->sum('credit')), 'pos', $this->scr('erp.collections')],
                // تقسيمة المصدر — لازم تطابق بوكس التحصيل في الداشبورد
                [__('rpt.src_invoice'), $bySrc(\App\Models\Invoice::class), '', ...$this->flt('source', 'invoice')],
                [__('rpt.src_visit'), $bySrc(\App\Models\Visit::class), '', ...$this->flt('source', 'visit')],
                [__('rpt.src_po'), $bySrc(\App\Models\PurchaseOrder::class), '', ...$this->flt('source', 'po')],
                // «مكتب» كان ناقص من الكروت فمجموعهم مابيقفلش على الإجمالي (٢٢/٩)
                [__('rpt.src_office'), $this->m($officeSum), '', ...$this->flt('source', 'office')],
                // تقسيمة الطريقة — للمطابقة المحاسبية
                [__('rpt.m_cash'), $byMethod('cash'), '', ...$this->flt('method', 'cash')],
                [__('rpt.m_card'), $byMethod('card'), '', ...$this->flt('method', 'card')],
                [__('rpt.m_cheque'), $byMethod('cheque'), '', ...$this->flt('method', 'cheque')],
                [__('rpt.m_transfer'), $byMethod('transfer'), '', ...$this->flt('method', 'transfer')],
                // قيود كاش الفواتير مالهاش وسيلة — من غير الكارت ده كروت الوسائل مابتقفلش على الإجمالي (٢٢/٩)
                [__('rpt.m_with_invoice'), $this->m($base->whereNull('method')->sum('credit')), '', ...$this->flt('method', 'none')],
            ],
            'columns' => [
                [__('rpt.c_date')], [__('rpt.c_client')], [__('rpt.c_source')], [__('rpt.c_memo')],
                [__('rpt.c_method')], [__('rpt.c_ref')], [__('rpt.c_cheque_bank')], [__('rpt.c_cheque_due')],
                [__('rpt.k_amount'), 'num'],
            ],
            'rows' => $rows->map(fn ($t) => [
                $t->date instanceof \DateTimeInterface ? $t->date->format('Y-m-d') : (string) $t->date,
                $this->cClient($t->client),
                $this->lk(__('rpt.'.$srcKey($t)), $srcUrl($t)),
                (string) $t->memo,
                $t->method ? __('rpt.m_'.$t->method) : __('rpt.m_with_invoice'),
                // المرجع بيفلتر نفس التقرير عليه — كل تحصيلات الشيك/التحويل ده
                $this->lk($t->reference, $t->reference ? request()->fullUrlWithQuery(['q' => $t->reference, 'export' => null]) : null),
                // الشيك (٢٢/٩): بنكه وميعاده — الشيك اللي ميعاده لسه جاي فلوسه لسه مادخلتش
                (string) ($t->cheque_bank ?: '—'),
                $t->cheque_due ? substr((string) $t->cheque_due, 0, 10).(Carbon::parse($t->cheque_due)->isFuture() ? ' ⏳' : '') : '—',
                $this->m($t->credit),
            ])->all(),
            'totals' => ['', '', '', '', '', '', '', __('common.total'), $this->m($all->sum('credit'))],
        ];
    }

    // ═══════════════════ ٧. المرتجعات ═══════════════════

    private function rReturnsDocs(Request $r): array
    {
        [$a, $b] = $this->range($r);

        // ⚠️ المرتجع بيدخل الفترة بتاريخ قيده في كشف الحساب (٢١/٩)
        $q = ClientReturn::with(['client.group', 'user'])
            ->whereIn('id', Transaction::where('kind', 'return')->where('source_type', ClientReturn::class)
                ->whereBetween('date', [$a->toDateString(), $b->toDateString()])->select('source_id'))
            ->when($r->filled('user_id'), fn ($w) => $w->where('user_id', $r->integer('user_id')));

        $this->like($q, $r, ['number', 'client.name']);

        $all = (clone $q)->get(['id', 'grand_total', 'good_units', 'damaged_units']);
        $rows = $q->latest()->take(self::MAX_ROWS)->get();

        return [
            'filters' => ['range', 'rep', 'q'],
            'kpis' => [
                [__('uib.k_docs'), $this->f0($all->count()), '', $this->scr('ops.returns')],
                [__('rpt.k_returns'), $this->m($all->sum('grand_total')), 'neg', $this->scr('ops.returns')],
                [__('rpt.k_good'), $this->f0($all->sum('good_units')), '', $this->scr('ops.returns', ['condition' => 'good'])],
                [__('rpt.k_damaged'), $this->f0($all->sum('damaged_units')), 'neg', $this->scr('ops.returns', ['condition' => 'damaged'])],
            ],
            'columns' => [
                [__('rpt.c_date')], [__('rpt.c_number')], [__('rpt.c_client')],
                [__('rpt.c_rep')], [__('rpt.c_policy')],
                [__('rpt.k_good'), 'num'], [__('rpt.k_damaged'), 'num'], [__('rpt.k_amount'), 'num'],
            ],
            'rows' => $rows->map(fn ($d) => [
                $d->created_at->format('Y-m-d'),
                $this->lk($d->number, route('ops.returns.show', $d->id)),
                $this->cClient($d->client),
                $this->cRep($d->user),
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
        // ⚠️ `visibleTo` — عملاء فريق اللي فاتح التقرير بس (تدقيق ٢١/٩)
        $clients = Client::visibleTo(Client::query(), $r->user())->with(['group', 'channel', 'zone', 'rep'])
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

        // ═══ شروط السداد والمتأخر (٢٢/٩) — كانت في فورم العميل وكارت المتأخر بتاعه بس،
        // فمديونية بـ1.25 مليون من غير أيام سداد ماكانتش باينة في أي تقرير.
        // نفس `Client::overdue()` بتاعة صفحة العميل — فالرقم هنا هو الرقم هناك. ═══
        $od = $clients->mapWithKeys(fn ($c) => [$c->id => $c->overdue()]);

        if ($r->input('terms') === 'none') {
            $clients = $clients->filter(fn ($c) => ! $od[$c->id]['has_terms'] && $c->allowsCredit());
        } elseif ($r->input('terms') === 'overdue') {
            $clients = $clients->filter(fn ($c) => $od[$c->id]['amount'] > 0);
        } elseif ($r->input('terms') === 'withheld') {
            $clients = $clients->filter(fn ($c) => (float) $c->withheld > 0);
        }

        // آخر تحصيل لكل عميل — كويري مجمّع واحد
        $lastColl = Transaction::where('kind', 'collection')
            ->whereIn('client_id', $clients->pluck('id'))
            ->selectRaw('client_id, MAX(created_at) t')
            ->groupBy('client_id')->pluck('t', 'client_id');

        $payDays = $this->avgDaysToPay($clients->pluck('id')->all());
        $noTerms = $clients->filter(fn ($c) => ! $od[$c->id]['has_terms'] && $c->allowsCredit());
        $overdueSum = $clients->sum(fn ($c) => $od[$c->id]['amount']);

        $rows = $clients->map(function ($c) use ($lastColl, $od, $payDays) {
            $t = $lastColl->get($c->id);
            $o = $od[$c->id];

            return [
                $this->cClient($c),
                $c->channel?->displayName() ?? '—',
                $c->zone?->displayName() ?? '—',
                $this->cRep($c->rep),
                $this->m($c->balance),
                $c->paymentTermsLabel(),
                // «بدون أيام» للآجل اللي مالوش أيام سداد — دي اللي المتابعة لازم تكمّلها
                $o['has_terms'] ? $this->f0($c->paymentDays()) : ($c->allowsCredit() ? __('rpt.no_pay_days') : '—'),
                $o['amount'] > 0 ? $this->m($o['amount']) : '—',
                $o['amount'] > 0 && $o['days'] !== null ? $this->f0($o['days']) : '—',
                (float) $c->withheld > 0 ? $this->m($c->withheld) : '—',
                isset($payDays[$c->id]) ? $this->f0($payDays[$c->id]) : '—',
                $t ? Carbon::parse($t)->format('Y-m-d') : '—',
                $t ? $this->f0(Carbon::parse($t)->diffInDays(now())) : '—',
            ];
        })->values()->all();

        return [
            'filters' => ['channel', 'q'],
            'kpis' => [
                [__('rpt.k_clients'), $this->f0($clients->count()), '', $this->scr('erp.reports', ['tab' => 'aging'], false)],
                [__('rpt.k_balance'), $this->m($clients->sum('balance')), 'neg', $this->scr('erp.reports', ['tab' => 'aging'], false)],
                // أكبر مديونية بتفتح صاحبها (القايمة مرتبة بالرصيد)
                [__('rpt.k_max_debt'), $this->m($clients->max('balance')), '',
                    $clients->isNotEmpty() ? route('erp.clients.show', $clients->sortByDesc('balance')->first()->id) : null],
                [__('rpt.k_avg_debt'), $this->m($clients->avg('balance')), '', $this->scr('erp.reports', ['tab' => 'aging'], false)],
                [__('rpt.k_overdue'), $this->m($overdueSum), 'neg', ...$this->flt('terms', 'overdue')],
                [__('rpt.k_no_terms'), $this->f0($noTerms->count()).' · '.$this->m($noTerms->sum('balance')), 'mid', ...$this->flt('terms', 'none')],
                [__('rpt.k_withheld'), $this->m($clients->sum('withheld')), '', ...$this->flt('terms', 'withheld')],
            ],
            'columns' => [
                [__('rpt.c_client')], [__('rpt.c_channel')], [__('rpt.c_zone')],
                [__('rpt.c_rep')], [__('rpt.k_balance'), 'num'],
                [__('rpt.c_pay_terms')], [__('rpt.c_pay_days'), 'num'],
                [__('rpt.k_overdue'), 'num'], [__('rpt.c_overdue_days'), 'num'],
                [__('rpt.k_withheld'), 'num'], [__('rpt.c_avg_pay_days'), 'num'],
                [__('rpt.c_last_coll')], [__('rpt.c_days'), 'num'],
            ],
            'rows' => $rows,
            'totals' => [__('common.total'), '', '', '', $this->m($clients->sum('balance')), '', '',
                $this->m($overdueSum), '', $this->m($clients->sum('withheld')), '', '', ''],
        ];
    }

    // ═══════════════════ ٩. المناديب الشامل ═══════════════════

    private function rRepsOverview(Request $r): array
    {
        [$a, $b] = $this->range($r);

        $reps = User::fieldVisibleTo(User::whereIn('role', User::FIELD_WORK_ROLES), $r->user())->where('active', true)
            ->orderBy('name')->get();

        // ⚠️ من كشف الحساب (٢١/٩): فواتير + أوامر توريد + قيود، بتاريخ القيد
        $inv = \App\Services\SalesSource::docs($a, $b)
            ->selectRaw("user_id, COUNT(*) c, SUM(grand_total) g,
                SUM(CASE WHEN payment = 'cash' THEN grand_total ELSE 0 END) cash_g")
            ->groupBy('user_id')->get()->keyBy('user_id');

        // التحصيل الميداني — قيوده مصدرها الزيارة، والزيارة ليها مندوب
        // ⚠️ `transactions.created_at` مؤهّلة — الجوين مع visits خلّى
        // العمود ambiguous ورمى 500 (بلاغ ٢١/٨)
        $fieldColl = \App\Services\SalesSource::collections($a, $b)->where('src', 'visit')
            ->selectRaw('user_id uid, SUM(amount) g')->groupBy('user_id')->pluck('g', 'uid');

        $rets = \App\Services\SalesSource::returns($a, $b)
            ->selectRaw('user_id, SUM(amount) g')->groupBy('user_id')->pluck('g', 'user_id');

        $gifts = GiftHandout::whereBetween('created_at', [$a, $b])
            ->selectRaw('user_id, SUM(qty) q')->groupBy('user_id')->pluck('q', 'user_id');

        $vis = Visit::whereBetween('created_at', [$a, $b])
            ->selectRaw('user_id, COUNT(*) c, COUNT(DISTINCT client_id) cl')
            ->groupBy('user_id')->get()->keyBy('user_id');

        $rows = [];
        $T = ['c' => 0, 'g' => 0.0, 'fc' => 0.0, 'cash' => 0.0, 'ret' => 0.0, 'gift' => 0, 'vis' => 0, 'units' => 0, 'val' => 0.0];

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
                $this->cRep($rep),
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

            $T['c'] += $i->c ?? 0; $T['g'] += (float) ($i->g ?? 0); $T['fc'] += (float) ($fieldColl[$rep->id] ?? 0);
            $T['cash'] += $cash; $T['ret'] += (float) ($rets[$rep->id] ?? 0); $T['gift'] += $gifts[$rep->id] ?? 0;
            $T['vis'] += $v->c ?? 0; $T['units'] += $units; $T['val'] += $value;
        }

        return [
            'filters' => ['range'],
            'kpis' => [
                [__('rpt.k_reps'), $this->f0($reps->count()), '', $this->scr('erp.team', [], false)],
                [__('rpt.k_grand'), $this->m($inv->sum('g')), 'pos', $this->to('sales_by_rep')],
                [__('rpt.k_field_coll'), $this->m($fieldColl->sum()), '', $this->to('collections', ['source' => 'visit'])],
                [__('rpt.k_custody_val'), $this->m($T['val']), 'mid',
                    $this->scr('ops.vans', [], false)],
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
            // ⚠️ «العملاء» عدد مميز لكل مندوب — مابيتجمعش (نفس العميل عند أكتر من مندوب) (٢٢/٩)
            'totals' => [__('common.total'), '', $this->f0($T['c']), $this->m($T['g']), $this->m($T['fc']),
                $this->m($T['cash']), $this->m($T['ret']), $this->f0($T['gift']), $this->f0($T['vis']), '',
                $this->f0($T['units']), $this->m($T['val'])],
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

        // ═══ البعد عن لوكيشن العميل ومدة الزيارة (٢٢/٩) — اللوكيشنين متسجلين من زمان
        // ومحدش كان بيحسب المسافة: تلت الزيارات اللي ليها لوكيشن أبعد من 2 كم. ═══
        $dist = fn ($v) => ($v->lat && $v->lng && $v->client?->lat && $v->client?->lng)
            ? self::km((float) $v->lat, (float) $v->lng, (float) $v->client->lat, (float) $v->client->lng) : null;
        $mins = fn ($v) => ($v->checked_in_at && $v->checked_out_at)
            ? abs($v->checked_in_at->diffInMinutes($v->checked_out_at)) : null;
        $isFar = fn ($v) => ($d = $dist($v)) !== null && $d > self::FAR_KM;
        $isShort = fn ($v) => ($m = $mins($v)) !== null && $m < self::SHORT_MIN;

        $farCount = $rows->filter($isFar)->count();
        $shortCount = $rows->filter($isShort)->count();

        if ($r->input('flag') === 'far') {
            $rows = $rows->filter($isFar)->values();
        } elseif ($r->input('flag') === 'short') {
            $rows = $rows->filter($isShort)->values();
        }

        $withInvoice = Invoice::whereIn('visit_id', $rows->pluck('id'))
            ->distinct()->pluck('visit_id')->flip();

        $closed = $rows->whereNotNull('checked_out_at');
        // ⚠️ الفرق من الدخول للخروج (٢٢/٩) — بالعكس `diffInMinutes` بيرجّع سالب
        // فالمتوسط كان بيطلع «−45 دقيقة»
        $avgMin = $closed->whereNotNull('checked_in_at')->isEmpty() ? 0 : $closed->whereNotNull('checked_in_at')->avg(
            fn ($v) => abs($v->checked_in_at->diffInMinutes($v->checked_out_at)));

        return [
            'filters' => ['range', 'rep', 'q'],
            'kpis' => [
                [__('rpt.k_visits'), $this->f0($rows->count()), '', $this->scr('ops.visits')],
                [__('rpt.k_closed'), $this->f0($closed->count()), '', $this->scr('ops.visits')],
                [__('rpt.k_with_invoice'), $this->f0($withInvoice->count()), 'pos', $this->to('sales_docs')],
                [__('rpt.k_avg_min'), $this->f0($avgMin), '', $this->scr('ops.visits')],
                [__('rpt.k_far_visits', ['km' => self::FAR_KM]), $this->f0($farCount), 'neg', ...$this->flt('flag', 'far')],
                [__('rpt.k_short_visits', ['m' => self::SHORT_MIN]), $this->f0($shortCount), 'mid', ...$this->flt('flag', 'short')],
            ],
            'columns' => [
                [__('rpt.c_date')], [__('rpt.c_rep')], [__('rpt.c_client')],
                [__('rpt.c_in')], [__('rpt.c_out')], [__('rpt.c_minutes'), 'num'],
                [__('rpt.c_invoiced')], [__('rpt.c_distance'), 'num'], [__('rpt.c_flag')],
            ],
            'rows' => $rows->map(fn ($v) => [
                $v->created_at->format('Y-m-d'),
                $this->cRep($v->user),
                $this->cClient($v->client),
                $v->checked_in_at?->format('h:i A') ?? '—',
                $v->checked_out_at?->format('h:i A') ?? '—',
                $v->checked_out_at && $v->checked_in_at
                    ? $this->f0(abs($v->checked_in_at->diffInMinutes($v->checked_out_at))) : '—',
                $withInvoice->has($v->id) ? '✓' : '—',
                ($d = $dist($v)) === null ? '—' : ($d < 1 ? $this->f0($d * 1000).' '.__('rpt.u_m') : number_format($d, 1).' '.__('rpt.u_km')),
                trim(($isFar($v) ? '📍 '.__('rpt.flag_far') : '').' '.($isShort($v) ? '⚡ '.__('rpt.flag_short') : '')) ?: '—',
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
                // مفيش شاشة مستقلة للهدايا — أقرب تفصيل ليها عمود الهدايا في «المبيعات بالمندوب»
                [__('uib.k_times'), $this->f0($rows->count()), '', $this->to('sales_by_rep')],
                [__('rpt.k_qty'), $this->f0($rows->sum('qty')), 'mid', $this->to('sales_by_rep')],
            ],
            'columns' => [
                [__('rpt.c_date')], [__('rpt.c_rep')], [__('rpt.c_client')],
                [__('rpt.c_product')], [__('rpt.k_qty'), 'num'], [__('rpt.c_reason')],
            ],
            'rows' => $rows->map(fn ($g) => [
                $g->created_at->format('Y-m-d'),
                $this->cRep($g->rep),
                $this->cClient($g->client),
                $this->cProduct($g->product),
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

        $q = PurchaseOrder::with(['client.group', 'courier', 'items'])
            ->whereBetween('created_at', [$a, $b])
            ->when($r->filled('user_id'), fn ($w) => $w->where('assigned_to', $r->integer('user_id')))
            ->when($r->filled('status'), fn ($w) => $w->where('status', $r->input('status')));

        $this->like($q, $r, ['number', 'client.name']);

        $rows = $q->latest()->take(self::MAX_ROWS)->get();

        // ═══ مستوى الخدمة (٢٢/٩) — كان باين في صفحة الأمر الواحد بس: اتسلّم متأخر قد إيه،
        // اتسلّم كام في المية من المطلوب، وقعد عند العميل قد إيه. ═══
        $lateHours = fn ($p) => ($p->due_at && $p->delivered_at && $p->delivered_at->gt($p->due_at))
            ? (int) ceil($p->due_at->diffInMinutes($p->delivered_at) / 60) : null;
        $fill = function ($p) {
            $asked = (float) $p->items->sum('qty');

            return $p->status === 'delivered' && $asked > 0
                ? round($p->items->sum(fn ($i) => (float) ($i->delivered_qty ?? $i->qty)) * 100 / $asked, 1) : null;
        };

        if ($r->input('svc') === 'late') {
            $rows = $rows->filter(fn ($p) => $lateHours($p) !== null)->values();
        } elseif ($r->input('svc') === 'short') {
            $rows = $rows->filter(fn ($p) => ($f = $fill($p)) !== null && $f < 100)->values();
        }

        $open = $rows->whereIn('status', ['pending', 'arrived']);
        $delivered = $rows->where('status', 'delivered');
        $deliveredLate = $delivered->filter(fn ($p) => $lateHours($p) !== null);
        $askedAll = (float) $delivered->sum(fn ($p) => $p->items->sum('qty'));
        $gotAll = (float) $delivered->sum(fn ($p) => $p->items->sum(fn ($i) => (float) ($i->delivered_qty ?? $i->qty)));

        return [
            'filters' => ['range', 'rep', 'status', 'q'],
            'kpis' => [
                [__('uib.k_orders'), $this->f0($rows->count()), '', $this->scr('ops.pos')],
                [__('rpt.k_open'), $this->f0($open->count()).' · '.$this->m($open->sum('grand_total')), 'mid', $this->scr('ops.pos')],
                [__('rpt.k_delivered'), $this->f0($delivered->count()).' · '.$this->m($delivered->sum('grand_total')), 'pos', ...$this->flt('status', 'delivered')],
                [__('rpt.k_cancelled'), $this->f0($rows->where('status', 'cancelled')->count()), 'neg', ...$this->flt('status', 'cancelled')],
                [__('rpt.k_late'), $this->f0($rows->filter(fn ($p) => $p->isLate())->count()), 'neg', $this->scr('ops.pos')],
                [__('rpt.k_delivered_late'), $this->f0($deliveredLate->count()).' / '.$this->f0($delivered->count()), 'neg', ...$this->flt('svc', 'late')],
                [__('rpt.k_fill_rate'), $askedAll > 0 ? number_format($gotAll * 100 / $askedAll, 1).'%' : '—', '', ...$this->flt('svc', 'short')],
            ],
            'columns' => [
                [__('rpt.c_date')], [__('rpt.c_number')], [__('rpt.c_client')],
                [__('rpt.c_rep')], [__('common.status')], [__('rpt.c_due')],
                [__('rpt.c_delivered_at')], [__('rpt.c_late_hours'), 'num'], [__('rpt.c_fill'), 'num'],
                [__('rpt.c_onsite_min'), 'num'], [__('rpt.c_reason')], [__('rpt.k_amount'), 'num'],
            ],
            'rows' => $rows->map(fn ($p) => [
                $p->created_at->format('Y-m-d'),
                $this->lk($p->number, route('ops.pos.show', $p->id)),
                $this->cClient($p->client),
                $this->cRep($p->courier),
                $p->statusLabel().($p->isLate() ? ' ⏰' : ''),
                $p->due_at?->format('Y-m-d h:i A') ?? '—',
                $p->delivered_at?->format('Y-m-d h:i A') ?? '—',
                ($h = $lateHours($p)) === null ? '—' : $this->f0($h),
                ($f = $fill($p)) === null ? '—' : rtrim(rtrim(number_format($f, 1), '0'), '.').'%',
                $p->arrived_at && $p->delivered_at ? $this->f0(abs($p->arrived_at->diffInMinutes($p->delivered_at))) : '—',
                (string) ($p->abort_reason ?: $p->approval_note ?: '—'),
                $this->m($p->grand_total),
            ])->all(),
            'totals' => ['', '', '', '', '', '', '', '', '', '', __('common.total'), $this->m($rows->sum('grand_total'))],
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

        // ⚠️ `visibleTo` — عملاء فريق اللي فاتح التقرير بس (تدقيق ٢١/٩)
        $clients = Client::visibleTo(Client::query(), $r->user())->with(['group', 'channel', 'zone', 'rep'])
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
                $this->cClient($c),
                $c->channel?->displayName() ?? '—',
                $c->zone?->displayName() ?? '—',
                $this->cRep($c->rep),
                $t ? Carbon::parse($t)->format('Y-m-d') : __('rpt.never'),
                $t ? $this->f0(Carbon::parse($t)->diffInDays(now())) : '∞',
                $this->m($c->balance),
            ];
        })->values()->all();

        return [
            'filters' => ['days', 'channel', 'q'],
            'kpis' => [
                [__('rpt.k_clients'), $this->f0(count($rows)), 'neg', $this->scr('ops.journeys', [], false)],
                [__('rpt.k_balance'), $this->m($clients->sum('balance')), 'mid', $this->to('debts')],
            ],
            'columns' => [
                [__('rpt.c_client')], [__('rpt.c_channel')], [__('rpt.c_zone')],
                [__('rpt.c_rep')], [__('rpt.c_last_visit')], [__('rpt.c_days'), 'num'],
                [__('rpt.k_balance'), 'num'],
            ],
            'rows' => $rows,
            // الرصيد بس اللي بيتجمع — الأيام وتاريخ آخر زيارة لأ (٢٢/٩)
            'totals' => [__('common.total'), '', '', '', '', '', $this->m($clients->sum('balance'))],
        ];
    }

    // ═══════════════════ ١٤. العملاء الجدد ═══════════════════

    private function rNewClients(Request $r): array
    {
        [$a, $b] = $this->range($r);

        // ⚠️ `visibleTo` — عملاء فريق اللي فاتح التقرير بس (تدقيق ٢١/٩)
        $clients = Client::visibleTo(Client::query(), $r->user())->with(['group', 'channel', 'zone', 'rep'])
            ->whereBetween('created_at', [$a, $b])
            ->when($r->filled('channel_id'), fn ($w) => $w->where('channel_id', $r->integer('channel_id')))
            ->latest()->take(self::MAX_ROWS)->get();

        // ⚠️ من كشف الحساب (`clients.purchases`) — كان فواتير بس، فعميل الكي
        // أكاونت الجديد اللي شغله أوامر توريد كان بيطلع «صفر مبيعات» (٢١/٩)
        $sales = $clients->pluck('purchases', 'id');

        $rows = $clients->map(fn ($c) => [
            $c->created_at->format('Y-m-d'),
            $this->cClient($c),
            $c->channel?->displayName() ?? '—',
            $c->zone?->displayName() ?? '—',
            $this->cRep($c->rep),
            $this->m($sales[$c->id] ?? 0),
            $this->m($c->balance),
        ])->all();

        return [
            'filters' => ['range', 'channel'],
            'kpis' => [
                [__('rpt.k_clients'), $this->f0($clients->count()), 'pos', $this->scr('erp.clients', [], false)],
                [__('rpt.k_grand'), $this->m($sales->sum()), '', $this->to('sales_by_client')],
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

    // ═══════════════════ ١٥. صلاحية الرفوف (من جرد المنسقين) ═══════════════════

    /**
     * آخر جرد لكل صنف في كل فرع، مرتب بالأقرب انتهاءً. «خلال كام يوم»
     * بيتحكم فيها فلتر `days` (الافتراضي 60). الصنف اللي اتجرد من غير
     * تاريخ انتهاء مش بيظهر هنا — التقرير ده عن الصلاحية مش عن الكمية.
     */
    private function rShelfExpiry(Request $r): array
    {
        $days = max(1, min(365, $r->integer('days') ?: 60));
        $limit = today()->addDays($days)->toDateString();

        // آخر صف جرد لكل (فرع، صنف)
        $latest = \App\Models\ShelfCount::selectRaw('MAX(id) id')->groupBy('client_id', 'product_id');

        // جدول الجرد لسه متعملش (الملفات اترفعت قبل التحديث) → تقرير فاضي مش خطأ
        $ready = \App\Models\MerchVisit::countsReady();

        $rows = ! $ready ? collect() : \App\Models\ShelfCount::with(['client.group', 'client.channel', 'product', 'merchVisit.user'])
            ->whereIn('id', $latest)
            ->whereIn('client_id', Client::visibleTo(Client::query(), $r->user())->select('clients.id'))
            ->whereNotNull('expiry_date')->where('expiry_date', '<=', $limit)
            ->where('pieces', '>', 0)
            ->when($r->filled('channel_id'), fn ($w) => $w->whereHas('client',
                fn ($c) => $c->where('channel_id', $r->integer('channel_id'))))
            ->orderBy('expiry_date')->take(self::MAX_ROWS)->get();

        if ($ready && $r->filled('q')) {
            $s = mb_strtolower($r->string('q')->trim());
            $rows = $rows->filter(fn ($c) => str_contains(mb_strtolower(
                ($c->client?->fullName() ?? '').' '.($c->product?->displayName() ?? '')), $s));
        }

        $expired = $rows->filter(fn ($c) => $c->daysToExpiry() < 0);

        return [
            'filters' => ['days', 'channel', 'q'],
            // خانة الأيام بتفتح على 60 هنا (الافتراضي العام 14 بتاع «من غير زيارة»)
            'daysDefault' => 60,
            'daysLabel' => __('rpt.f_days_expiry'),
            'kpis' => [
                [__('rpt.k_lines'), $this->f0($rows->count()), '', $this->scr('ops.merch', [], false)],
                [__('rpt.k_expired'), $this->f0($expired->count()), 'neg', $this->scr('ops.merch', [], false)],
                [__('rpt.k_expired_pcs'), $this->f0($expired->sum('pieces')), 'neg', $this->scr('ops.merch', [], false)],
                [__('rpt.k_near_pcs'), $this->f0($rows->sum('pieces') - $expired->sum('pieces')), 'mid', $this->to('oos_frequency')],
            ],
            'columns' => [
                [__('rpt.c_client')], [__('rpt.c_channel')], [__('rpt.c_product')],
                [__('rpt.k_qty'), 'num'], [__('rpt.c_unit')], [__('rpt.k_pieces'), 'num'],
                [__('rpt.c_production')], [__('rpt.c_expiry')], [__('rpt.k_days_left'), 'num'],
                [__('rpt.c_counted_at')], [__('rpt.c_rep')],
            ],
            'rows' => $rows->map(fn ($c) => [
                $this->cClient($c->client),
                $c->client?->channel?->displayName() ?? '—',
                $this->cProduct($c->product),
                rtrim(rtrim(number_format((float) $c->qty, 2), '0'), '.'),
                __('stock.unit_'.$c->unit),
                $this->f0($c->pieces),
                $c->production_date?->format('Y-m-d') ?? '—',
                $c->expiry_date->format('Y-m-d'),
                $this->f0($c->daysToExpiry()),
                $c->created_at?->format('Y-m-d') ?? '—',
                $this->cRep($c->merchVisit?->user),
            ])->values()->all(),
            'totals' => [__('common.total'), '', '', '', '', $this->f0($rows->sum('pieces')), '', '', '', '', ''],
        ];
    }

    // ═══════════════════ ١٦. أداء المنسقين ═══════════════════

    /** لكل منسق في الفترة: زيارات، صور كاملة، بدون تصوير، جرد، اتنقل للرف، نواقص، ومتوسط المدة */
    private function rMerchPerformance(Request $r): array
    {
        [$a, $b] = $this->range($r);

        $team = User::fieldVisibleTo(User::query(), $r->user())->select('id');

        // `counts_count` و`no_photos` بيبقوا null قبل التحديث → الأعمدة دي بتطلع صفر
        $visits = \App\Models\MerchVisit::with(['user', 'refills'])
            ->when(\App\Models\MerchVisit::countsReady(), fn ($w) => $w->withCount('counts'))
            ->whereIn('user_id', $team)->whereBetween('checked_in_at', [$a, $b])
            ->when($r->filled('user_id'), fn ($w) => $w->where('user_id', $r->integer('user_id')))
            ->get()->groupBy('user_id');

        $T = ['v' => 0, 'full' => 0, 'np' => 0, 'cnt' => 0, 'moved' => 0, 'short' => 0, 'br' => 0];
        $rows = [];

        foreach ($visits as $list) {
            $done = $list->whereNotNull('checked_out_at');
            $full = $list->filter(fn ($v) => $v->photo_before !== null && $v->photo_after !== null)->count();
            $np = $list->where('no_photos', true)->count();
            $cnt = $list->where('counts_count', '>', 0)->count();
            $moved = (int) $list->sum(fn ($v) => $v->movedTotal());
            $short = (int) $list->sum(fn ($v) => $v->outOfStockCount());
            $branches = $list->pluck('client_id')->unique()->count();
            $mins = $done->map(fn ($v) => $v->minutes())->filter(fn ($m) => $m !== null);

            $rows[] = [
                $this->cRep($list->first()->user),
                $this->f0($list->count()), $this->f0($branches), $this->f0($full),
                $this->f0($np), $this->f0($cnt), $this->f0($moved), $this->f0($short),
                $mins->isEmpty() ? '—' : $this->f0($mins->avg()),
            ];

            $T['v'] += $list->count(); $T['full'] += $full; $T['np'] += $np; $T['cnt'] += $cnt;
            $T['moved'] += $moved; $T['short'] += $short; $T['br'] += $branches;
        }

        $merchQ = array_filter(['user' => $r->input('user_id')]);

        return [
            'filters' => ['range', 'rep'],
            'kpis' => [
                [__('rpt.k_visits'), $this->f0($T['v']), '', $this->scr('ops.merch', $merchQ)],
                [__('rpt.k_full_photos'), $this->f0($T['full']), 'pos', $this->scr('ops.merch', $merchQ)],
                [__('rpt.k_no_photos'), $this->f0($T['np']), 'neg', $this->scr('ops.merch', $merchQ)],
                [__('rpt.k_counted'), $this->f0($T['cnt']), '', $this->to('shelf_expiry')],
                [__('rpt.k_moved'), $this->f0($T['moved']), 'mid', $this->to('oos_frequency')],
            ],
            'columns' => [
                [__('rpt.c_rep')], [__('rpt.k_visits'), 'num'], [__('rpt.k_branches'), 'num'],
                [__('rpt.k_full_photos'), 'num'], [__('rpt.k_no_photos'), 'num'], [__('rpt.k_counted'), 'num'],
                [__('rpt.k_moved'), 'num'], [__('rpt.k_short'), 'num'], [__('rpt.k_avg_minutes'), 'num'],
            ],
            'rows' => $rows,
            // ⚠️ «الفروع» عدد مميز لكل منسق — جمعه بيعدّ الفرع الواحد أكتر من مرة، فخانته فاضية (٢٢/٩)
            'totals' => [__('common.total'), $this->f0($T['v']), '', $this->f0($T['full']),
                $this->f0($T['np']), $this->f0($T['cnt']), $this->f0($T['moved']), $this->f0($T['short']), ''],
        ];
    }

    // ═══════════════════ ١٧. العميل × الصنف ═══════════════════

    /** كل عميل اشترى إيه في الفترة: كمية وقيمة لكل صنف. فواتير + أوامر توريد مسلّمة. */
    private function rClientProducts(Request $r): array
    {
        [$a, $b] = $this->range($r);

        $visible = Client::visibleTo(Client::query(), $r->user())
            ->when($r->filled('channel_id'), fn ($w) => $w->where('channel_id', $r->integer('channel_id')))
            ->select('clients.id');

        $lines = \App\Services\SalesSource::lines($a, $b, $r->filled('user_id') ? [$r->integer('user_id')] : null)
            ->whereIn('client_id', $visible)
            ->selectRaw('client_id, product_id, SUM(qty) q, SUM(total) net, SUM(tax) tax, MAX(doc_at) last_at')
            ->groupBy('client_id', 'product_id')->get();

        $clients = Client::with('group')->whereIn('id', $lines->pluck('client_id')->unique())->get()->keyBy('id');
        $products = \App\Models\Product::whereIn('id', $lines->pluck('product_id')->unique())->get()->keyBy('id');
        $perClient = $lines->groupBy('client_id')->map(fn ($g) => (float) $g->sum(fn ($l) => $l->net + $l->tax));

        $rows = $lines->map(fn ($l) => [
            'cid' => $l->client_id, 'pid' => $l->product_id,
            'client' => $clients->get($l->client_id)?->fullName() ?? '—',
            'code' => $clients->get($l->client_id)?->code ?? '',
            'product' => $products->get($l->product_id)?->displayName() ?? '#'.$l->product_id,
            'pcode' => $products->get($l->product_id)?->code ?? '',
            'q' => (float) $l->q, 'net' => (float) $l->net, 'tax' => (float) $l->tax,
            'last' => substr((string) $l->last_at, 0, 10),
            'rank' => $perClient[$l->client_id] ?? 0,
        ]);

        if ($r->filled('q')) {
            $s = mb_strtolower($r->string('q')->trim());
            $rows = $rows->filter(fn ($x) => str_contains(mb_strtolower($x['client'].' '.$x['code'].' '.$x['product'].' '.$x['pcode']), $s));
        }

        // أكبر عميل الأول، وجوّه العميل أكبر صنف الأول
        $rows = $rows->sort(fn ($x, $y) => [$y['rank'], $x['client'], $y['net']] <=> [$x['rank'], $y['client'], $x['net']])
            ->take(self::MAX_ROWS)->values();

        return [
            'filters' => ['range', 'rep', 'channel', 'q'],
            'kpis' => [
                // ⚠️ العدّ على الـid — الكود الفاضي كان بيلمّ كل العملاء اللي من غير كود في واحد (٢٢/٩)
                [__('rpt.k_clients'), $this->f0($rows->pluck('cid')->unique()->count()), '', $this->to('sales_by_client')],
                [__('rpt.k_products'), $this->f0($rows->pluck('pid')->unique()->count()), '', $this->to('sales_by_product')],
                [__('rpt.k_qty'), $this->f0($rows->sum('q')), '', $this->to('sales_by_product')],
                [__('rpt.k_grand'), $this->m($rows->sum('net') + $rows->sum('tax')), 'pos', $this->to('sales_docs')],
            ],
            'columns' => [
                [__('common.code')], [__('rpt.c_client')], [__('rpt.c_product')], [__('rpt.k_qty'), 'num'],
                [__('rpt.k_net'), 'num'], [__('rpt.k_tax'), 'num'], [__('rpt.k_grand'), 'num'], [__('rpt.c_last_op')],
            ],
            'rows' => $rows->map(fn ($x) => [
                $this->lk($x['code'], $clients->has($x['cid']) ? route('erp.clients.show', $x['cid']) : null),
                $this->lk($x['client'], $clients->has($x['cid']) ? route('erp.clients.show', $x['cid']) : null),
                $this->lk($x['product'], $products->has($x['pid']) ? route('erp.products.show', $x['pid']) : null),
                $this->f0($x['q']),
                $this->m($x['net']), $this->m($x['tax']), $this->m($x['net'] + $x['tax']), $x['last'] ?: '—'])->all(),
            'totals' => [__('common.total'), '', '', $this->f0($rows->sum('q')), $this->m($rows->sum('net')),
                $this->m($rows->sum('tax')), $this->m($rows->sum('net') + $rows->sum('tax')), ''],
        ];
    }

    // ═══════════════════ ١٨. عملاء مبيعاتهم نزلت ═══════════════════

    /**
     * صافي مبيعات الفترة (بيع − مرتجع من كشف الحساب) مقابل الفترة اللي قبلها.
     * لو الفترة بتبدأ أول الشهر، المقارنة بنفس الأيام من الشهر اللي فات؛
     * غير كده بنفس عدد الأيام اللي قبلها مباشرة. الأكبر نزولاً فوق.
     */
    private function rSalesDecline(Request $r): array
    {
        [$a, $b] = $this->range($r);

        if ($a->day === 1) {
            $pa = $a->copy()->subMonthNoOverflow();
            $pb = $b->copy()->subMonthNoOverflow();
        } else {
            $len = (int) $a->copy()->startOfDay()->diffInDays($b->copy()->startOfDay()) + 1;
            $pb = $a->copy()->subDay();
            $pa = $pb->copy()->subDays($len - 1);
        }

        $net = fn ($x, $y) => Transaction::whereIn('kind', ['sale', 'return'])
            ->whereBetween('date', [$x->toDateString(), $y->toDateString()])
            ->selectRaw("client_id, SUM(CASE WHEN kind = 'sale' THEN debit ELSE -credit END) v")
            ->groupBy('client_id')->pluck('v', 'client_id');

        $cur = $net($a, $b);
        $prev = $net($pa, $pb);

        $clients = Client::visibleTo(Client::query(), $r->user())->with(['group', 'channel', 'rep'])
            ->whereIn('clients.id', $cur->keys()->merge($prev->keys())->unique())
            ->when($r->filled('channel_id'), fn ($w) => $w->where('channel_id', $r->integer('channel_id')))
            ->get();

        if ($r->filled('q')) {
            $s = mb_strtolower($r->string('q')->trim());
            $clients = $clients->filter(fn ($c) => str_contains(mb_strtolower($c->fullName().' '.$c->name_en.' '.$c->code), $s));
        }

        $rows = $clients->map(fn ($c) => [
            'c' => $c, 'prev' => (float) ($prev[$c->id] ?? 0), 'cur' => (float) ($cur[$c->id] ?? 0),
        ])->map(fn ($x) => $x + ['diff' => $x['cur'] - $x['prev']])
            ->sortBy('diff')->take(self::MAX_ROWS)->values();

        $down = $rows->where('diff', '<', 0);
        $lost = $rows->filter(fn ($x) => $x['prev'] > 0 && $x['cur'] <= 0);

        return [
            'filters' => ['range', 'channel', 'q'],
            'kpis' => [
                // فترة المقارنة بتفتح «المبيعات بالعميل» عليها
                [__('rpt.k_prev_period'), $pa->toDateString().' → '.$pb->toDateString(), '',
                    $this->to('sales_by_client', ['from' => $pa->toDateString(), 'to' => $pb->toDateString()])],
                [__('rpt.k_down_clients'), $this->f0($down->count()), 'neg', $this->to('sales_by_client')],
                [__('rpt.k_down_value'), $this->m(abs($down->sum('diff'))), 'neg', $this->to('sales_by_client')],
                [__('rpt.k_stopped'), $this->f0($lost->count()), 'mid', $this->to('inactive_clients')],
                [__('rpt.k_up_value'), $this->m($rows->where('diff', '>', 0)->sum('diff')), 'pos', $this->to('sales_by_client')],
            ],
            'columns' => [
                [__('rpt.c_client')], [__('rpt.c_channel')], [__('rpt.c_rep')],
                [__('rpt.k_prev_sales'), 'num'], [__('rpt.k_cur_sales'), 'num'],
                [__('rpt.k_diff'), 'num'], [__('rpt.k_change_pct'), 'num'],
            ],
            'rows' => $rows->map(fn ($x) => [
                $this->cClient($x['c']), $x['c']->channel?->displayName() ?? '—', $this->cRep($x['c']->rep),
                $this->m($x['prev']), $this->m($x['cur']), $this->m($x['diff']),
                $x['prev'] > 0 ? number_format($x['diff'] / $x['prev'] * 100, 1).'%' : '—',
            ])->all(),
            'totals' => [__('common.total'), '', '', $this->m($rows->sum('prev')), $this->m($rows->sum('cur')),
                $this->m($rows->sum('diff')), ''],
        ];
    }

    // ═══════════════════ ١٩. التارجت مقابل المحقق ═══════════════════

    /**
     * من شجرة التارجيت السنوي: الشركة والمديرين والمناديب، لشهور الفترة.
     * المحقق من `TargetProgress` (كشف الحساب + اليدوي) — نفس رقم شاشة التارجيت.
     * الفترة بتتقص على سنة تاريخ «إلى»، وبالشهور الكاملة.
     */
    private function rTargetVsActual(Request $r): array
    {
        [$a, $b] = $this->range($r);
        $year = (int) $b->year;
        $m1 = (int) ($a->year < $year ? 1 : $a->month);
        $m2 = (int) $b->month;

        $viewer = $r->user();
        $team = User::fieldVisibleTo(User::query(), $viewer)->pluck('id');

        $targets = \App\Models\Target::with(['months', 'user'])->where('year', $year)
            ->whereIn('kind', [\App\Models\Target::KIND_COMPANY, \App\Models\Target::KIND_MANAGER, \App\Models\Target::KIND_REP])
            ->get()
            // المدير بيشوف عقدته وعقد فريقه بس — مش الشركة ولا باقي المديرين
            ->filter(fn ($t) => $viewer?->role !== 'manager'
                || ($t->kind === \App\Models\Target::KIND_MANAGER && (int) $t->user_id === (int) $viewer->id)
                || ($t->kind === \App\Models\Target::KIND_REP && $team->contains($t->user_id)))
            ->when($r->filled('user_id'), fn ($c) => $c->where('user_id', $r->integer('user_id')));

        $order = [\App\Models\Target::KIND_COMPANY => 0, \App\Models\Target::KIND_MANAGER => 1, \App\Models\Target::KIND_REP => 2];
        $rows = [];
        // مجموع كل مستوى لوحده — عمرهم ما بيتجمعوا على بعض (الشركة ⊇ المديرين ⊇ المناديب)
        $K = [];

        foreach ($targets->sortBy(fn ($t) => [$order[$t->kind], $t->user?->name]) as $t) {
            $plan = (float) $t->months->filter(fn ($m) => $m->month >= $m1 && $m->month <= $m2)->sum('amount');
            $ach = 0.0;

            foreach (\App\Services\TargetProgress::achievedByMonth($t) as $mon => $v) {
                if ($mon >= $m1 && $mon <= $m2) {
                    $ach += (float) $v;
                }
            }

            $rows[] = [
                __('rpt.tk_'.$t->kind),
                $t->kind === \App\Models\Target::KIND_COMPANY ? __('rpt.tk_company')
                    : ($t->kind === \App\Models\Target::KIND_REP && $t->user
                        ? $this->lk($t->user->displayName(), route('erp.targets.annual.rep', ['user' => $t->user_id, 'year' => $year]))
                        : $this->cRep($t->user)),
                $this->m($plan), $this->m($ach), $this->m($ach - $plan),
                $plan > 0 ? number_format($ach / $plan * 100, 1).'%' : '—',
                $this->m($t->amount),
            ];

            $K[$t->kind] ??= ['t' => 0.0, 'a' => 0.0];
            $K[$t->kind]['t'] += $plan;
            $K[$t->kind]['a'] += $ach;
        }

        // ⚠️ الكروت وصف الإجمالي من **مستوى واحد** (٢٢/٩): المناديب لو ليهم تارجت،
        // وإلا الشركة، وإلا المديرين. كان مناديب بس — ومفيش تارجت مناديب متسجّل،
        // فالكروت كانت 0/0 وسطر الشركة تحتها 2.5 مليون.
        $level = collect([\App\Models\Target::KIND_REP, \App\Models\Target::KIND_COMPANY, \App\Models\Target::KIND_MANAGER])
            ->first(fn ($k) => ($K[$k]['t'] ?? 0) > 0)
            ?? collect(array_keys($K))->first();
        $T = $K[$level] ?? ['t' => 0.0, 'a' => 0.0];
        $totalLabel = match ($level) {
            \App\Models\Target::KIND_COMPANY => __('uib.tk_total_company'),
            \App\Models\Target::KIND_MANAGER => __('uib.tk_total_managers'),
            default => __('rpt.tk_reps_total'),
        };

        return [
            'filters' => ['range', 'rep'],
            'kpis' => [
                [__('rpt.k_months'), $year.' · '.$m1.' → '.$m2, '', route('erp.targets.annual', ['year' => $year])],
                [__('rpt.k_target').' · '.$totalLabel, $this->m($T['t']), '', route('erp.targets.annual', ['year' => $year])],
                [__('rpt.k_achieved'), $this->m($T['a']), 'pos', $this->to('sales_by_rep')],
                [__('rpt.k_achieved_pct'), $T['t'] > 0 ? number_format($T['a'] / $T['t'] * 100, 1).'%' : '—', 'mid',
                    route('erp.targets.annual', ['year' => $year])],
            ],
            'columns' => [
                [__('rpt.c_level')], [__('rpt.c_name')], [__('rpt.k_target'), 'num'], [__('rpt.k_achieved'), 'num'],
                [__('rpt.k_gap'), 'num'], [__('rpt.k_achieved_pct'), 'num'], [__('rpt.k_year_target'), 'num'],
            ],
            'rows' => $rows,
            // النسبة في كارت «نسبة التحقيق» فوق — صف الإجمالي فلوس بس (٢٢/٩)
            'totals' => [$totalLabel, '', $this->m($T['t']), $this->m($T['a']), $this->m($T['a'] - $T['t']), '', ''],
        ];
    }

    // ═══════════════════ ٢٠. تكرار النواقص ═══════════════════

    /** أنهي صنف بيخلص في أنهي فرع: من بنود ريفيل المنسقين في الفترة. */
    private function rOosFrequency(Request $r): array
    {
        [$a, $b] = $this->range($r);

        $team = User::fieldVisibleTo(User::query(), $r->user())->select('id');

        $agg = DB::table('shelf_refills')
            ->join('merch_visits', 'merch_visits.id', '=', 'shelf_refills.merch_visit_id')
            ->whereBetween('merch_visits.checked_in_at', [$a, $b])
            ->whereIn('merch_visits.user_id', $team)
            ->when($r->filled('user_id'), fn ($w) => $w->where('merch_visits.user_id', $r->integer('user_id')))
            ->selectRaw('merch_visits.client_id, shelf_refills.product_id, COUNT(*) seen,
                SUM(CASE WHEN shelf_refills.out_of_stock = 1 THEN 1 ELSE 0 END) oos,
                MAX(CASE WHEN shelf_refills.out_of_stock = 1 THEN merch_visits.checked_in_at END) last_oos,
                SUBSTRING_INDEX(GROUP_CONCAT(shelf_refills.store_qty ORDER BY merch_visits.checked_in_at DESC), ",", 1) store_last')
            ->groupBy('merch_visits.client_id', 'shelf_refills.product_id')
            ->havingRaw('oos > 0')->orderByDesc('oos')->limit(self::MAX_ROWS)->get();

        $clients = Client::visibleTo(Client::query(), $r->user())->with(['group', 'channel'])
            ->whereIn('clients.id', $agg->pluck('client_id')->unique())
            ->when($r->filled('channel_id'), fn ($w) => $w->where('channel_id', $r->integer('channel_id')))
            ->get()->keyBy('id');
        $products = \App\Models\Product::whereIn('id', $agg->pluck('product_id')->unique())->get()->keyBy('id');

        $rows = $agg->filter(fn ($x) => $clients->has($x->client_id));

        if ($r->filled('q')) {
            $s = mb_strtolower($r->string('q')->trim());
            $rows = $rows->filter(fn ($x) => str_contains(mb_strtolower(
                $clients[$x->client_id]->fullName().' '.($products->get($x->product_id)?->displayName() ?? '')), $s));
        }

        $merchQ = array_filter(['user' => $r->input('user_id')]);

        return [
            'filters' => ['range', 'rep', 'channel', 'q'],
            'kpis' => [
                [__('rpt.k_lines'), $this->f0($rows->count()), '', $this->scr('ops.merch', $merchQ)],
                [__('rpt.k_oos_times'), $this->f0($rows->sum('oos')), 'neg', $this->scr('ops.merch', $merchQ)],
                [__('rpt.k_branches'), $this->f0($rows->pluck('client_id')->unique()->count()), '', $this->to('merch_performance')],
                [__('rpt.k_products'), $this->f0($rows->pluck('product_id')->unique()->count()), '', $this->scr('ops.replenishments', [], false)],
            ],
            'columns' => [
                [__('rpt.c_client')], [__('rpt.c_channel')], [__('rpt.c_product')],
                [__('rpt.k_oos_times'), 'num'], [__('rpt.k_seen_times'), 'num'], [__('rpt.k_oos_pct'), 'num'],
                [__('rpt.c_last_oos')], [__('rpt.c_store_qty'), 'num'],
            ],
            'rows' => $rows->map(fn ($x) => [
                $this->cClient($clients[$x->client_id]),
                $clients[$x->client_id]->channel?->displayName() ?? '—',
                $this->cProduct($products->get($x->product_id), $products->has($x->product_id) ? null : '#'.$x->product_id),
                $this->f0($x->oos), $this->f0($x->seen), number_format($x->oos / max(1, $x->seen) * 100, 0).'%',
                $x->last_oos ? Carbon::parse($x->last_oos)->format('Y-m-d') : '—',
                // مخزن الفرع في آخر زيارة (٢٢/٩) — الرف فاضي والمخزن فيه بضاعة = مشكلة رص مش توريد
                $x->store_last === null || $x->store_last === '' ? '—' : $this->f0((float) $x->store_last),
            ])->values()->all(),
            'totals' => [__('common.total'), '', '', $this->f0($rows->sum('oos')), $this->f0($rows->sum('seen')), '', '', ''],
        ];
    }

    // ═══════════════════ ٢١. ربحية العميل والقناة (أدمن) ═══════════════════

    /**
     * صافي المبيعات − التكلفة لكل عميل. ⚠️ الصنف اللي تكلفته صفر بيطلّع
     * ربح 100% كدب — فعمود «مبيعات بلا تكلفة» بيقول قد إيه من رقم العميل
     * مش متغطي بتكلفة، والهامش بيتحسب على المتغطي بس.
     */
    private function rProfitability(Request $r): array
    {
        [$a, $b] = $this->range($r);

        $agg = \App\Services\SalesSource::lines($a, $b, $r->filled('user_id') ? [$r->integer('user_id')] : null)
            ->selectRaw('client_id, SUM(total) net, SUM(cost) cost,
                SUM(CASE WHEN cost > 0 THEN total ELSE 0 END) covered,
                SUM(CASE WHEN cost > 0 THEN 0 ELSE total END) uncosted')
            ->groupBy('client_id')->get()->keyBy('client_id');

        $clients = Client::visibleTo(Client::query(), $r->user())->with(['group', 'channel'])
            ->whereIn('clients.id', $agg->keys())
            ->when($r->filled('channel_id'), fn ($w) => $w->where('channel_id', $r->integer('channel_id')))
            ->get();

        if ($r->filled('q')) {
            $s = mb_strtolower($r->string('q')->trim());
            $clients = $clients->filter(fn ($c) => str_contains(mb_strtolower($c->fullName().' '.$c->name_en.' '.$c->code), $s));
        }

        $clients = $clients->sortByDesc(fn ($c) => (float) $agg[$c->id]->net)->take(self::MAX_ROWS);
        $T = ['net' => 0.0, 'cost' => 0.0, 'cov' => 0.0, 'unc' => 0.0];
        $rows = [];

        foreach ($clients as $c) {
            $x = $agg[$c->id];
            $profit = (float) $x->covered - (float) $x->cost;
            $T['net'] += $x->net; $T['cost'] += $x->cost; $T['cov'] += $x->covered; $T['unc'] += $x->uncosted;

            $rows[] = [
                $this->cClient($c), $c->channel?->displayName() ?? '—', $this->m($x->net), $this->m($x->cost),
                $this->m($profit), $x->covered > 0 ? number_format($profit / $x->covered * 100, 1).'%' : '—',
                $this->m($x->uncosted),
            ];
        }

        $profitT = $T['cov'] - $T['cost'];

        return [
            'filters' => ['range', 'rep', 'channel', 'q'],
            'kpis' => [
                [__('rpt.k_net'), $this->m($T['net']), '', $this->to('sales_by_client')],
                [__('rpt.k_cost'), $this->m($T['cost']), '', $this->to('sales_by_product')],
                [__('rpt.k_profit'), $this->m($profitT), 'pos', $this->to('sales_by_product')],
                [__('rpt.k_margin'), $T['cov'] > 0 ? number_format($profitT / $T['cov'] * 100, 1).'%' : '—', 'mid', $this->to('sales_by_product')],
                // الأصناف اللي من غير تكلفة بتتصلّح من شاشة الأصناف
                [__('rpt.k_uncosted'), $this->m($T['unc']), 'neg', $this->scr('erp.stock', [], false)],
            ],
            'columns' => [
                [__('rpt.c_client')], [__('rpt.c_channel')], [__('rpt.k_net'), 'num'], [__('rpt.k_cost'), 'num'],
                [__('rpt.k_profit'), 'num'], [__('rpt.k_margin'), 'num'], [__('rpt.k_uncosted'), 'num'],
            ],
            'rows' => $rows,
            // الهامش الإجمالي في الكارت فوق — صف الإجمالي فلوس بس (٢٢/٩)
            'totals' => [__('common.total'), '', $this->m($T['net']), $this->m($T['cost']), $this->m($profitT),
                '', $this->m($T['unc'])],
        ];
    }

    // ═══════════════════ ٢٢. مطابقة المبيعات والتحصيل (أدمن) ═══════════════════

    /**
     * السيستم كله بقى بيقرا من **كشف الحساب** (قرار المالك ٢١/٩). التقرير ده
     * بيقارن كشف الحساب بإجمالي **المستندات** (الحساب القديم بتاع الداشبورد)
     * وبيفرد الفرق سطر سطر بسببه — عشان أي مستند مالوش قيد يبان ويتصلّح:
     *
     *   consignment  أمر توريد لعميل أمانة — قيده `consignment` مش بيع، فمش مديونية
     *   no_entry     مستند مالوش قيد بيع خالص
     *   other_date   المستند في الفترة وقيده بتاريخ بره الفترة
     *   amount       القيد بقيمة غير إجمالي المستند (تسليم جزئي مثلاً)
     *   ledger_only  قيد بيع في الفترة ومستنده بره الفترة أو مالوش مستند
     *   coll_date    قيد تحصيل اتسجّل في الفترة وتاريخه بره الفترة (أو العكس)
     *
     * قراءة بس — مفيش أي تعديل. الحل بيتقرر بعد ما السبب يبان بالأرقام.
     */
    private function rSalesReconcile(Request $r): array
    {
        [$a, $b] = $this->range($r);
        $day = [$a->toDateString(), $b->toDateString()];
        $types = ['invoice' => Invoice::class, 'po' => PurchaseOrder::class];

        // ⚠️ المستندات الخام عن قصد (مش `SalesSource`) — ده الطرف التاني في المقارنة
        $docs = DB::table('invoices')->whereBetween('created_at', [$a, $b])
            ->selectRaw("'invoice' AS kind, id AS doc_id, client_id, created_at AS doc_at, grand_total")
            ->unionAll(DB::table('purchase_orders')->where('status', 'delivered')->whereBetween('delivered_at', [$a, $b])
                ->selectRaw("'po' AS kind, id AS doc_id, client_id, delivered_at AS doc_at, grand_total"))
            ->get();
        $docsTotal = (float) $docs->sum('grand_total');

        // كل قيود البيع/الأمانة المربوطة بمستندات الفترة — أياً كان تاريخها
        $entries = collect();
        foreach ($types as $kind => $class) {
            foreach ($docs->where('kind', $kind)->pluck('doc_id')->chunk(500) as $ids) {
                $entries = $entries->concat(Transaction::where('source_type', $class)
                    ->whereIn('source_id', $ids)->whereIn('kind', ['sale', 'consignment'])
                    ->get(['id', 'source_type', 'source_id', 'kind', 'date', 'debit']));
            }
        }
        $byDoc = $entries->groupBy(fn ($t) => $t->source_type.'#'.$t->source_id);

        $ledger = Transaction::where('kind', 'sale')->whereBetween('date', $day)
            ->get(['id', 'client_id', 'source_type', 'source_id', 'date', 'debit', 'memo']);
        $ledgerTotal = (float) $ledger->sum('debit');

        $numbers = [
            'invoice' => Invoice::whereIn('id', $docs->where('kind', 'invoice')->pluck('doc_id'))->pluck('number', 'id'),
            'po' => PurchaseOrder::whereIn('id', $docs->where('kind', 'po')->pluck('doc_id'))->pluck('number', 'id'),
        ];

        $rows = collect();
        $sum = ['consignment' => 0.0, 'no_entry' => 0.0, 'other_date' => 0.0, 'amount' => 0.0, 'ledger_only' => 0.0, 'coll_date' => 0.0];
        $docKeys = [];

        foreach ($docs as $d) {
            $key = $types[$d->kind].'#'.$d->doc_id;
            $docKeys[$key] = true;
            $list = $byDoc->get($key) ?? collect();
            $sale = $list->where('kind', 'sale');
            $g = (float) $d->grand_total;
            $base = [$d->kind, $numbers[$d->kind][$d->doc_id] ?? '#'.$d->doc_id, $d->client_id, substr((string) $d->doc_at, 0, 10), $g];
            // [9] لينك المستند
            $docUrl = route($d->kind === 'invoice' ? 'ops.invoice' : 'ops.pos.show', $d->doc_id);

            if ($sale->isEmpty()) {
                $why = $list->where('kind', 'consignment')->isNotEmpty() ? 'consignment' : 'no_entry';
                $sum[$why] += $g;
                $rows->push([...$base, $why, '', 0.0, $g, $docUrl]);

                continue;
            }

            $in = $sale->filter(fn ($t) => $t->date?->toDateString() >= $day[0] && $t->date?->toDateString() <= $day[1]);
            $inSum = (float) $in->sum('debit');

            if ($in->isEmpty()) {
                $sum['other_date'] += $g;
                $rows->push([...$base, 'other_date', $sale->first()->date?->toDateString() ?? '', 0.0, $g, $docUrl]);
            } elseif (abs($inSum - $g) > 0.009) {
                $sum['amount'] += $g - $inSum;
                $rows->push([...$base, 'amount', $in->first()->date?->toDateString() ?? '', $inSum, $g - $inSum, $docUrl]);
            }
        }

        // قيود بيع في الفترة مالهاش مستند في الفترة — بتزوّد كشف الحساب عن الداشبورد
        foreach ($ledger as $t) {
            if ($t->source_type !== null && isset($docKeys[$t->source_type.'#'.$t->source_id])) {
                continue;
            }

            $sum['ledger_only'] += (float) $t->debit;
            $rows->push([$t->source_type ? class_basename($t->source_type) : 'entry', \Illuminate\Support\Str::limit((string) $t->memo, 40),
                $t->client_id, '', 0.0, 'ledger_only', $t->date?->toDateString() ?? '', (float) $t->debit, -(float) $t->debit,
                match ($t->source_type) {
                    Invoice::class => route('ops.invoice', $t->source_id),
                    PurchaseOrder::class => route('ops.pos.show', $t->source_id),
                    default => null,
                }]);
        }

        // التحصيل: الداشبورد بتاريخ التسجيل (`created_at`) وكشف الحساب بتاريخ القيد (`date`)
        $collDash = (float) Transaction::where('kind', 'collection')->whereBetween('created_at', [$a, $b])->sum('credit');
        $collLedger = (float) Transaction::where('kind', 'collection')->whereBetween('date', $day)->sum('credit');

        $collOff = Transaction::where('kind', 'collection')
            ->where(fn ($w) => $w
                ->where(fn ($x) => $x->whereBetween('created_at', [$a, $b])->whereNotBetween('date', $day))
                ->orWhere(fn ($x) => $x->whereBetween('date', $day)->whereNotBetween('created_at', [$a, $b])))
            ->orderByDesc('credit')->take(500)->get(['client_id', 'date', 'created_at', 'credit', 'memo', 'method']);

        foreach ($collOff as $t) {
            $inDash = $t->created_at >= $a && $t->created_at <= $b;
            $sum['coll_date'] += $inDash ? (float) $t->credit : -(float) $t->credit;
            $rows->push(['collection', \Illuminate\Support\Str::limit((string) $t->memo, 40), $t->client_id,
                $t->created_at?->toDateString() ?? '', $inDash ? (float) $t->credit : 0.0, 'coll_date',
                $t->date?->toDateString() ?? '', $inDash ? 0.0 : (float) $t->credit, $inDash ? (float) $t->credit : -(float) $t->credit]);
        }

        // كروت الأسباب بقت فلاتر على الجدول (٢٢/٩) — الكروت نفسها من كل الصفوف
        $reasons = array_keys($sum);
        if (in_array($r->input('reason'), $reasons, true)) {
            $rows = $rows->where(5, $r->input('reason'));
        }

        $names = Client::with('group')->whereIn('id', $rows->pluck(2)->unique())->get()->keyBy('id');
        $rows = $rows->sortByDesc(fn ($x) => abs($x[8]))->take(self::MAX_ROWS)->values();

        return [
            'filters' => ['range'],
            'extraSelects' => [
                'reason' => [__('rpt.rc_reason'), __('ui.all_of', ['x' => __('uib.reasons')]),
                    collect($reasons)->mapWithKeys(fn ($k) => [$k => __('rpt.rc_'.$k)])->all()],
            ],
            'kpis' => [
                [__('rpt.rc_docs_total'), $this->m($docsTotal), '', $this->scr('ops.invoices')],
                [__('rpt.rc_ledger_total'), $this->m($ledgerTotal), '', $this->to('sales_by_client')],
                [__('rpt.k_diff'), $this->m($docsTotal - $ledgerTotal), 'neg', request()->fullUrlWithQuery(['reason' => null, 'export' => null])],
                [__('rpt.rc_consignment'), $this->m($sum['consignment']), 'mid', ...$this->flt('reason', 'consignment')],
                [__('rpt.rc_no_entry'), $this->m($sum['no_entry']), 'neg', ...$this->flt('reason', 'no_entry')],
                [__('rpt.rc_other_date'), $this->m($sum['other_date']), 'mid', ...$this->flt('reason', 'other_date')],
                [__('rpt.rc_amount'), $this->m($sum['amount']), 'mid', ...$this->flt('reason', 'amount')],
                [__('rpt.rc_ledger_only'), $this->m($sum['ledger_only']), 'mid', ...$this->flt('reason', 'ledger_only')],
                [__('rpt.rc_coll_dash'), $this->m($collDash), '', ...$this->flt('reason', 'coll_date')],
                [__('rpt.rc_coll_ledger'), $this->m($collLedger), '', $this->to('collections')],
            ],
            'columns' => [
                [__('rpt.c_doc_type')], [__('rpt.c_doc')], [__('rpt.c_client')], [__('rpt.rc_doc_date')],
                [__('rpt.rc_doc_amount'), 'num'], [__('rpt.rc_reason')], [__('rpt.rc_entry_date')],
                [__('rpt.rc_entry_amount'), 'num'], [__('rpt.k_diff'), 'num'],
            ],
            'rows' => $rows->map(fn ($x) => [
                __('rpt.rc_kind_'.(in_array($x[0], ['invoice', 'po', 'collection'], true) ? $x[0] : 'entry')),
                $this->lk((string) $x[1], $x[9] ?? null),
                $names->has($x[2]) ? $this->cClient($names->get($x[2])) : '#'.$x[2], $x[3], $this->m($x[4]),
                __('rpt.rc_'.$x[5]), $x[6], $this->m($x[7]), $this->m($x[8]),
            ])->all(),
            'totals' => [__('common.total'), '', '', '', '', '', '', '', $this->m($rows->sum(fn ($x) => $x[8]))],
        ];
    }

    // ═══════════════════════════════════════════════════════════════
    // تقارير ٢٢ سبتمبر ٢٠٢٦ — أرقام كانت في الداتا ومالهاش شاشة
    // ═══════════════════════════════════════════════════════════════

    /** الزيارة أبعد من كده عن لوكيشن العميل = «بعيدة»، وأقصر من كده = «خاطفة» */
    private const FAR_KM = 2;

    private const SHORT_MIN = 2;

    /** المسافة بالكيلومتر بين نقطتين (Haversine) */
    private static function km(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $h = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 6371 * 2 * asin(min(1, sqrt($h)));
    }

    /**
     * متوسط أيام السداد لكل عميل — FIFO: كل تحصيل بيقفل أقدم مبيعات مفتوحة،
     * والمتوسط موزون بالمبلغ. المرتجع بيقفل زي التحصيل بس مش بيتحسب «سداد».
     *
     * @param  list<int>  $clientIds
     * @return array<int, float>
     */
    private function avgDaysToPay(array $clientIds): array
    {
        if ($clientIds === []) {
            return [];
        }

        $byClient = [];

        foreach (Transaction::whereIn('client_id', $clientIds)->whereIn('kind', ['sale', 'collection', 'return'])
            ->orderBy('date')->orderBy('id')->get(['client_id', 'kind', 'date', 'debit', 'credit']) as $t) {
            $byClient[$t->client_id][] = $t;
        }

        $out = [];

        foreach ($byClient as $cid => $list) {
            $open = [];          // [تاريخ البيع، المتبقي]
            $paid = 0.0;
            $weighted = 0.0;

            foreach ($list as $t) {
                if ($t->kind === 'sale') {
                    $open[] = [Carbon::parse($t->date), (float) $t->debit];

                    continue;
                }

                $left = (float) $t->credit;
                $at = Carbon::parse($t->date);

                while ($left > 0.005 && $open !== []) {
                    $take = min($left, $open[0][1]);

                    if ($t->kind === 'collection') {
                        $paid += $take;
                        $weighted += $take * max(0, $open[0][0]->diffInDays($at));
                    }

                    $open[0][1] -= $take;
                    $left -= $take;

                    if ($open[0][1] <= 0.005) {
                        array_shift($open);
                    }
                }
            }

            if ($paid > 0) {
                $out[$cid] = $weighted / $paid;
            }
        }

        return $out;
    }

    // ═══════════════════ ٢٣. الخصومات الممنوحة ═══════════════════

    /**
     * كل خصم خرج للعميل في الفترة: خصم الفاتورة (مخصوص / عقد) + البيع بأقل من سعر
     * القايمة في بنود الفواتير وأوامر التوريد. كان باين مستند بمستند بس.
     *
     * ⚠️ الفترة بتاريخ **قيد البيع** زي كل أرقام المبيعات، وبند الأمر بالكمية المسلَّمة.
     */
    private function rDiscountsGiven(Request $r): array
    {
        [$a, $b] = $this->range($r);
        $day = [$a->toDateString(), $b->toDateString()];
        $repId = $r->filled('user_id') ? $r->integer('user_id') : null;

        $sale = fn () => DB::table('transactions as t')->where('t.kind', 'sale')->whereBetween('t.date', $day);

        $inv = $sale()->join('invoices as i', fn ($j) => $j->on('i.id', '=', 't.source_id')->where('t.source_type', '=', Invoice::class))
            ->when($repId, fn ($w) => $w->where('i.user_id', $repId))
            ->selectRaw("t.client_id,
                SUM(CASE WHEN i.discount_source = 'contract' THEN i.discount ELSE 0 END) d_contract,
                SUM(CASE WHEN COALESCE(i.discount_source, '') <> 'contract' THEN i.discount ELSE 0 END) d_custom")
            ->groupBy('t.client_id')->get()->keyBy('client_id');

        $invLines = $sale()->join('invoices as i', fn ($j) => $j->on('i.id', '=', 't.source_id')->where('t.source_type', '=', Invoice::class))
            ->join('invoice_items as ii', 'ii.invoice_id', '=', 'i.id')
            ->when($repId, fn ($w) => $w->where('i.user_id', $repId))
            ->whereColumn('ii.list_price', '>', 'ii.price')
            ->selectRaw('t.client_id, SUM((ii.list_price - ii.price) * ii.qty) v')
            ->groupBy('t.client_id')->pluck('v', 'client_id');

        $poLines = $sale()->join('purchase_orders as p', fn ($j) => $j->on('p.id', '=', 't.source_id')->where('t.source_type', '=', PurchaseOrder::class))
            ->join('purchase_order_items as pi', 'pi.purchase_order_id', '=', 'p.id')
            ->when($repId, fn ($w) => $w->where('p.assigned_to', $repId))
            ->whereColumn('pi.list_price', '>', 'pi.price')
            ->selectRaw('t.client_id, SUM((pi.list_price - pi.price) * COALESCE(pi.delivered_qty, pi.qty)) v')
            ->groupBy('t.client_id')->pluck('v', 'client_id');

        $sales = $sale()->selectRaw('t.client_id, SUM(t.debit - COALESCE(t.tax, 0)) g')->groupBy('t.client_id')->pluck('g', 'client_id');

        $ids = $inv->filter(fn ($x) => $x->d_contract + $x->d_custom > 0)->keys()
            ->merge($invLines->keys())->merge($poLines->keys())->unique();

        $clients = Client::visibleTo(Client::query()->with(['group', 'channel']), $r->user())
            ->whereIn('clients.id', $ids)
            ->when($r->filled('channel_id'), fn ($w) => $w->where('channel_id', $r->integer('channel_id')))
            ->get();

        if ($r->filled('q')) {
            $s = mb_strtolower($r->string('q')->trim());
            $clients = $clients->filter(fn ($c) => str_contains(mb_strtolower($c->fullName().' '.$c->name_en), $s));
        }

        $T = ['custom' => 0.0, 'contract' => 0.0, 'il' => 0.0, 'pl' => 0.0, 'all' => 0.0, 'net' => 0.0];

        $rows = $clients->map(function ($c) use ($inv, $invLines, $poLines, $sales, &$T) {
            $custom = (float) ($inv->get($c->id)->d_custom ?? 0);
            $contract = (float) ($inv->get($c->id)->d_contract ?? 0);
            $il = (float) ($invLines[$c->id] ?? 0);
            $pl = (float) ($poLines[$c->id] ?? 0);
            // ⚠️ `$il` (بنود الفاتورة تحت سعر القايمة) هو **نفس** خصم الفاتورة متوزّع على بنودها —
            // بيتعرض للمراجعة بس ومايدخلش الإجمالي، وإلا الخصم يتحسب مرتين.
            $all = $custom + $contract + $pl;
            $net = (float) ($sales[$c->id] ?? 0);

            $T['custom'] += $custom; $T['contract'] += $contract; $T['il'] += $il;
            $T['pl'] += $pl; $T['all'] += $all; $T['net'] += $net;

            return ['sort' => $all, 'row' => [
                $this->cClient($c), $c->channel?->displayName() ?? '—', $this->m($net),
                $this->m($custom), $this->m($contract), $this->m($il), $this->m($pl), $this->m($all),
                // النسبة من السعر قبل الخصم: الخصم ÷ (الصافي + الخصم)
                $net + $all > 0 ? number_format($all * 100 / ($net + $all), 1).'%' : '—',
            ]];
        })->sortByDesc('sort')->take(self::MAX_ROWS)->pluck('row')->values()->all();

        return [
            'filters' => ['range', 'rep', 'channel', 'q'],
            'kpis' => [
                [__('rpt.k_clients'), $this->f0(count($rows)), '', $this->to('sales_by_client')],
                [__('rpt.k_disc_total'), $this->m($T['all']), 'neg', $this->to('sales_by_client')],
                [__('rpt.c_disc_custom'), $this->m($T['custom']), '', $this->scr('ops.invoices')],
                [__('rpt.c_disc_contract'), $this->m($T['contract']), '', $this->to('contract_schedule')],
                [__('rpt.c_disc_po_lines'), $this->m($T['pl']), '', $this->scr('ops.pos')],
                [__('rpt.k_disc_share'), $T['net'] + $T['all'] > 0 ? number_format($T['all'] * 100 / ($T['net'] + $T['all']), 1).'%' : '—', 'mid', $this->to('sales_by_channel')],
            ],
            'columns' => [
                [__('rpt.c_client')], [__('rpt.c_channel')], [__('rpt.k_net'), 'num'],
                [__('rpt.c_disc_custom'), 'num'], [__('rpt.c_disc_contract'), 'num'],
                [__('rpt.c_disc_inv_lines'), 'num'], [__('rpt.c_disc_po_lines'), 'num'],
                [__('rpt.k_disc_total'), 'num'], [__('rpt.k_disc_share'), 'num'],
            ],
            'rows' => $rows,
            'totals' => [__('common.total'), '', $this->m($T['net']), $this->m($T['custom']), $this->m($T['contract']),
                $this->m($T['il']), $this->m($T['pl']), $this->m($T['all']), ''],
        ];
    }

    // ═══════════════════ ٢٤. جودة المرتجعات ═══════════════════

    /** المرتجع سليم ولا تالف، لكل عميل وصنف، وسببه — بتاريخ قيد المرتجع */
    private function rReturnsQuality(Request $r): array
    {
        [$a, $b] = $this->range($r);

        $visible = Client::visibleTo(Client::query(), $r->user())
            ->when($r->filled('channel_id'), fn ($w) => $w->where('channel_id', $r->integer('channel_id')))
            ->select('clients.id');

        $lines = DB::table('return_items as ri')
            ->join('returns as rt', 'rt.id', '=', 'ri.return_id')
            ->join('transactions as t', 't.id', '=', 'rt.transaction_id')
            ->whereBetween('t.date', [$a->toDateString(), $b->toDateString()])
            ->whereIn('rt.client_id', $visible)
            ->when($r->filled('user_id'), fn ($w) => $w->where('rt.user_id', $r->integer('user_id')))
            ->when(in_array($r->input('condition'), ['good', 'damaged'], true), fn ($w) => $w->where('ri.condition', $r->input('condition')))
            ->selectRaw("rt.client_id, ri.product_id, ri.condition, SUM(ri.qty) qty, SUM(ri.total + COALESCE(ri.tax, 0)) v,
                COUNT(DISTINCT rt.id) docs, MAX(t.date) last_at,
                GROUP_CONCAT(DISTINCT NULLIF(TRIM(rt.note), '') SEPARATOR ' · ') notes")
            ->groupBy('rt.client_id', 'ri.product_id', 'ri.condition')
            ->orderByDesc('v')->limit(self::MAX_ROWS)->get();

        $clients = Client::with('group')->whereIn('id', $lines->pluck('client_id')->unique())->get()->keyBy('id');
        $products = \App\Models\Product::whereIn('id', $lines->pluck('product_id')->unique())->get()->keyBy('id');

        if ($r->filled('q')) {
            $s = mb_strtolower($r->string('q')->trim());
            $lines = $lines->filter(fn ($l) => str_contains(mb_strtolower(
                ($clients[$l->client_id] ?? null)?->fullName().' '.($products[$l->product_id] ?? null)?->displayName().' '.$l->notes), $s))->values();
        }

        $damaged = $lines->where('condition', 'damaged');
        $good = $lines->where('condition', '!=', 'damaged');
        $all = (float) $lines->sum('v');

        return [
            'filters' => ['range', 'rep', 'channel', 'q'],
            'kpis' => [
                [__('rpt.k_returns'), $this->m($all), 'neg', $this->to('returns_docs')],
                [__('rpt.k_ret_damaged'), $this->m($damaged->sum('v')).' · '.$this->f0($damaged->sum('qty')), 'neg', ...$this->flt('condition', 'damaged')],
                [__('rpt.k_ret_good'), $this->m($good->sum('v')).' · '.$this->f0($good->sum('qty')), 'pos', ...$this->flt('condition', 'good')],
                [__('rpt.k_ret_damaged_share'), $all > 0 ? number_format($damaged->sum('v') * 100 / $all, 1).'%' : '—', 'mid', $this->scr('ops.returns')],
            ],
            'columns' => [
                [__('rpt.c_client')], [__('rpt.c_product')], [__('rpt.c_condition')],
                [__('rpt.k_qty'), 'num'], [__('rpt.k_amount'), 'num'], [__('rpt.c_ret_docs'), 'num'],
                [__('rpt.c_last_op')], [__('rpt.c_reason')],
            ],
            'rows' => $lines->map(fn ($l) => [
                $this->cClient($clients[$l->client_id] ?? null),
                $this->cProduct($products[$l->product_id] ?? null),
                $l->condition === 'damaged' ? '🧯 '.__('rpt.cond_damaged') : '✓ '.__('rpt.cond_good'),
                $this->f0($l->qty), $this->m($l->v), $this->f0($l->docs),
                substr((string) $l->last_at, 0, 10), mb_substr((string) ($l->notes ?? ''), 0, 120) ?: '—',
            ])->all(),
            'totals' => [__('common.total'), '', '', $this->f0($lines->sum('qty')), $this->m($all), '', '', ''],
        ];
    }

    // ═══════════════════ ٢٥. رصيد الهدايا ═══════════════════

    /**
     * الهدايا اللي اتحمّلت على عربية كل مندوب مقابل اللي اتسجّل إنه اتسلّم لعملاء.
     * الفرق = هدايا خرجت من المخزن ومحدش سجّل راحت فين.
     */
    private function rGiftsBalance(Request $r): array
    {
        [$a, $b] = $this->range($r);
        $team = User::fieldVisibleTo(User::query(), $r->user())->pluck('id');

        $loaded = DB::table('pick_order_items as pi')->join('pick_orders as po', 'po.id', '=', 'pi.pick_order_id')
            ->where('pi.gift_qty', '>', 0)->whereIn('po.assigned_to', $team)
            ->whereBetween(DB::raw('COALESCE(po.handed_at, po.issued_at, po.created_at)'), [$a, $b])
            ->when($r->filled('user_id'), fn ($w) => $w->where('po.assigned_to', $r->integer('user_id')))
            ->selectRaw('po.assigned_to uid, SUM(pi.gift_qty) q, COUNT(DISTINCT po.id) picks, COUNT(DISTINCT pi.product_id) items')
            ->groupBy('po.assigned_to')->get()->keyBy('uid');

        $given = GiftHandout::whereBetween('created_at', [$a, $b])->whereIn('user_id', $team)
            ->when($r->filled('user_id'), fn ($w) => $w->where('user_id', $r->integer('user_id')))
            ->selectRaw('user_id uid, SUM(qty) q, COUNT(DISTINCT client_id) clients')
            ->groupBy('user_id')->get()->keyBy('uid');

        $users = User::whereIn('id', $loaded->keys()->merge($given->keys())->unique())->get()->keyBy('id');

        $rows = $users->map(function ($u) use ($loaded, $given) {
            $in = (float) ($loaded->get($u->id)->q ?? 0);
            $out = (float) ($given->get($u->id)->q ?? 0);

            return ['sort' => $in - $out, 'in' => $in, 'out' => $out, 'row' => [
                $this->cRep($u), $this->f0($loaded->get($u->id)->picks ?? 0), $this->f0($loaded->get($u->id)->items ?? 0),
                $this->f0($in), $this->f0($out), $this->f0($given->get($u->id)->clients ?? 0), $this->f0($in - $out),
                $in > 0 ? number_format($out * 100 / $in, 0).'%' : '—',
            ]];
        })->sortByDesc('sort')->values();

        $in = $rows->sum('in');
        $out = $rows->sum('out');

        return [
            'filters' => ['range', 'rep'],
            'kpis' => [
                [__('rpt.k_gifts_loaded'), $this->f0($in), '', $this->scr('wh.picks')],
                [__('rpt.k_gifts_given'), $this->f0($out), 'pos', $this->to('gifts_log')],
                [__('rpt.k_gifts_unlogged'), $this->f0($in - $out), 'neg', $this->to('gifts_log')],
                [__('rpt.k_gifts_logged_share'), $in > 0 ? number_format($out * 100 / $in, 0).'%' : '—', 'mid', $this->to('gifts_log')],
            ],
            'columns' => [
                [__('rpt.c_rep')], [__('rpt.c_gift_picks'), 'num'], [__('rpt.c_gift_items'), 'num'],
                [__('rpt.k_gifts_loaded'), 'num'], [__('rpt.k_gifts_given'), 'num'], [__('rpt.c_gift_clients'), 'num'],
                [__('rpt.k_gifts_unlogged'), 'num'], [__('rpt.k_gifts_logged_share'), 'num'],
            ],
            'rows' => $rows->pluck('row')->all(),
            'totals' => [__('common.total'), '', '', $this->f0($in), $this->f0($out), '', $this->f0($in - $out), ''],
        ];
    }

    // ═══════════════════ ٢٦. جدول مستحقات العقود ═══════════════════

    /**
     * كل بند فلوس في كل عقد ساري: المفروض يكون استحق قد إيه لحد النهارده، واتسجّل
     * منه قد إيه في «المستحقات»، والفرق. **قراءة بس** — مابيولّدش ولا بيقيّد حاجة.
     *
     *   نسبة دورية (خصم كمية)   Σ الفترات المكتملة: النسبة × صافي مسحوبات الفترة
     *   مبلغ دوري (إيجار/تسويق)  شهر بشهر: المبلغ ÷ شهور الفترة × الشهور اللي عدّت
     *   مبلغ مرة واحدة / متفق     المبلغ، من أول يوم في العقد
     *   لكل فاتورة / لكل فرع جديد / عند حدث   مشروط — بيتسجل وقت ما يحصل، فمالوش جدول
     *
     * ⚠️ البنود «البديلة» و«غير المؤكدة» بره الحساب (`counts()`) زي صفحة العقد.
     */
    private function rContractSchedule(Request $r): array
    {
        $today = today();
        $visible = Client::visibleTo(Client::query(), $r->user())->pluck('clients.id')->flip();

        $contracts = \App\Models\Contract::with(['contractClauses', 'client.group', 'group.clients', 'dues'])
            ->where('active', true)->get()
            ->filter(fn ($c) => ! $c->isExpired());

        $rows = [];
        $T = ['accrued' => 0.0, 'booked' => 0.0, 'settled' => 0.0];
        $conditional = 0;

        foreach ($contracts as $ct) {
            $clients = ($ct->group_id ? ($ct->group?->clients ?? collect()) : collect(array_filter([$ct->client])))
                ->filter(fn ($c) => $visible->has($c->id));

            if ($clients->isEmpty()) {
                continue;
            }

            if ($r->filled('channel_id') && ! $clients->contains(fn ($c) => (int) $c->channel_id === $r->integer('channel_id'))) {
                continue;
            }

            $party = $ct->group_id
                ? $this->lk($ct->group?->displayName(), $ct->group ? route('erp.groups.show', $ct->group_id) : null)
                : $this->cClient($ct->client);
            $end = $ct->ends_at && $ct->ends_at->lt($today) ? $ct->ends_at : $today;

            foreach ($ct->contractClauses as $cl) {
                if (! $cl->counts()) {
                    continue;
                }

                $months = ['monthly' => 1, 'quarterly' => 3, 'annual' => 12][$cl->basis] ?? null;
                $pct = (float) $cl->pct;
                $amount = (float) $cl->amount;
                $periods = 0;
                $nextDue = null;
                $accrued = null;

                if ($months !== null && $ct->starts_at !== null) {
                    for ($i = 0; $i < 200; $i++) {
                        $pe = $ct->starts_at->copy()->addMonthsNoOverflow($months * ($i + 1))->subDay();

                        if ($pe->gt($end)) {
                            $nextDue = $ct->ends_at && $pe->gt($ct->ends_at) ? null : $pe;
                            break;
                        }

                        $periods++;
                    }
                }

                if ($months !== null && $pct > 0 && $cl->kind === 'rebate' && $ct->starts_at !== null) {
                    $accrued = 0.0;

                    for ($i = 0; $i < $periods; $i++) {
                        $ps = $ct->starts_at->copy()->addMonthsNoOverflow($months * $i);
                        $pe = $ct->starts_at->copy()->addMonthsNoOverflow($months * ($i + 1))->subDay();

                        foreach ($clients as $c) {
                            $accrued += round(\App\Services\ContractDues::purchasesIn($c, $ps, $pe) * $pct, 2);
                        }
                    }
                } elseif ($months !== null && $amount > 0 && $ct->starts_at !== null) {
                    // ⚠️ المبلغ الدوري بيستحق **شهر بشهر** مش في آخر الفترة: رسم سنوي 120 ألف
                    // بعد 8 شهور = 80 ألف علينا. استنّى آخر السنة والتقرير يقول صفر لحد ديسمبر.
                    $elapsed = $ct->starts_at->lte($end) ? (int) floor($ct->starts_at->diffInMonths($end->copy()->addDay())) : 0;
                    $accrued = round($amount / $months * $elapsed, 2);
                } elseif ($amount > 0 && in_array($cl->basis, ['one_off', 'agreed'], true)) {
                    $accrued = $ct->starts_at === null || $ct->starts_at->lte($today) ? $amount : 0.0;
                    $nextDue = $ct->starts_at !== null && $ct->starts_at->gt($today) ? $ct->starts_at : null;
                }
                // ⚠️ «لكل فرع» = لكل فرع **جديد** بيتفتح — حدث مش جدول. ضربه في كل فروع
                // السلسلة كان بيطلّع رسوم افتتاح على فروع شغالة من سنين.

                $dues = $ct->dues->where('contract_clause_id', $cl->id)->where('status', '!=', \App\Models\ContractDue::STATUS_WAIVED);
                $booked = (float) $dues->sum('amount');
                $settled = (float) $dues->where('status', \App\Models\ContractDue::STATUS_SETTLED)->sum('amount');

                $gap = $accrued === null ? null : round($accrued - $booked, 2);

                if ($r->input('gap') === 'open' && ! ($gap !== null && $gap > 0.5)) {
                    continue;
                }
                if ($r->input('gap') === 'conditional' && $accrued !== null) {
                    continue;
                }

                // الإجماليات بعد الفلتر — الكروت والصف الأخير بيقفلوا على الجدول المعروض
                if ($accrued === null) {
                    $conditional++;
                } else {
                    $T['accrued'] += $accrued;
                }
                $T['booked'] += $booked;
                $T['settled'] += $settled;

                $rows[] = ['sort' => $gap ?? -1, 'row' => [
                    $this->lk($ct->number ?: '#'.$ct->id, route('erp.contracts.show', $ct->id)),
                    $party, $cl->kindLabel(), mb_substr($cl->displayLabel(), 0, 60), $cl->basisLabel(), $cl->valueLabel(),
                    $months !== null ? $this->f0($periods) : '—',
                    $accrued === null ? __('rpt.conditional') : $this->m($accrued),
                    $this->m($booked), $this->m($settled),
                    $gap === null ? '—' : $this->m($gap),
                    $nextDue?->format('Y-m-d') ?? '—',
                ]];
            }
        }

        if ($r->filled('q')) {
            $s = mb_strtolower($r->string('q')->trim());
            $rows = array_filter($rows, fn ($x) => str_contains(mb_strtolower(
                implode(' ', array_map(fn ($c) => self::cellText($c), $x['row']))), $s));
        }

        usort($rows, fn ($x, $y) => $y['sort'] <=> $x['sort']);
        $rows = array_slice(array_column($rows, 'row'), 0, self::MAX_ROWS);

        return [
            'filters' => ['channel', 'q'],
            'kpis' => [
                [__('rpt.k_cs_clauses'), $this->f0(count($rows)), '', $this->scr('erp.contracts', [], false)],
                [__('rpt.k_cs_accrued'), $this->m($T['accrued']), 'neg', $this->scr('erp.contracts', [], false)],
                [__('rpt.k_cs_booked'), $this->m($T['booked']), '', $this->scr('erp.dues', [], false)],
                [__('rpt.k_cs_settled'), $this->m($T['settled']), 'pos', $this->scr('erp.dues', ['status' => 'settled'], false)],
                [__('rpt.k_cs_gap'), $this->m($T['accrued'] - $T['booked']), 'neg', ...$this->flt('gap', 'open')],
                [__('rpt.k_cs_conditional'), $this->f0($conditional), 'mid', ...$this->flt('gap', 'conditional')],
            ],
            'columns' => [
                [__('rpt.c_contract')], [__('rpt.c_party')], [__('rpt.c_clause_kind')], [__('rpt.c_clause')],
                [__('rpt.c_basis')], [__('rpt.c_value')], [__('rpt.c_periods'), 'num'],
                [__('rpt.k_cs_accrued'), 'num'], [__('rpt.k_cs_booked'), 'num'], [__('rpt.k_cs_settled'), 'num'],
                [__('rpt.k_cs_gap'), 'num'], [__('rpt.c_next_due')],
            ],
            'rows' => $rows,
            'totals' => [__('common.total'), '', '', '', '', '', '', $this->m($T['accrued']), $this->m($T['booked']),
                $this->m($T['settled']), $this->m($T['accrued'] - $T['booked']), ''],
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
            'periodFrom' => $a->toDateString(),
            'periodTo' => $b->toDateString(),
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
