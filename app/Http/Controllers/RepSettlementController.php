<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\RepSettlement;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * تصفية المناديب — قفلة الحسابات اليومية (2026-08-06)
 * ═══════════════════════════════════════════════════════════════
 *
 * المحاسب بيقعد مع المندوب آخر اليوم:
 *  1. السيستم مطلّع كل فواتيره **من آخر تصفية** — كاش وآجل.
 *  2. النقدية المتوقعة = فواتير الكاش (بالإجمالي شامل الضريبة —
 *     نفس اللي العميل دفعه) − مرتجعات الكاش اللي ردّها نقدي.
 *  3. المحاسب بيستلم المبلغ ويسجّله — والفرق بيترحّل رصيد:
 *     موجب = عليه (مدين) · سالب = ليه (دائن).
 *
 * ⚠️ **مفيش لمس لليدجر بتاع العملاء هنا.** فلوس العملاء اتقيدت وقت
 * الفاتورة (sale + collection) — دي تصفية **نقدية المندوب** مع
 * الخزنة، مش حسابات عملاء. النافذة الزمنية (من آخر تصفية لحد لحظة
 * القفل) بتضمن إن مفيش فاتورة بتتحسب مرتين ولا بتضيع.
 */
class RepSettlementController extends Controller
{
    /** المناديب بأرصدتهم وأرقام الفترة المفتوحة — نظرة واحدة */
    public function index()
    {
        $reps = User::whereIn('role', ['sales_agent', 'driver'])
            ->where('active', true)->orderBy('name')->get();

        $rows = $reps->map(function (User $rep) {
            $figures = $this->openFigures($rep);

            return ['rep' => $rep] + $figures;
        });

        return view('erp.repclose', [
            'rows' => $rows,
            'recent' => RepSettlement::with(['user', 'creator'])
                ->latest('to_at')->limit(15)->get(),
        ]);
    }

    /** شاشة تصفية مندوب واحد — الفواتير بالتفصيل للمطابقة قدام المحاسب */
    public function show(User $user)
    {
        abort_unless(in_array($user->role, ['sales_agent', 'driver'], true), 404);

        $figures = $this->openFigures($user);

        return view('erp.repclose_show', [
            'rep' => $user,
            'invoices' => $figures['invoices'],
            'refundRows' => $figures['refund_rows'],
            // ⚠️ «الفلوس دي لمين» — المحاسب بيسأل السؤال ده في كل
            // تصفية، والإجابة كانت بتتطلع بالعين من 14 سطر فاتورة
            'cashByClient' => $this->byClient($figures['invoices'], 'cash'),
            'creditByClient' => $this->byClient($figures['invoices'], 'credit'),
        ] + $figures);
    }

