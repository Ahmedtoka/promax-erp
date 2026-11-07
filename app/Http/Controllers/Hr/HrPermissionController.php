<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\HrPermission;
use App\Support\Csv;
use App\Support\DateRange;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * الأذونات بالساعة (٧/١١/٢٠٢٦) — تأخير الصبح · انصراف بدري · خروج في النص.
 *
 * • الطلب: لموظف من اللي اليوزر يشوفهم (`visibleTo`) — نفسه أو فريقه، والموارد البشرية الكل.
 * • الاعتماد: `isManagedBy` (الأدمن والموارد البشرية أي حد، والمدير المباشر) — ومحدش يعتمد إذنه.
 * • المعتمد بيتشال من دقايق التأخير في المرتبات وفي كشف الحضور.
 */
class HrPermissionController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $range = DateRange::fromRequest($request);
        $visible = Employee::query()->visibleTo($user)->where('active', true)->orderBy('code')->get();

        $q = HrPermission::with(['employee:id,code,name,name_en,manager_id,user_id', 'requester:id,name', 'decider:id,name'])
            ->whereIn('employee_id', $visible->pluck('id'))
            ->when($request->filled('status'), fn ($w) => $w->where('status', $request->query('status')))
            ->tap(fn ($w) => $range->apply($w, 'date'));

        if ($request->boolean('export')) {
            return Csv::download('permissions-'.now()->format('Y-m-d-Hi').'.csv',
                [__('common.code'), __('hr.employee'), __('hr.date'), __('fp.perm_kind'), __('fp.perm_minutes'),
                    __('fp.perm_reason'), __('fp.status'), __('fp.perm_decided_by')],
                (clone $q)->orderBy('date')->limit(20000)->get()->map(fn (HrPermission $p) => [
                    $p->employee?->code, $p->employee?->displayName(), $p->date->toDateString(), $p->kindLabel(),
                    $p->minutes, $p->reason, $p->statusLabel(), $p->decider?->name,
                ]),
                null,
                Csv::meta(__('fp.perm_title'), $range->fromValue() ?: null, $range->toValue() ?: null));
        }

        return view('hr.permissions', [
            'rows' => (clone $q)->orderByDesc('date')->orderByDesc('id')->paginate(50)->withQueryString(),
            'employees' => $visible,
            'range' => $range,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer'],
            'date' => ['required', 'date'],
            'kind' => ['required', Rule::in(HrPermission::KINDS)],
            'minutes' => ['required', 'integer', 'min:5', 'max:480'],
            'reason' => ['nullable', 'string', 'max:300'],
        ]);

        $e = Employee::query()->visibleTo($request->user())->whereKey($data['employee_id'])->firstOrFail();

        HrPermission::create($data + [
            'employee_id' => $e->id,
            'status' => 'pending',
            'requested_by' => $request->user()->id,
        ]);

        return back()->with('ok', __('fp.perm_saved'));
    }

    public function decide(Request $request, HrPermission $permission)
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected', 'cancelled'])],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        $user = $request->user();
        $e = $permission->employee;

        if ($data['decision'] === 'cancelled') {
            // الإلغاء لصاحب الطلب أو اللي يعتمد — قبل القرار بس
            abort_unless($permission->status === 'pending'
                && ((int) $permission->requested_by === $user->id || $e->isManagedBy($user)), 403);
        } else {
            abort_unless($permission->status === 'pending' && $e->isManagedBy($user)
                && (int) $e->user_id !== $user->id, 403);
        }

        $permission->update([
            'status' => $data['decision'],
            'decided_by' => $user->id,
            'decided_at' => now(),
            'decision_note' => $data['note'] ?? null,
        ]);

        return back()->with('ok', __('fp.perm_decided'));
    }
}
