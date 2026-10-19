<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Models\User;
use App\Services\Attendance;
use App\Support\Csv;
use App\Support\DateRange;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * الحضور لكل موظف (Lane 7 — المهمة ٢).
 *
 * • `punch` — زرار الهيدر في الداش بورد (المحاسب، أمين المخزن، المدير…).
 * • `supervisor` / `supervisorPunch` — المشرف بيبصم للعامل اللي مالوش لوجين.
 * • `punches` — تقرير البانشات سطر سطر بالمصدر والجهاز والمكان.
 *
 * ⚠️ الكتابة كلها عبر `App\Services\Attendance` — مفيش `AttendancePunch::create` هنا.
 */
class AttendanceHrController extends Controller
{
    private const TYPES = 'in:in,break,back,out';

    /** POST /attendance/punch — بانش الداش بورد لصاحب الحساب نفسه */
    public function punch(Request $request)
    {
        $data = $request->validate(['type' => ['required', self::TYPES]]);
        $user = $request->user();

        if (Employee::forUser($user) === null) {
            return back()->withErrors(['punch' => __('hrm.no_employee_record')]);
        }

        [$error] = Attendance::punch($user, $data['type'], null, null, false, 'dashboard', [
            'device' => (string) $request->userAgent(),
            'ip' => $request->ip(),
        ]);

        if ($error !== null) {
            return back()->withErrors(['punch' => $error]);
        }

        return back()->with('ok', __('hrm.punch_saved', ['type' => __('hr.punch_'.$data['type'])]));
    }

    /** GET /hr/attendance/supervisor — عمال من غير لوجين تحت إيدي وحالتهم النهارده */
    public function supervisor(Request $request)
    {
        $user = $request->user();
        $rows = $this->workersFor($user)
            ->with('costCenter:id,code,name,name_en')
            ->orderBy('name')->get();

        $states = Attendance::peekEmployeeStates($rows);

        return view('hr.supervisor', [
            'rows' => $rows,
            'states' => $states,
            'next' => collect($states)->map(fn ($s) => Attendance::nextTypes($s))->all(),
        ]);
    }

    /** POST /hr/attendance/supervisor/punch — `{employee_id, type}` */
    public function supervisorPunch(Request $request)
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'type' => ['required', self::TYPES],
        ]);

        $user = $request->user();
        $employee = Employee::query()->findOrFail($data['employee_id']);

        // ⚠️ الحارس على السيرفر — فلترة القايمة مش حماية
        abort_unless($employee->isManagedBy($user), 403, __('perm.scope_staff_denied'));

        [$error] = Attendance::punchEmployee($employee, $data['type'], $user);

        if ($error !== null) {
            return back()->withErrors(['punch' => $error]);
        }

        return back()->with('ok', __('hrm.supervisor_saved', [
            'type' => __('hr.punch_'.$data['type']),
            'name' => $employee->displayName(),
        ]));
    }

    /** GET /hr/attendance/punches — سطر لكل بانش + `?export=1` */
    public function punches(Request $request)
    {
        $user = $request->user();
        $range = DateRange::fromRequest($request, 'month');

        $q = AttendancePunch::query()
            ->with(['employee:id,code,name,name_en', 'user:id,code,name,name_en', 'recorder:id,name,name_en'])
            ->tap(fn ($q) => $range->apply($q, 'at'))
            ->tap(fn ($q) => $this->visibleTo($q, $user))
            ->when($request->filled('employee_id'), fn ($q) => $q->where('employee_id', $request->integer('employee_id')))
            ->when(in_array($request->query('source'), AttendancePunch::SOURCES, true),
                fn ($q) => $q->where('source', (string) $request->query('source')));

        $line = fn (AttendancePunch $p) => [
            $p->at->format('Y-m-d'),
            $p->at->format('h:i A'),
            $this->nameOf($p),
            $p->typeLabel(),
            $this->sourceText($p),
            $p->recorder?->displayName() ?? '—',
            $p->lat !== null && $p->lng !== null ? 'https://maps.google.com/?q='.(float) $p->lat.','.(float) $p->lng : '—',
            $p->device ?: '—',
            $p->ip ?: '—',
        ];

        if ($request->boolean('export')) {
            $all = (clone $q)->orderBy('at')->orderBy('id')->limit(20000)->get();

            return Csv::download('punches-'.now()->format('Y-m-d-Hi').'.csv',
                [
                    __('hr.date'), __('hrm.time'), __('hr.employee'), __('hrm.punch_type'), __('hrm.source'),
                    __('hrm.recorded_by'), __('hrm.location'), __('hrm.device'), __('hrm.ip'),
                ],
                $all->map($line),
                null,
                Csv::meta(__('hrm.punches'), $range->fromValue() ?: null, $range->toValue() ?: null));
        }

        $rows = (clone $q)->orderByDesc('at')->orderByDesc('id')->paginate(100)->withQueryString();

        return view('hr.punches', [
            'range' => $range,
            'rows' => $rows,
            'lines' => $rows->getCollection()->mapWithKeys(fn ($p) => [$p->id => $line($p)]),
            'total' => (clone $q)->count(),
            'bySource' => (clone $q)->reorder()->selectRaw('source, COUNT(*) n')->groupBy('source')->pluck('n', 'source'),
            'employees' => Employee::query()->visibleTo($user)->where('active', true)->orderBy('name')->get(['id', 'code', 'name', 'name_en']),
        ]);
    }

    // ═══════════════ أدوات ═══════════════

    /** العمال النشطين من غير لوجين اللي اليوزر ده مديرهم (الأدمن والموارد البشرية: الكل) */
    private function workersFor(User $user): Builder
    {
        $q = Employee::query()->where('active', true)->whereNull('user_id');

        if (in_array($user->role, Employee::MANAGE_ALL_ROLES, true)) {
            return $q;
        }

        $mine = Employee::forUser($user);

        return $mine === null ? $q->whereRaw('1 = 0') : $q->where('manager_id', $mine->id);
    }

    /**
     * البانشات اللي اليوزر يشوفها: موظفين `visibleTo`، + بانشات يوزرز
     * مالهمش ملف موظف لسه (المناديب قبل السيد) — الأدمن/الموارد/المحاسب
     * الكل، والمدير فريقه (`manager_id`)، ومدير الفرع فرعه.
     */
    private function visibleTo(Builder $q, User $user): void
    {
        if (in_array($user->role, Employee::SEE_ALL_ROLES, true)) {
            return;
        }

        $q->where(function ($w) use ($user) {
            $w->whereIn('employee_id', Employee::query()->visibleTo($user)->select('id'))
                ->orWhere(function ($o) use ($user) {
                    $o->whereNull('employee_id')->whereNotNull('user_id')
                        ->whereIn('user_id', $this->teamUsers($user)->select('id'));
                });
        });
    }

    private function teamUsers(User $user)
    {
        $q = User::query();

        if ($user->role === 'manager') {
            return $q->where(fn ($w) => $w->whereKey($user->id)->orWhere('manager_id', $user->id));
        }

        if ($user->role === 'branch_manager') {
            return $user->branch_id === null
                ? $q->whereKey($user->id)
                : $q->where('branch_id', $user->branch_id);
        }

        return $q->whereKey($user->id);
    }

    private function nameOf(AttendancePunch $p): string
    {
        return $p->employee?->displayName() ?? $p->user?->displayName() ?? '—';
    }

    private function sourceText(AttendancePunch $p): string
    {
        if ($p->source === 'supervisor' && $p->recorder !== null) {
            return __('hrm.source_by_supervisor', ['name' => $p->recorder->displayName()]);
        }

        return $p->sourceLabel();
    }
}
