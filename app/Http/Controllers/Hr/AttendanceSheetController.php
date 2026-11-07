<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\AttendanceDay;
use App\Models\Employee;
use App\Services\Hr\AttendanceSheet;
use App\Support\Csv;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * كشف الحضور (٧/١١/٢٠٢٦) — `role:admin,hr,manager,accountant`.
 *
 * • `daily` — يوم واحد: حضور · انصراف · ساعات · تأخير · انصراف بدري · غياب · إجازة · مصدر البصمة.
 * • `monthly` — شهر: ملخص لكل موظف، أو يوم بيوم لموظف واحد.
 * ⚠️ المدير بيشوف نفسه وفريقه بس (`Employee::visibleTo`)، والحسبة كلها في `AttendanceSheet`.
 */
class AttendanceSheetController extends Controller
{
    public function daily(Request $request)
    {
        $date = $this->date($request->query('date')) ?? today();
        $employees = $this->employees($request);
        $grid = AttendanceSheet::grid($employees, $date, $date);
        $key = $date->toDateString();

        $rows = $employees->map(fn (Employee $e) => ['e' => $e] + $grid[$e->id][$key])
            ->sortBy(fn ($r) => [array_search($r['status'], ['absent', 'late', 'not_yet', 'present', 'leave', 'weekend', 'future'], true), $r['e']->code])
            ->values();

        $status = $request->query('status');
        $shown = $status ? $rows->where('status', $status)->values() : $rows;

        if ($request->boolean('export')) {
            return Csv::download('attendance-'.$key.'.csv',
                [__('common.code'), __('hr.employee'), __('fp.check_in'), __('fp.check_out'), __('fp.worked'),
                    __('fp.late_min'), __('fp.early_min'), __('fp.status'), __('fp.sources')],
                $shown->map(fn ($r) => $this->line($r)),
                null,
                Csv::meta(__('fp.daily_title'), $key, $key));
        }

        return view('hr.attendance_daily', [
            'date' => $date,
            'rows' => $shown,
            'counts' => $rows->countBy('status'),
            'total' => $rows->count(),
            'status' => $status,
        ]);
    }

    public function monthly(Request $request)
    {
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month'))
            ? Carbon::createFromFormat('Y-m-d', $request->query('month').'-01')->startOfDay()
            : today()->startOfMonth();
        $from = $month->copy()->startOfMonth();
        $to = $month->copy()->endOfMonth();

        $employees = $this->employees($request);
        $one = $request->filled('employee_id') ? $employees->firstWhere('id', (int) $request->query('employee_id')) : null;
        $grid = AttendanceSheet::grid($one !== null ? collect([$one]) : $employees, $from, $to);

        if ($one !== null) {
            $days = $grid[$one->id] ?? [];

            if ($request->boolean('export')) {
                return Csv::download('attendance-'.$one->code.'-'.$month->format('Y-m').'.csv',
                    [__('hr.date'), __('fp.check_in'), __('fp.check_out'), __('fp.worked'), __('fp.late_min'),
                        __('fp.early_min'), __('fp.status'), __('fp.sources')],
                    collect($days)->map(fn ($r, $d) => array_merge([$d], array_slice($this->line(['e' => $one] + $r), 2))),
                    null,
                    Csv::meta(__('fp.monthly_title').' — '.$one->displayName(), $from->toDateString(), $to->toDateString()));
            }

            return view('hr.attendance_monthly', [
                'month' => $month, 'employees' => $employees, 'one' => $one,
                'days' => $days, 'totals' => AttendanceSheet::totals($days), 'summary' => null,
            ]);
        }

        $summary = $employees->map(fn (Employee $e) => ['e' => $e] + AttendanceSheet::totals($grid[$e->id] ?? []));

        if ($request->boolean('export')) {
            return Csv::download('attendance-'.$month->format('Y-m').'.csv',
                [__('common.code'), __('hr.employee'), __('fp.present_days'), __('fp.late_days'), __('fp.late_min'),
                    __('fp.absent_days'), __('fp.leave_days'), __('fp.early_min'), __('fp.worked')],
                $summary->map(fn ($r) => [$r['e']->code, $r['e']->displayName(), $r['present'], $r['late'], $r['late_minutes'],
                    $r['absent'], $r['leave'], $r['early_minutes'], AttendanceDay::hhmm($r['worked'])]),
                null,
                Csv::meta(__('fp.monthly_title'), $from->toDateString(), $to->toDateString()));
        }

        return view('hr.attendance_monthly', [
            'month' => $month, 'employees' => $employees, 'one' => null,
            'days' => [], 'totals' => null, 'summary' => $summary,
        ]);
    }

    private function employees(Request $request)
    {
        return Employee::query()->visibleTo($request->user())
            ->where('active', true)->where('attendance_required', true)
            ->orderBy('code')->get();
    }

    private function date(?string $v): ?Carbon
    {
        return $v !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? Carbon::parse($v)->startOfDay() : null;
    }

    /** نفس أعمدة الشاشة والتصدير */
    private function line(array $r): array
    {
        return [
            $r['e']->code,
            $r['e']->displayName(),
            $r['in']?->format('H:i') ?? '—',
            $r['out']?->format('H:i') ?? '—',
            AttendanceDay::hhmm($r['worked']),
            $r['late'],
            $r['early'],
            __('fp.st_'.$r['status']).($r['leave'] ? ' · '.$r['leave']->typeLabel() : ''),
            collect($r['sources'])->map(fn ($s) => __('hrm.source_'.$s))->implode(' + '),
        ];
    }
}
