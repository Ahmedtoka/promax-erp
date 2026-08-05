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
        ];
    }
}
