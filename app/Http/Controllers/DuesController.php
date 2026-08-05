<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ContractDue;
use App\Services\ContractDues;
use Illuminate\Http\Request;

/**
 * ═══════════════════════════════════════════════════════════════
 * مستحقات العقود — الخصومات الدورية وحجز الضمان والالتزامات
 * ═══════════════════════════════════════════════════════════════
 *
 * الشاشة دي بتجاوب على سؤال واحد: **إحنا مدينين بكام دلوقتي بسبب
 * بنود العقود، ولمين؟** الأرقام دي كانت بتتحسب على ورق بره السيستم.
 *
 * ⚠️ العرض بيفصل بين تلات حاجات مختلفة تماماً:
 *   المستحق    خصومات دورية اتحسبت ولسه ماترحّلتش لكشف الحساب
 *   المحجوز    فلوسنا عند العميل كضمان — مش مديونية جديدة، جزء من الرصيد
 *   الالتزامات رسوم ثابتة على العقد (تكويد/افتتاحات/مجلات) سنوياً
 * خلطهم في رقم واحد بيدي صورة غلط.
 */
class DuesController extends Controller
{
    public function index(Request $request)
    {
        // ⚠️ سكوب التشانل مانجر (2026-08-05): استحقاقات عملائه بس —
        // نفس الفلتر على الجدول والـKPIs عشان مايختلفوش (نطاق واحد).
        $u = auth()->user();
        $vis = fn ($q, string $col = 'client_id') => $u?->role === 'manager'
            ? $q->whereIn($col, Client::visibleTo(Client::query(), $u)->select('id'))
            : $q;

        $q = $vis(ContractDue::with(['client', 'contract', 'clause']));

        $filters = $request->only(['status', 'client', 'kind']);

        // الافتراضي: المستحق بس — ده اللي محتاج قرار
        $status = $request->string('status')->value() ?: ContractDue::STATUS_DUE;
        if ($status !== 'all') {
            $q->where('status', $status);
        }
        if ($clientId = $request->integer('client')) {
            $q->where('client_id', $clientId);
        }
        if ($kind = $request->string('kind')->value()) {
            $q->where('kind', $kind);
        }

        $dues = $q->orderBy('status')
            ->orderByDesc('period_end')
            ->orderByDesc('amount')
            ->paginate(50)
            ->withQueryString();

        // ⚠️ الإجماليات على الكل مش على الصفحة المعروضة — وبنفس السكوب
        $allDue = $vis(ContractDue::due());
        $allSettled = $vis(ContractDue::settled());

        // العملاء اللي عندهم فلوسنا محجوزة
        $withheld = Client::visibleTo(Client::where('withheld', '>', 0))
            ->with('contract')
            ->orderByDesc('withheld')
            ->get();

        return view('erp.dues', [
            'dues' => $dues,
            // ⚠️ الإسناد مش الجمع: لو status جه فاضي، `+` مابيستبدلوش
            // فالقايمة بتفضل من غير اختيار رغم إن الفلتر شغّال.
            'filters' => array_merge($filters, ['status' => $status]),
            'kpi' => [
                'due_count' => (clone $allDue)->count(),
                'due_amount' => (float) (clone $allDue)->sum('amount'),
                'settled_amount' => (float) (clone $allSettled)->sum('amount'),
                'clients' => (clone $allDue)->distinct('client_id')->count('client_id'),
                'withheld_total' => (float) Client::visibleTo(Client::query())->sum('withheld'),
                'withheld_clients' => $withheld->count(),
            ],
            // أكبر العملاء استحقاقاً — ده اللي بيهم القرار
            'byClient' => (clone $allDue)
                ->selectRaw('client_id, COUNT(*) as n, SUM(amount) as total')
                ->groupBy('client_id')
                ->orderByDesc('total')
                ->with('client')
                ->take(12)
                ->get(),
            'withheld' => $withheld,
            // ⚠️ بنود خصم دوري من غير توقيت محدّد في العقد. مابنخترعش لها
            // دورة — الاستحقاق بيتحسب على فترة، وفترة متخيّلة = رقم متخيّل.
            // بنعرضها عشان صاحب القرار يحدّد توقيتها من صفحة العقد وساعتها
            // تدخل الحساب تلقائي. لحد كده هي داخلة في "إجمالي الخصم الحقيقي"
            // بس مش بتولّد استحقاق.
            'undated' => \App\Models\ContractClause::with('contract.client')
                ->where('kind', 'rebate')
                ->whereNotIn('basis', ['monthly', 'quarterly', 'annual'])
                ->where('pct', '>', 0)
                ->where('is_alternative', false)
                ->where('is_uncertain', false)
                ->whereHas('contract', fn ($q) => $q->where('active', true))
                ->get(),
            'clients' => Client::visibleTo(Client::whereHas('dues'))->orderBy('name')->get(['id', 'name', 'name_en']),
        ]);
    }

    /** ترحيل استحقاق لكشف الحساب */
    public function settle(Request $request, ContractDue $due)
    {
        if ($err = ContractDues::settle($due, $request->user()->id)) {
            return back()->withErrors(['status' => $err]);
        }

        return back()->with('ok', __('flash.due_settled', [
            'amount' => number_format((float) $due->amount),
            'client' => $due->client?->displayName() ?? '',
        ]));
    }

    /** إلغاء استحقاق — بيفضل مسجّل بس مش هيتقيّد */
    public function waive(Request $request, ContractDue $due)
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        if ($err = ContractDues::waive($due, $data['note'] ?? null, $request->user()->id)) {
            return back()->withErrors(['status' => $err]);
        }

        return back()->with('ok', __('flash.due_waived'));
    }

    /** إعادة حساب المستحقات من بنود العقود */
    public function generate()
    {
        $r = ContractDues::generate();

        return back()->with('ok', __('flash.dues_generated', [
            'created' => $r['created'],
            'contracts' => $r['contracts'],
        ]));
    }
}
