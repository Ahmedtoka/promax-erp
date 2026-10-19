<?php

namespace App\Http\Controllers\Hr;

use App\Exceptions\Rejected;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\HrPenalty;
use App\Models\Safe;
use App\Services\Gl\ClosedPeriod;
use App\Services\Gl\Ledger;
use App\Support\Access;
use App\Support\Csv;
use App\Support\DateRange;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * السلف والجزاءات (Lane 7 — المهمة ٥).
 *
 * • `index` — تابين (سلف / جزاءات) + فلتر تاريخ + `?export=1`.
 * • الكتابة `role:admin,hr` + `act.hr.advances`.
 * • قيد السلفة في الدفتر بيتزوّد في المهمة ٧ بعد الـ commit — هنا الصف بس،
 *   مع رفض الشهر المقفول (`Ledger::assertOpen`).
 *
 * ⚠️ الموظف والخزنة بيتراجعوا على السيرفر (النطاق + النشاط)، مش بس في القايمة.
 */
class AdvanceController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $tab = $request->query('tab') === 'penalties' ? 'penalties' : 'advances';
        $range = DateRange::fromRequest($request);

        if ($tab === 'penalties') {
            $q = HrPenalty::query()->with('employee:id,code,name,name_en');
        } else {
            $q = EmployeeAdvance::query()->with('employee:id,code,name,name_en', 'safe:id,code,name,name_en');
        }
        $range->apply($q, 'date');

        if ($request->boolean('export')) {
            $all = (clone $q)->orderBy('date')->orderBy('id')->limit(20000)->get();

            if ($tab === 'penalties') {
                return Csv::download('penalties-'.now()->format('Y-m-d-Hi').'.csv',
                    [__('hrm.emp_code'), __('hr.employee'), __('common.date'), __('common.amount'), __('hrm.pen_reason'), __('hrm.pen_deducted')],
                    $all->map(fn (HrPenalty $p) => [
                        $p->employee?->code ?? '', $p->employee?->displayName() ?? '', $p->date->format('Y-m-d'),
                        Csv::money($p->amount), $p->reason, $p->payslip_id ? __('common.yes') : __('common.no'),
                    ]),
                    null,
                    Csv::meta(__('hrm.penalties'), $range->fromValue() ?: null, $range->toValue() ?: null));
            }

            return Csv::download('advances-'.now()->format('Y-m-d-Hi').'.csv',
                [
                    __('common.code'), __('hrm.emp_code'), __('hr.employee'), __('common.date'), __('common.amount'),
                    __('hrm.adv_installments'), __('hrm.adv_installment_amount'), __('hrm.adv_start'),
                    __('hrm.adv_recovered'), __('hrm.adv_remaining'), __('hrm.status'),
                ],
                $all->map(fn (EmployeeAdvance $a) => [
                    $a->number, $a->employee?->code ?? '', $a->employee?->displayName() ?? '', $a->date->format('Y-m-d'),
                    Csv::money($a->amount), $a->installments, Csv::money($a->installment_amount), $a->start_period,
                    Csv::money($a->recovered), Csv::money($a->remaining()), __('hrm.adv_status_'.$a->status),
                ]),
                null,
                Csv::meta(__('hrm.advances'), $range->fromValue() ?: null, $range->toValue() ?: null));
        }

        $rows = (clone $q)->orderByDesc('date')->orderByDesc('id')->paginate(50)->withQueryString();

        return view('hr.advances', [
            'tab' => $tab,
            'range' => $range,
            'rows' => $rows,
            'employees' => Employee::query()->visibleTo($user)->where('active', true)->orderBy('code')->get(['id', 'code', 'name', 'name_en']),
            'safes' => Safe::query()->where('active', true)->orderBy('code')->get(),
            'canEdit' => Access::action($user, 'act.hr.advances'),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer'],
            'date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:100000000'],
            'installments' => ['required', 'integer', 'min:1', 'max:120'],
            'start_period' => ['required', 'date_format:Y-m'],
            'safe_id' => ['required', 'integer', Rule::exists('safes', 'id')->where('active', true)],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        // ⚠️ نطاق الموظف على السيرفر
        $emp = Employee::query()->visibleTo($request->user())->where('active', true)->find($data['employee_id']);
        if ($emp === null) {
            return back()->withErrors(['employee_id' => __('hrm.adv_employee_invalid')])->withInput();
        }

        if ($locked = self::lockedPeriod($data['start_period'])) {
            return back()->withErrors(['start_period' => __('hrm.adv_period_locked', ['period' => $locked])])->withInput();
        }

        try {
            $adv = DB::transaction(function () use ($data, $emp, $request) {
                $date = Carbon::parse($data['date']);
                app(Ledger::class)->assertOpen($date);

                $amount = round((float) $data['amount'], 2);

                return EmployeeAdvance::create([
                    'number' => EmployeeAdvance::nextNumber(),
                    'employee_id' => $emp->id,
                    'date' => $date->toDateString(),
                    'amount' => $amount,
                    'installments' => (int) $data['installments'],
                    'installment_amount' => round($amount / (int) $data['installments'], 2),
                    'start_period' => $data['start_period'],
                    'safe_id' => (int) $data['safe_id'],
                    'note' => $data['note'] ?? null,
                    'created_by' => $request->user()->id,
                ]);
            });
        } catch (ClosedPeriod|Rejected $e) {
            return back()->withErrors(['advance' => $e->getMessage()])->withInput();
        }

        // قيد السلفة (مدين سلف العاملين / دائن الخزنة) بعد الـ commit
        \App\Services\Hr\PayrollGl::post($adv);

        return back()->with('ok', __('hrm.adv_saved', ['number' => $adv->number]));
    }

    public function void(Request $request, EmployeeAdvance $advance)
    {
        try {
            DB::transaction(function () use ($advance) {
                $a = EmployeeAdvance::query()->lockForUpdate()->findOrFail($advance->id);

                if ($a->status !== 'open' || (float) $a->recovered > 0) {
                    throw new Rejected(__('hrm.adv_void_blocked'));
                }
                // ⚠️ الإلغاء بيشيل قيد السلفة — ممنوع في شهر مقفول
                app(Ledger::class)->assertOpen($a->date->copy());
                // ⚠️ قسطها في مسير معتمد لسه ماتصرفش — الإلغاء كان هيضيّع الخصم ويكسر حساب السلف
                $inRun = \App\Models\Payslip::query()->where('employee_id', $a->employee_id)->where('advances', '>', 0)
                    ->whereHas('run', fn ($q) => $q->where('status', 'approved')->where('period', '>=', $a->start_period))
                    ->exists();
                if ($inRun) {
                    throw new Rejected(__('hrm.adv_void_in_run'));
                }

                $a->status = 'void';
                $a->save();
            });
        } catch (ClosedPeriod|Rejected $e) {
            return back()->withErrors(['advance' => $e->getMessage()]);
        }

        // السلفة اتلغت قبل أي استرداد — قيدها بيتشال
        \App\Services\Hr\PayrollGl::unpost($advance);

        return back()->with('ok', __('hrm.adv_voided'));
    }

    public function storePenalty(Request $request)
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer'],
            'date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:100000000'],
            'reason' => ['required', 'string', 'max:200'],
        ]);

        $emp = Employee::query()->visibleTo($request->user())->where('active', true)->find($data['employee_id']);
        if ($emp === null) {
            return back()->withErrors(['employee_id' => __('hrm.adv_employee_invalid')])->withInput();
        }

        if ($locked = self::lockedPeriod(substr((string) $data['date'], 0, 7))) {
            return back()->withErrors(['date' => __('hrm.pen_period_locked', ['period' => $locked])])->withInput();
        }

        HrPenalty::create([
            'employee_id' => $emp->id,
            'date' => $data['date'],
            'amount' => round((float) $data['amount'], 2),
            'reason' => $data['reason'],
            'created_by' => $request->user()->id,
        ]);

        return back()->with('ok', __('hrm.pen_saved'));
    }

    /** آخر مسير معتمد/مصروف لو الشهر ده أو بعده — الإضافة فيه كانت هتعدّي من غير خصم */
    private static function lockedPeriod(string $period): ?string
    {
        return \App\Models\PayrollRun::query()->whereIn('status', ['approved', 'paid'])
            ->where('period', '>=', $period)->orderByDesc('period')->value('period');
    }

    public function deletePenalty(Request $request, HrPenalty $penalty)
    {
        try {
            DB::transaction(function () use ($penalty) {
                $p = HrPenalty::query()->lockForUpdate()->findOrFail($penalty->id);

                if ($p->payslip_id !== null) {
                    throw new Rejected(__('hrm.pen_delete_blocked'));
                }

                $p->delete();
            });
        } catch (Rejected $e) {
            return back()->withErrors(['penalty' => $e->getMessage()]);
        }

        return back()->with('ok', __('hrm.pen_deleted'));
    }
}