    /** قفل التصفية — الأرقام بتتجمد والرصيد بيترحّل */
    public function store(Request $request, User $user)
    {
        abort_unless(in_array($user->role, ['sales_agent', 'driver'], true), 404);

        $data = $request->validate([
            'received' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $settlement = DB::transaction(function () use ($user, $data, $request) {
            // ⚠️ الأرقام بتتحسب جوه الترانزاكشن — فاتورة بتتسجل في نفس
            // اللحظة يا إما جوه النافذة يا إما في التصفية الجاية.
            $f = $this->openFigures($user);

            $received = round((float) $data['received'], 2);
            $balance = round($f['prev_balance'] + $f['expected'] - $received, 2);

            return RepSettlement::create([
                'number' => RepSettlement::nextNumber(),
                'user_id' => $user->id,
                'from_at' => $f['from_at'],
                'to_at' => $f['to_at'],
                'invoices_count' => $f['invoices']->count(),
                'cash_sales' => $f['cash_sales'],
                'credit_sales' => $f['credit_sales'],
                'cash_refunds' => $f['cash_refunds'],
                'expected' => $f['expected'],
                'prev_balance' => $f['prev_balance'],
                'received' => $received,
                'balance' => $balance,
                'note' => $data['note'] ?? null,
                'created_by' => $request->user()->id,

                // ⚠️ **لقطة البضاعة بتتجمّد مع الأرقام** (2026-08-08).
                // الورقة المطبوعة مستند بيتمضي — ولو قريناها من
                // العهدة الحية، فتحها بعد أسبوع كان بيوري أرقام
                // اليوم مش أرقام لحظة التوقيع.
                'goods_json' => $f['goods']['lines']->map(fn ($l) => [
                    'name' => $l['product']?->displayName() ?? '—',
                    'assigned' => $l['assigned'],
                    'cash_qty' => $l['cash_qty'], 'cash_value' => $l['cash_value'],
                    'credit_qty' => $l['credit_qty'], 'credit_value' => $l['credit_value'],
                    'gift' => $l['gift_given'],
                    'returned_in' => $l['returned_in'],
                    'remaining' => $l['remaining'],
                    'diff' => $l['diff'],
                ])->all(),
            ]);
        });

        return redirect()->route('erp.repclose.doc', $settlement)
            ->with('ok', __('settle.closed_ok', [
                'number' => $settlement->number,
                'balance' => number_format(abs((float) $settlement->balance), 2),
            ]));
    }

    /** ورقة التصفية — بتتطبع وتتمضي من المندوب والمحاسب */
    public function doc(RepSettlement $settlement)
    {
        $settlement->load(['user', 'creator']);

        return view('erp.repclose_doc', ['s' => $settlement]);
    }

    /**
     * أرقام الفترة المفتوحة لمندوب: من آخر تصفية لحد دلوقتي.
     *
     * النقدية المتوقعة = Σ فواتير كاش (grand_total — نفس عقيدة
     * الليدجر: اللي العميل دفعه فعلاً) − Σ قيود `refund` (مرتجع كاش
     * اتردّ نقدي) على زيارات المندوب في نفس النافذة.
     */
    private function openFigures(User $rep): array
    {
        $last = RepSettlement::lastFor($rep->id);
        $from = $last?->to_at;
        $now = now();

        $invoices = Invoice::with('client')
            ->where('user_id', $rep->id)
            ->when($from, fn ($q) => $q->where('created_at', '>', $from))
            ->where('created_at', '<=', $now)
            ->orderBy('created_at')
            ->get();

        $cashSales = round((float) $invoices->where('payment', 'cash')->sum('grand_total'), 2);
        $creditSales = round((float) $invoices->where('payment', '!=', 'cash')->sum('grand_total'), 2);

        // مرتجعات الكاش اللي المندوب ردّ قيمتها نقدي — قيود refund
        // مصدرها زيارات المندوب ده في النافذة
        $refundRows = Transaction::where('kind', 'refund')
            ->where('source_type', Visit::class)
            ->whereIn('source_id', Visit::where('user_id', $rep->id)->select('id'))
            ->when($from, fn ($q) => $q->where('created_at', '>', $from))
            ->where('created_at', '<=', $now)
            ->with('client')
            ->get();
        $cashRefunds = round((float) $refundRows->sum('debit'), 2);

        $expected = round($cashSales - $cashRefunds, 2);
        $prev = round((float) ($last?->balance ?? 0), 2);

        return [
            'from_at' => $from,
            'to_at' => $now,
            'invoices' => $invoices,
            'refund_rows' => $refundRows,
            'cash_sales' => $cashSales,
            'credit_sales' => $creditSales,
            'cash_refunds' => $cashRefunds,
            'expected' => $expected,
            'prev_balance' => $prev,
            'due_total' => round($prev + $expected, 2),
            'last' => $last,

            // ═══ الجانب التاني من التصفية: البضاعة (2026-08-08) ═══
            'goods' => $this->goodsReconciliation($rep, $from, $now, $invoices),
        ];
    }

    /**
     * ═══════════════════════════════════════════════════════════
     * مطابقة العهدة — بالقطع مش بالفلوس
     * ═══════════════════════════════════════════════════════════
     *
     * ⚠️ **التصفية كانت بتقفل الفلوس وتسيب البضاعة.** المحاسب كان
     * بيستلم كاش ويمضي، والعربية فيها بضاعة محدش عدّها — يعني
     * العجز مابيظهرش غير في الجرد الشهري، وساعتها محدش يعرف حصل
     * إمتى ولا مع مين.
     *
     * **المعادلة اللي لازم تقفل لكل صنف:**
     *
     *     المحمَّل = مباع كاش + مباع آجل + هدايا + مرتجع داخل + الباقي
     *
     * ⚠️ **المرتجع الداخل بيتحط في طرف اليمين مش بيتطرح.** البضاعة
     * اللي رجعت من العميل موجودة في العربية فعلاً — هي جزء من
     * «اللي لسه معاه»، مش نقص من المحمَّل. طرحها كان بيخلي كل
     * مرتجع يبان كأنه عجز.
     *
     * ⚠️ **الفرق ≠ صفر معناه عجز حقيقي.** مش خطأ حسابي: بضاعة خرجت
     * من العربية من غير فاتورة ولا هدية ولا مرتجع.
     *
     * @return array{lines: \Illuminate\Support\Collection, ...}
     */
    private function goodsReconciliation(User $rep, $from, $now, $invoices): array
    {
        // ⚠️ **كل عهد الفترة مش عهدة النهارده.** المندوب اللي مااتصفّاش
        // من 3 أيام عنده 3 عهد، وقراية الأخيرة بس كانت بتخفي بضاعة
        // يومين.
        $custodies = \App\Models\Custody::with(['items.product'])
            ->where('user_id', $rep->id)
            ->when($from, fn ($q) => $q->where('created_at', '>', $from))
            ->where('created_at', '<=', $now)
            ->get();

        // ═══ 1. المحمَّل والباقي — من بنود العهدة ═══
        $rows = [];

        foreach ($custodies as $c) {
            foreach ($c->items as $it) {
                $pid = $it->product_id;

                $rows[$pid] ??= [
                    'product' => $it->product,
                    'assigned' => 0, 'remaining' => 0, 'returned_in' => 0,
                    'gift_given' => 0,
                    'cash_qty' => 0, 'cash_value' => 0.0,
                    'credit_qty' => 0, 'credit_value' => 0.0,
                ];

                $rows[$pid]['assigned'] += (int) $it->assigned;
                $rows[$pid]['remaining'] += $it->remaining();
                $rows[$pid]['returned_in'] += (int) $it->returned_in;
                $rows[$pid]['gift_given'] += (int) $it->gift_given;
            }
        }

        // ═══ 2. المباع — من بنود الفواتير، مقسوم كاش/آجل ═══
        //
        // ⚠️ **من `invoice_items` مش من `custody_items.sold`.**
        // العمود `sold` بيجمع الاتنين مع بعض، والمحاسب محتاج يعرف
        // أنهي قطع خرجت بفلوس في إيده وأنهي خرجت مديونية.
        $items = \App\Models\InvoiceItem::whereIn('invoice_id', $invoices->pluck('id'))
            ->get()
            ->groupBy('invoice_id');

        $payOf = $invoices->pluck('payment', 'id');

        foreach ($items as $invoiceId => $lines) {
            $cash = ($payOf[$invoiceId] ?? 'cash') === 'cash';

            foreach ($lines as $l) {
                $pid = $l->product_id;

                // ⚠️ صنف اتباع ومش في العهدة = حالة شاذة لازم تبان،
                // مش تتبلع — بنعمله صف بمحمَّل صفر فالفرق بيطلع سالب
                $rows[$pid] ??= [
                    'product' => $l->product ?? \App\Models\Product::find($pid),
                    'assigned' => 0, 'remaining' => 0, 'returned_in' => 0,
                    'gift_given' => 0,
                    'cash_qty' => 0, 'cash_value' => 0.0,
                    'credit_qty' => 0, 'credit_value' => 0.0,
                ];

                $rows[$pid][$cash ? 'cash_qty' : 'credit_qty'] += (int) $l->qty;
                $rows[$pid][$cash ? 'cash_value' : 'credit_value'] +=
                    (float) $l->total + (float) $l->tax;
            }
        }

        // ═══ 3. الفرق لكل صنف ═══
        $lines = collect($rows)->map(function (array $r) {
            $accounted = $r['cash_qty'] + $r['credit_qty']
                + $r['gift_given'] + $r['returned_in'] + $r['remaining'];

            $r['accounted'] = $accounted;
            $r['diff'] = $r['assigned'] - $accounted;
            $r['cash_value'] = round($r['cash_value'], 2);
            $r['credit_value'] = round($r['credit_value'], 2);

            return $r;
        })->sortByDesc('assigned')->values();

        return [
            'lines' => $lines,
            'assigned' => (int) $lines->sum('assigned'),
            'cash_qty' => (int) $lines->sum('cash_qty'),
            'credit_qty' => (int) $lines->sum('credit_qty'),
            'gift_qty' => (int) $lines->sum('gift_given'),
            'returned_qty' => (int) $lines->sum('returned_in'),
            'remaining_qty' => (int) $lines->sum('remaining'),
            'diff_qty' => (int) $lines->sum('diff'),
            // ⚠️ القيم دي **شاملة الضريبة** — نفس عقيدة الليدجر
            // واللي العميل دفعه فعلاً، عشان تطابق أرقام الفلوس فوق
            'cash_value' => round($lines->sum('cash_value'), 2),
            'credit_value' => round($lines->sum('credit_value'), 2),
        ];
    }

    /**
     * تفصيلة «الفلوس دي لمين» — لكل عميل: كام فاتورة وكام قطعة وبكام.
     *
     * ⚠️ **مجمّعة بالعميل مش بالفاتورة.** المحاسب بيسأل «الـ2,590
     * آجل دول على مين؟» — وقايمة 14 فاتورة مابتجاوبش، بينما 3 عملاء
     * بأرقامهم بتجاوب في ثانية.
     */
    private function byClient($invoices, string $payment): \Illuminate\Support\Collection
    {
        $rows = $invoices->filter(fn ($i) => $payment === 'cash'
            ? $i->payment === 'cash'
            : $i->payment !== 'cash');

        $qtyOf = \App\Models\InvoiceItem::whereIn('invoice_id', $rows->pluck('id'))
            ->selectRaw('invoice_id, SUM(qty) q')
            ->groupBy('invoice_id')
            ->pluck('q', 'invoice_id');

        return $rows->groupBy('client_id')->map(fn ($g) => [
            'client' => $g->first()->client,
            'count' => $g->count(),
            'qty' => (int) $g->sum(fn ($i) => $qtyOf[$i->id] ?? 0),
            'total' => round((float) $g->sum('grand_total'), 2),
        ])->sortByDesc('total')->values();
    }
}
