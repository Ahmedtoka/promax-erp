<?php

namespace App\Services\Hr;

use App\Models\AttendanceDay;
use App\Models\Employee;
use App\Models\HrPermission;
use App\Models\LeaveRequest;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * كشف الحضور (٧/١١/٢٠٢٦) — يوم لكل موظف: حاضر · متأخر · غايب · إجازة · إذن · إجازة أسبوعية.
 *
 * ⚠️ **نفس قواعد المرتبات بالظبط** (`Payroll::attendance`): بداية الشيفت + دقايق السماح
 * من `HrSettings`، الإجازة المعتمدة بتغطي اليوم، والإذن المعتمد (`late_in`) بيتشال من
 * التأخير. الشاشة بتقول اللي هيتخصم — مش رقم تاني.
 *
 * البانشات من أي مصدر (المكنة · الأبلكيشن · الداش بورد · المشرف) — يوم واحد لكل موظف.
 */
final class AttendanceSheet
{
    /**
     * @param  Collection<int, Employee>  $employees
     * @return array<int, array<string, array>> [employee_id => [Y-m-d => row]]
     */
    public static function grid(Collection $employees, Carbon $from, Carbon $to): array
    {
        if ($employees->isEmpty()) {
            return [];
        }

        $ids = $employees->pluck('id');
        $userIds = $employees->pluck('user_id')->filter()->values();

        $days = AttendanceDay::with('punches')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->where(fn ($q) => $q->whereIn('employee_id', $ids)
                ->when($userIds->isNotEmpty(), fn ($w) => $w->orWhereIn('user_id', $userIds)))
            ->get();

        $leaves = LeaveRequest::whereIn('employee_id', $ids)->where('status', 'approved')
            ->whereDate('from', '<=', $to->toDateString())->whereDate('to', '>=', $from->toDateString())
            ->get()->groupBy('employee_id');

        $perms = HrPermission::whereIn('employee_id', $ids)->where('status', 'approved')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get()->groupBy(fn ($p) => $p->employee_id.'|'.$p->date->toDateString());

        $weekend = Leave::weekendDays();
        [$sh, $sm] = array_map('intval', explode(':', HrSettings::get('hr_shift_start').':0'));
        $grace = (int) HrSettings::num('hr_late_grace_minutes');
        $dayMinutes = (int) HrSettings::num('hr_day_minutes');

        $out = [];

        foreach ($employees as $e) {
            for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
                $key = $d->toDateString();
                $day = $days->first(fn (AttendanceDay $x) => $x->date->toDateString() === $key
                    && ((int) $x->employee_id === $e->id || ($e->user_id !== null && (int) $x->user_id === (int) $e->user_id))
                    && (int) $x->sessions > 0);

                $leave = ($leaves->get($e->id) ?? collect())
                    ->first(fn (LeaveRequest $l) => $l->from->lte($d) && $l->to->gte($d));
                $dayPerms = $perms->get($e->id.'|'.$key) ?? collect();
                $permLate = (int) $dayPerms->where('kind', 'late_in')->sum('minutes');
                $permEarly = (int) $dayPerms->where('kind', 'early_out')->sum('minutes');

                $shift = $d->copy()->setTime($sh, $sm);
                $late = $early = 0;

                if ($day?->first_in_at !== null) {
                    $raw = (int) round(($day->first_in_at->getTimestamp() - $shift->getTimestamp()) / 60) - $permLate;
                    $late = $raw > $grace ? $raw : 0;
                }

                if ($day?->last_out_at !== null) {
                    $end = $shift->copy()->addMinutes($dayMinutes);
                    $early = max((int) round(($end->getTimestamp() - $day->last_out_at->getTimestamp()) / 60) - $permEarly, 0);
                }

                $status = match (true) {
                    $day !== null => $late > 0 ? 'late' : 'present',
                    $leave !== null => 'leave',
                    in_array($d->dayOfWeek, $weekend, true) => 'weekend',
                    $d->isFuture() && ! $d->isToday() => 'future',
                    $d->isToday() && now()->lt($shift->copy()->addMinutes($grace)) => 'not_yet',
                    default => 'absent',
                };

                $out[$e->id][$key] = [
                    'status' => $status,
                    'day' => $day,
                    'in' => $day?->first_in_at,
                    'out' => $day?->last_out_at,
                    'worked' => $day?->payableMinutes() ?? 0,
                    'late' => $late,
                    'early' => $early,
                    'leave' => $leave,
                    'perm_minutes' => (int) $dayPerms->sum('minutes'),
                    'sources' => $day?->punches->pluck('source')->unique()->values()->all() ?? [],
                ];
            }
        }

        return $out;
    }

    /** ملخص الشهر لموظف: أيام حضور · تأخير · غياب · إجازات · ساعات */
    public static function totals(array $rows): array
    {
        $t = ['present' => 0, 'late' => 0, 'late_minutes' => 0, 'absent' => 0, 'leave' => 0, 'worked' => 0, 'early_minutes' => 0];

        foreach ($rows as $r) {
            if (in_array($r['status'], ['present', 'late'], true)) {
                $t['present']++;
            }
            if ($r['status'] === 'late') {
                $t['late']++;
            }
            if ($r['status'] === 'absent') {
                $t['absent']++;
            }
            if ($r['status'] === 'leave') {
                $t['leave']++;
            }
            $t['late_minutes'] += $r['late'];
            $t['early_minutes'] += $r['early'];
            $t['worked'] += $r['worked'];
        }

        return $t;
    }
}
