<?php

namespace App\Http\Controllers\Gl;

use App\Http\Controllers\Controller;
use App\Models\CashMovement;
use App\Models\Gl\GlPeriod;
use App\Models\User;
use App\Support\DateRange;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * حركة النقدية بين الخزنة والبنك ونقدية المناديب — إيداع، سحب، عهدة
 * نقدية لمندوب، ورد عهدة. مافيش بضاعة ولا عميل هنا، فلوس بتتنقل بس.
 * القيد بيتولّد من `CashMovementObserver`.
 */
class CashMovementController extends Controller
{
    /** الحركات اللي بتخص مندوب معيّن — الخانة بتظهر ليها بس */
    public const REP_KINDS = ['rep_advance', 'rep_return'];

    public function index(Request $request)
    {
        $range = DateRange::fromRequest($request, 'month');
        $q = CashMovement::with(['user'])
            ->tap(fn ($q) => $range->apply($q, 'date'))
            ->when($request->filled('kind'), fn ($q) => $q->where('kind', $request->string('kind')->value()))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->value()));

        $posted = (clone $q)->where('status', 'posted');

        return view('gl.cash', [
            'range' => $range,
            'rows' => (clone $q)->orderByDesc('date')->orderByDesc('id')->paginate(50)->withQueryString(),
            'total' => (float) (clone $posted)->sum('amount'),
            // إجمالي كل نوع في النافذة — المحاسب بيطابق الإيداعات على كشف البنك
            'byKind' => (clone $posted)->selectRaw('kind, COALESCE(SUM(amount),0) total')
                ->groupBy('kind')->pluck('total', 'kind')->all(),
            'kinds' => CashMovement::KINDS,
            'reps' => User::whereIn('role', User::FIELD_WORK_ROLES)->where('active', true)->orderBy('name')->get(['id', 'name', 'name_en', 'code']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'kind' => ['required', Rule::in(CashMovement::KINDS)],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'user_id' => ['required_if:kind,rep_advance,rep_return', 'nullable', 'exists:users,id'],
            'reference' => ['nullable', 'string', 'max:80'],
            'note' => ['nullable', 'string', 'max:250'],
            'attachment' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'],
        ]);

        $date = Carbon::parse($data['date']);
        if (GlPeriod::isClosed($date)) {
            return back()->withErrors(['date' => __('gl.period_closed', ['period' => GlPeriod::keyFor($date)])])->withInput();
        }

        CashMovement::create([
            'number' => CashMovement::nextNumber(),
            'date' => $date->toDateString(),
            'kind' => $data['kind'],
            'amount' => round((float) $data['amount'], 2),
            // ⚠️ زي سند المصروف: المندوب بيتصفّر لو النوع مش بتاع مندوب،
            // وإلا الإيداع بيتحفظ منسوب لمندوب والقيد يروح لحسابه
            'user_id' => in_array($data['kind'], self::REP_KINDS, true) ? $data['user_id'] : null,
            'reference' => $data['reference'] ?? null,
            'note' => $data['note'] ?? null,
            'attachment_path' => $request->hasFile('attachment') ? $request->file('attachment')->store('gl-attachments', 'public') : null,
            'status' => 'posted',
            'created_by' => $request->user()->id,
        ]);

        return redirect()->route('gl.cash')->with('ok', __('gl.cash_saved'));
    }

    public function void(Request $request, CashMovement $cashMovement)
    {
        if ($cashMovement->status === 'void') {
            return back();
        }
        if (GlPeriod::isClosed($cashMovement->date)) {
            return back()->withErrors(['void' => __('gl.void_closed_hint')]);
        }
        $cashMovement->update(['status' => 'void', 'voided_at' => now(), 'voided_by' => $request->user()->id]);

        return back()->with('ok', __('gl.voided'));
    }
}
