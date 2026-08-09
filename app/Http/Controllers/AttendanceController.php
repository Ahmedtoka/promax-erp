<?php

namespace App\Http\Controllers;

use App\Models\AttendanceDay;
use App\Models\User;
use App\Services\Attendance;
use App\Support\Scope;
use Illuminate\Http\Request;

/**
 * ═══════════════════════════════════════════════════════════════
 * شاشات الحضور والانصراف (2026-08-08)
 * ═══════════════════════════════════════════════════════════════
 *
 * تلات أسئلة، تلات تابات:
 *   1. **مين شغال دلوقتي؟** — بورد النهارده، كل الفريق بحالته.
 *   2. **اشتغل كام؟** — السجل بفترة وفلاتر وسامري.
 *   3. **مين نسي يقفل؟** — قايمة المراجعة والاعتماد.
 */
class AttendanceController extends Controller
{
    /** بورد النهارده — كل موظف وحالته دلوقتي */
    public function board(Request $request)
    {
        $date = $request->date('date')?->toDateString() ?? today()->toDateString();

        // ⚠️ **كل الموظفين النشطين مش اللي عملوا حضور بس.** السؤال
        // الأهم هو «مين **مش** شغال» — واللي مسجّلش حضور مالوش صف في
        // `attendance_days` أصلاً، فلو بدأنا من الجدول كان هيختفي.
        $users = User::where('active', true)
            ->orderBy('role')->orderBy('name')
            ->get(['id', 'name', 'name_en', 'code', 'role']);

        $days = AttendanceDay::whereDate('date', $date)
            ->get()->keyBy('user_id');

        $rows = $users->map(function ($u) use ($days) {
            $day = $days->get($u->id);

            return [
                'user' => $u,
                'day' => $day,
                'state' => $day?->state() ?? 'off',
                // ⚠️ لحظي — الموظف الشغال دلوقتي رقمه بيزيد
                'worked' => $day ? AttendanceDay::hhmm($day->liveMinutes()) : '—',
                'breaks' => $day ? AttendanceDay::hhmm($day->break_minutes) : '—',
                'in' => $day?->first_in_at,
                'out' => $day?->last_out_at,
                'status' => $day?->status,
                'punches' => $day?->punches()->count() ?? 0,
            ];
        });

        // ═══ فلتر الحالة من كروت السامري الكليكبل (٩/٨) ═══
        //
        // ⚠️ العدّادات بتتحسب من **الكل** قبل الفلترة — كارت «شغال: 5»
        // لازم يفضل 5 وانت واقف جوّاه، وإلا الأرقام بتنط مع كل ضغطة.
        $state = (string) $request->query('state', '');

        $filtered = match ($state) {
            'working' => $rows->where('state', 'working'),
            'break' => $rows->where('state', 'break'),
            'done' => $rows->filter(fn ($r) => $r['state'] === 'off' && ($r['day']?->sessions ?? 0) > 0),
            'off' => $rows->filter(fn ($r) => ($r['day']?->sessions ?? 0) === 0),
            default => $rows,
        };

        return view('erp.attendance_board', [
            'date' => $date,
            'state' => in_array($state, ['working', 'break', 'done', 'off'], true) ? $state : '',
            'rows' => $rows,
            'filtered' => $filtered->values(),
            'working' => $rows->where('state', 'working')->count(),
            'onBreak' => $rows->where('state', 'break')->count(),
            // ⚠️ «مسجّلش حضور» ≠ «خلّص شغله» — الاتنين حالتهم `off`
            // والفرق إن التاني عنده حضور فعلاً. خلطهم كان هيخلي
            // المدير يكلّم ناس خلصت شغلها.
            'notIn' => $rows->filter(fn ($r) => ($r['day']?->sessions ?? 0) === 0)->count(),
            'done' => $rows->filter(fn ($r) => $r['state'] === 'off' && ($r['day']?->sessions ?? 0) > 0)->count(),
            'needsReview' => AttendanceDay::needsReview()->count(),
        ]);
    }

