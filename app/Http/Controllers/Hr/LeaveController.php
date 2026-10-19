<?php

namespace App\Http\Controllers\Hr;

use App\Exceptions\Rejected;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Hr\Leave;
use App\Support\Access;
use App\Support\Csv;
use App\Support\DateRange;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * الإجازات على الويب (Lane 7 — المهمة ٣).
 *
 * • `index` — طلباتي + طلب جديد + تاب «مستني موافقتي» (`?tab=approvals`).
 * • `decide` / `cancel` — الحارس على السيرفر (`isManagedBy` / صاحب الطلب).
 * • `balances` / `updateBalance` — أرصدة السنة (`role:admin,hr` + `act.hr.leave_balances`).
 *
 * ⚠️ الكتابة كلها عبر `App\Services\Hr\Leave`.
 */
class LeaveController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $mine = Employee::forUser($user);
        $tab = $request->query('tab') === 'approvals' ? 'approvals' : 'mine';
        $range = DateRange::fromRequest($request);

        $q = ($tab === 'approvals' ? self::approvalsFor($user) : self::mineQuery($mine))
            ->with('employee:id,code,name,name_en')
            ->tap(fn ($q) => $range->apply($q, 'from'));

        $line = fn (LeaveRequest $r) => [
            $r->employee?->code ?? '',
            $r->employee?->displayName() ?? '—',
            $r->typeLabel(),
            $r->from->format('Y-m-d'),
            $r->to->format('Y-m-d'),
            Leave::fmt((float) $r->days),
            $r->statusLabel(),
            $r->note ?? '',
            $r->decision_note ?? '',
        ];

        if ($request->boolean('export')) {
            $all = (clone $q)->orderBy('from')->orderBy('id')->limit(20000)->get();

            return Csv::download('leave-'.$tab.'-'.now()->format('Y-m-d-Hi').'.csv',
                [
                    __('common.code'), __('hr.employee'), __('hrm.leave_type'), __('hrm.leave_from'), __('hrm.leave_to'),
                    __('hrm.leave_days'), __('hrm.status'), __('hrm.leave_note'), __('hrm.leave_decision_note'),
                ],
                $all->map($line),
                null,
                Csv::meta(__('hrm.leave'), $range->fromValue() ?: null, $range->toValue() ?: null));
        }

        $rows = (clone $q)->orderByDesc('from')->orderByDesc('id')->paginate(50)->withQueryString();

        return view('hr.leave', [
            'tab' => $tab,
            'range' => $range,
            'rows' => $rows,
            'employee' => $mine,
            'balances' => $mine !== null ? Leave::balances($mine, (int) now()->year) : [],
            'pendingCount' => self::approvalsFor($user)->count(),
            'canBalances' => Access::action($user, 'act.hr.leave_balances') && Access::allows($user, 'hr.leave.balances'),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(LeaveRequest::TYPES)],
            'from' => ['required', 'date'],
            'to' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        $user = $request->user();
        $mine = Employee::forUser($user);

        if ($mine === null) {
            return back()->withErrors(['leave' => __('hrm.no_employee_record')]);
        }

        try {
            Leave::request($mine, $user, $data['type'], Carbon::parse($data['from']), Carbon::parse($data['to']), $data['note'] ?? null);
        } catch (Rejected $e) {
            return back()->withErrors(['leave' => $e->getMessage()])->withInput();
        }

        return back()->with('ok', __('hrm.leave_sent'));
    }

    public function decide(Request $request, LeaveRequest $leaveRequest)
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        $user = $request->user();

        // ⚠️ الحارس على السيرفر — فلترة التاب مش حماية
        abort_unless($leaveRequest->employee?->isManagedBy($user) ?? false, 403, __('hrm.leave_not_your_team'));

        try {
            Leave::decide($leaveRequest, $user, $data['decision'], $data['note'] ?? null);
        } catch (Rejected $e) {
            return back()->withErrors(['leave' => $e->getMessage()]);
        }

        return back()->with('ok', __('hrm.leave_decided_'.$data['decision']));
    }

    public function cancel(Request $request, LeaveRequest $leaveRequest)
    {
        try {
            Leave::cancel($leaveRequest, $request->user());
        } catch (Rejected $e) {
            return back()->withErrors(['leave' => $e->getMessage()]);
        }

        return back()->with('ok', __('hrm.leave_cancelled'));
    }

    /** GET /hr/leave/balances — أرصدة السنة لكل موظف نشط + `?export=1` */
    public function balances(Request $request)
    {
        $year = (int) ($request->integer('year') ?: now()->year);
        $year = max(2000, min(2100, $year));

        $employees = Employee::query()->where('active', true)
            ->when($request->filled('q'), function ($q) use ($request) {
                $s = '%'.trim((string) $request->query('q')).'%';
                $q->where(fn ($w) => $w->where('name', 'like', $s)->orWhere('name_en', 'like', $s)->orWhere('code', 'like', $s));
            })
            ->orderBy('code')->get(['id', 'code', 'name', 'name_en']);

        $rows = LeaveBalance::query()->where('year', $year)
            ->whereIn('employee_id', $employees->pluck('id'))
            ->get()->groupBy('employee_id');

        $cell = function (Employee $e, string $type) use ($rows) {
            $row = $rows->get($e->id)?->firstWhere('type', $type);
            $entitled = $row !== null ? (float) $row->entitled : Leave::defaultEntitled($type);
            $used = $row !== null ? (float) $row->used : 0.0;

            return ['row' => $row, 'entitled' => $entitled, 'used' => $used,
                'remaining' => Leave::checked($type) ? max(0.0, $entitled - $used) : null];
        };

        $grid = $employees->mapWithKeys(fn ($e) => [$e->id => collect(LeaveRequest::TYPES)->mapWithKeys(fn ($t) => [$t => $cell($e, $t)])->all()]);

        if ($request->boolean('export')) {
            $cols = [__('common.code'), __('hr.employee')];
            foreach (LeaveRequest::TYPES as $t) {
                $cols[] = __('hrm.leave_type_'.$t).' — '.__('hrm.leave_entitled');
                $cols[] = __('hrm.leave_type_'.$t).' — '.__('hrm.leave_used');
            }

            return Csv::download('leave-balances-'.$year.'.csv', $cols,
                $employees->map(function ($e) use ($grid) {
                    $line = [$e->code, $e->displayName()];
                    foreach (LeaveRequest::TYPES as $t) {
                        $line[] = Leave::fmt($grid[$e->id][$t]['entitled']);
                        $line[] = Leave::fmt($grid[$e->id][$t]['used']);
                    }

                    return $line;
                }),
                null,
                Csv::meta(__('hrm.leave_balances').' '.$year));
        }

        return view('hr.leave_balances', [
            'year' => $year,
            'employees' => $employees,
            'grid' => $grid,
            'missing' => $employees->count() * count(LeaveRequest::TYPES) - $rows->flatten()->count(),
            'canEdit' => Access::action($request->user(), 'act.hr.leave_balances'),
        ]);
    }

    /** POST /hr/leave/balances/{leaveBalance} — تعديل المستحق (الأقدمية، الترحيل…) */
    public function updateBalance(Request $request, LeaveBalance $leaveBalance)
    {
        abort_unless(Access::action($request->user(), 'act.hr.leave_balances'), 403);

        $data = $request->validate([
            'entitled' => ['required', 'numeric', 'min:0', 'max:365'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        $leaveBalance->entitled = round((float) $data['entitled'], 1);

        // الفورم السريع في الجدول مابيبعتش ملاحظة — ماتمسحش القديمة
        if ($request->has('note')) {
            $leaveBalance->note = $data['note'] ?? null;
        }

        $leaveBalance->save();

        return back()->with('ok', __('hrm.leave_balance_saved', [
            'name' => $leaveBalance->employee?->displayName() ?? '',
            'type' => $leaveBalance->typeLabel(),
        ]));
    }

    /** POST /hr/leave/balances/seed — أرصدة السنة الناقصة من الإعدادات */
    public function seedBalances(Request $request)
    {
        abort_unless(Access::action($request->user(), 'act.hr.leave_balances'), 403);

        $year = max(2000, min(2100, (int) ($request->integer('year') ?: now()->year)));
        $r = Leave::seedOpening($year);

        return back()->with('ok', __('hrm.leave_seeded', ['n' => $r['created'], 'year' => $year]));
    }

    // ═══════════════ أدوات (الـAPI بيستخدمها كمان) ═══════════════

    /** طلبات الموظف ده (فاضية لو مالوش ملف) */
    public static function mineQuery(?Employee $mine): Builder
    {
        $q = LeaveRequest::query();

        return $mine === null ? $q->whereRaw('1 = 0') : $q->where('employee_id', $mine->id);
    }

    /** الطلبات المعلّقة اللي اليوزر ده يقرر فيها — الأدمن والموارد: الكل ما عدا طلبه */
    public static function approvalsFor(User $user): Builder
    {
        $q = LeaveRequest::query()->where('status', 'pending');
        $mine = Employee::forUser($user);

        if (in_array($user->role, Employee::MANAGE_ALL_ROLES, true)) {
            return $mine === null ? $q : $q->where('employee_id', '!=', $mine->id);
        }

        if ($mine === null) {
            return $q->whereRaw('1 = 0');
        }

        return $q->whereIn('employee_id', Employee::query()->where('manager_id', $mine->id)->select('id'));
    }
}