    /** السجل — فترة وفلاتر وإجماليات */
    public function log(Request $request)
    {
        $from = $request->date('from')?->toDateString() ?? today()->startOfMonth()->toDateString();
        $to = $request->date('to')?->toDateString() ?? today()->toDateString();

        $q = AttendanceDay::with(['user:id,name,name_en,code,role', 'approver:id,name,name_en'])
            ->whereBetween('date', [$from, $to]);

        if ($request->filled('user')) {
            $q->where('user_id', $request->integer('user'));
        }

        if ($request->filled('status')) {
            $q->where('status', $request->string('status'));
        }

        $rows = $q->orderByDesc('date')->orderBy('user_id')->get();

        return view('erp.attendance_log', [
            'from' => $from,
            'to' => $to,
            'rows' => $rows,
            'users' => User::where('active', true)->orderBy('name')->get(['id', 'name', 'name_en']),
            'totalMinutes' => $rows->sum(fn ($d) => $d->payableMinutes()),
            'avgMinutes' => $rows->isEmpty() ? 0 : (int) round($rows->avg(fn ($d) => $d->payableMinutes())),
            'needsReview' => AttendanceDay::needsReview()->count(),
        ]);
    }

    /**
     * تصدير السجل إكسيل (٩ أغسطس ٢٠٢٦) — بنفس فلاتر شاشة السجل:
     * موظف واحد أو الكل، من/إلى، والحالة. CSV بـBOM — نفس نمط تصدير
     * المخازن، بيفتح في إكسيل عربي سليم من غير أي مكتبات.
     */
    public function export(Request $request)
    {
        $from = $request->date('from')?->toDateString() ?? today()->startOfMonth()->toDateString();
        $to = $request->date('to')?->toDateString() ?? today()->toDateString();

        $rows = AttendanceDay::with(['user:id,name,name_en,code,role', 'approver:id,name,name_en'])
            ->whereBetween('date', [$from, $to])
            ->when($request->filled('user'), fn ($q) => $q->where('user_id', $request->integer('user')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderBy('date')->orderBy('user_id')
            ->get();

        $filename = 'attendance-'.$from.'-'.$to.'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            // ⚠️ الـBOM هو اللي بيخلّي إكسيل يقرا العربي UTF-8 صح
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, [
                __('hr.date'), __('hr.employee'), __('common.code'), __('hr.role'),
                __('hr.first_in'), __('hr.last_out'), __('hr.breaks'),
                __('hr.worked'), __('hr.sessions'), __('hr.state'), __('hr.approved'),
            ]);

            foreach ($rows as $d) {
                fputcsv($out, [
                    $d->date->format('Y-m-d'),
                    $d->user?->displayName() ?? '—',
                    $d->user?->code ?? '',
                    $d->user?->roleLabel() ?? '',
                    $d->first_in_at?->format('h:i A') ?? '',
                    $d->last_out_at?->format('h:i A') ?? '',
                    AttendanceDay::hhmm($d->break_minutes),
                    // ⚠️ المعتمد لو موجود — نفس الرقم اللي بيروح المرتبات
                    AttendanceDay::hhmm($d->payableMinutes()),
                    $d->sessions,
                    __('hr.status_'.$d->status),
                    $d->approved_at
                        ? ($d->approver?->displayName() ?? '—')
                        : __('hr.not_approved'),
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** قايمة المراجعة — اللي السيستم قفلهم */
    public function review()
    {
        return view('erp.attendance_review', [
            'rows' => AttendanceDay::needsReview()
                ->with(['user:id,name,name_en,code,role', 'punches'])
                ->orderByDesc('date')->get(),
        ]);
    }

    /** اعتماد ساعات يوم */
    public function approve(Request $request, AttendanceDay $day)
    {
        $data = $request->validate([
            // ⚠️ ساعات:دقايق مش رقم عشري — المدير بيفكر بـ«7 ونص»
            // مش بـ7.5، و7.30 كانت هتتقري 7 ساعة و18 دقيقة
            'hours' => ['nullable', 'regex:/^\d{1,2}:[0-5]\d$/'],
            'note' => ['nullable', 'string', 'max:300'],
        ], ['hours.regex' => __('hr.bad_time')]);

        // ⚠️ **كان بلا سكوب فريق** — أي مدير بيعتمد (**ويعدّل ساعات**)
        // أي موظف في الشركة. الساعات دي بتتحول فلوس، فده تعديل مالي.
        // `assertStaff` مش `assertRep`: الحضور بيشمل موظفين مكتب كمان.
        Scope::assertStaff($request->user(), $day->user);

        $minutes = null;

        if (! empty($data['hours'])) {
            [$h, $m] = array_map('intval', explode(':', $data['hours']));
            $minutes = $h * 60 + $m;
        }

        Attendance::approve($day, $request->user(), $minutes, $data['note'] ?? null);

        return back()->with('ok', __('hr.saved'));
    }
}
