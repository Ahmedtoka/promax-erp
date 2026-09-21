<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\ActivityStats;
use App\Support\Access;
use App\Support\Csv;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * ═══════════════════════════════════════════════════════════════
 * مركز نشاط المستخدمين — للأدمن بس (٢٢ سبتمبر ٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * تلات تابات على نفس السجل (`activity_logs`):
 *   now    مين شغال دلوقتي: حالته، في أنهي شاشة وقسم، آخر حاجة عملها
 *   users  ملخص كل يوزر في الفترة: وقت الشغل، الجلسات، الإيقاع، والحكم
 *   log    السجل الكامل بفلاتره (يوزر، رول، حركة، قسم، موديل، مرفوض، فترة)
 * وصفحة اليوزر: أيامه وجلساته وأقسامه وخط زمني بالفجوة بين كل حركتين.
 *
 * ⚠️ **قراءة فقط.** مفيش تعديل ولا مسح من الشاشة: سجل بيتعدّل مش سجل.
 * ⚠️ الفترة متقيدة بـ92 يوم — الملخصات بتتحسب في PHP على صفوف الفترة.
 */
class ActivityController extends Controller
{
    private const MAX_DAYS = 92;

    private const EXPORT_CAP = 20000;

    public function index(Request $request)
    {
        return match ($request->string('tab')->value()) {
            'users' => $this->users($request),
            'log' => $this->log($request),
            default => $this->now($request),
        };
    }

    // ═══════════════════ ١. مين شغال دلوقتي ═══════════════════

    private function now(Request $request)
    {
        $start = today();
        $rows = ActivityStats::rows($start, now());

        $lastIds = ActivityLog::where('created_at', '>=', $start)->whereNotNull('user_id')
            ->selectRaw('MAX(id) id')->groupBy('user_id')->pluck('id');
        $last = ActivityLog::whereIn('id', $lastIds)->get()->keyBy('user_id');

        // آخر «شغل فعلي» لكل يوزر — اللي بيتفرج بس آخر حركته فتح صفحة
        $lastWorkIds = ActivityLog::where('created_at', '>=', $start)->whereNotNull('user_id')
            ->whereIn('event', ActivityLog::WORK)
            ->selectRaw('MAX(id) id')->groupBy('user_id')->pluck('id');
        $lastWork = ActivityLog::whereIn('id', $lastWorkIds)->get()->keyBy('user_id');

        $users = User::whereIn('id', array_keys($rows))->get()->keyBy('id');

        $board = [];

        foreach ($rows as $uid => $list) {
            if (! isset($users[$uid])) {
                continue;
            }

            $sum = ActivityStats::summarize($list);
            $sessions = ActivityStats::sessions($list);
            $row = $last[$uid] ?? null;

            $board[] = [
                'user' => $users[$uid],
                'state' => ActivityLog::stateOf($row?->created_at),
                'last' => $row,
                'last_work' => $lastWork[$uid] ?? null,
                'session_start' => $sessions ? end($sessions)['start'] : null,
                'sum' => $sum,
                'app' => $row && str_starts_with((string) $row->url, 'api/'),
            ];
        }

        $order = ['active' => 0, 'idle' => 1, 'away' => 2];
        usort($board, fn ($x, $y) => [$order[$x['state']], -$x['sum']['last']] <=> [$order[$y['state']], -$y['sum']['last']]);

        $count = fn (string $s) => count(array_filter($board, fn ($b) => $b['state'] === $s));
        $todayQ = fn () => ActivityLog::where('created_at', '>=', $start);

        $kpi = [
            'active' => $count('active'),
            'idle' => $count('idle'),
            'away' => $count('away'),
            'work' => $todayQ()->whereIn('event', ActivityLog::WORK)->count(),
            'deleted' => $todayQ()->where('event', 'deleted')->count(),
            'failed' => ActivityLog::routeReady() ? $todayQ()->where('status', '>=', 400)->count() : 0,
        ];

        // يوزرات الويب اللي مافتحوش السيستم خالص النهارده
        $silent = User::where('active', true)->whereIn('role', Access::WEB_ROLES)
            ->whereNotIn('id', array_keys($rows))->orderBy('name')->get();

        if ($state = $request->string('state')->value()) {
            $board = array_values(array_filter($board, fn ($b) => $b['state'] === $state));
        }

        return view('erp.activity', [
            'tab' => 'now', 'board' => $board, 'kpi' => $kpi, 'silent' => $silent,
            'state' => $state, 'filters' => [],
        ]);
    }

    // ═══════════════════ ٢. ملخص اليوزرات ═══════════════════

    private function users(Request $request)
    {
        [$a, $b] = $this->range($request, 6);

        $all = User::where('active', true)
            ->when($request->filled('role'), fn ($q) => $q->where('role', $request->string('role')->value()))
            ->when($request->filled('q'), function ($q) use ($request) {
                $s = $request->string('q')->trim()->value();
                $q->where(fn ($w) => $w->where('name', 'like', "%$s%")
                    ->orWhere('name_en', 'like', "%$s%")->orWhere('code', 'like', "%$s%"));
            })
            ->orderBy('name')->get();

        $rows = ActivityStats::rows($a, $b, $all->pluck('id')->all());
        $webRoles = Access::WEB_ROLES;

        $list = [];

        foreach ($all as $u) {
            $sum = ActivityStats::summarize($rows[$u->id] ?? []);

            // الميداني اللي مالوش ويب ومافيش له حركة مش «مافتحش» — ده طبيعي
            if ($sum['total'] === 0 && ! in_array($u->role, $webRoles, true)) {
                continue;
            }

            $list[] = ['user' => $u, 'sum' => $sum];
        }

        if ($v = $request->string('verdict')->value()) {
            $list = array_values(array_filter($list, fn ($r) => $r['sum']['verdict'] === $v));
        }

        usort($list, fn ($x, $y) => [$y['sum']['minutes'], $y['sum']['total']] <=> [$x['sum']['minutes'], $x['sum']['total']]);

        $verdicts = array_count_values(array_map(fn ($r) => $r['sum']['verdict'], $list));
        $T = ['minutes' => 0, 'total' => 0, 'work' => 0, 'views' => 0, 'sessions' => 0, 'deleted' => 0];

        foreach ($list as $r) {
            foreach ($T as $k => $_) {
                $T[$k] += $r['sum'][$k];
            }
        }

        if ($request->boolean('export')) {
            return $this->exportUsers($list, $T, $a, $b);
        }

        return view('erp.activity', [
            'tab' => 'users', 'list' => $list, 'verdicts' => $verdicts, 'T' => $T,
            'from' => $a->toDateString(), 'to' => $b->toDateString(),
            'roles' => User::where('active', true)->distinct()->orderBy('role')->pluck('role'),
            'filters' => $request->only(['role', 'verdict', 'q']),
        ]);
    }

    private function exportUsers(array $list, array $T, Carbon $a, Carbon $b)
    {
        $rows = array_map(fn ($r) => [
            $r['user']->displayName(), $r['user']->roleLabel(), __('activity.v_'.$r['sum']['verdict']),
            $r['sum']['days'], $r['sum']['sessions'], $r['sum']['minutes'], $r['sum']['total'], $r['sum']['views'],
            $r['sum']['work'], $r['sum']['created'], $r['sum']['updated'], $r['sum']['deleted'], $r['sum']['actions'],
            $r['sum']['work_ratio'].'%', $r['sum']['pace'] ?? '', $r['sum']['longest_break'],
            $r['sum']['last'] ? date('Y-m-d H:i', $r['sum']['last']) : '',
        ], $list);

        return Csv::download('users-activity-'.now()->format('Y-m-d-Hi').'.csv', [
            __('activity.c_user'), __('activity.c_role'), __('activity.c_verdict'), __('activity.c_days'),
            __('activity.c_sessions'), __('activity.c_minutes'), __('activity.c_total'), __('activity.c_views'),
            __('activity.c_work'), __('activity.e_created'), __('activity.e_updated'), __('activity.e_deleted'),
            __('activity.e_action'), __('activity.c_work_ratio'), __('activity.c_pace_sec'),
            __('activity.c_break_min'), __('activity.c_last'),
        ], $rows, [
            __('common.total'), '', '', '', $T['sessions'], $T['minutes'], $T['total'], $T['views'], $T['work'],
            '', '', $T['deleted'], '', '', '', '', '',
        ], Csv::meta(__('activity.tab_users'), $a->toDateString(), $b->toDateString()));
    }

    // ═══════════════════ ٣. السجل الكامل ═══════════════════

    private function log(Request $request)
    {
        $q = $this->logQuery($request);

        if ($request->boolean('export')) {
            return $this->exportLog($q, $request);
        }

        $rows = $q->paginate(60)->withQueryString();

        // الفجوة بتتحسب جوه الصفحة — ومعناها واضح بس لما السجل ليوزر واحد
        if ($request->filled('user')) {
            ActivityStats::withGaps($rows->getCollection());
        }

        $base = fn () => $this->logQuery($request, false);

        return view('erp.activity', [
            'tab' => 'log', 'rows' => $rows,
            'kpi' => [
                'total' => $rows->total(),
                'users' => $base()->distinct()->count('user_id'),
                'work' => $base()->whereIn('event', ActivityLog::WORK)->count(),
                'deleted' => $base()->where('event', 'deleted')->count(),
                'failed' => ActivityLog::routeReady() ? $base()->where('status', '>=', 400)->count() : 0,
            ],
            'users' => User::orderBy('name')->get(['id', 'name', 'name_en', 'code', 'role']),
            'roles' => User::distinct()->orderBy('role')->pluck('role'),
            'models' => ActivityLog::query()->select('subject_type')->whereNotNull('subject_type')
                ->distinct()->orderBy('subject_type')->pluck('subject_type'),
            'filters' => $request->only(['user', 'role', 'event', 'section', 'model', 'failed', 'q', 'from', 'to']),
        ]);
    }

    private function logQuery(Request $request, bool $ordered = true): Builder
    {
        $q = ActivityLog::query()->with('user:id,name,name_en,code,role');

        if ($ordered) {
            $q->latest('id');
        }

        if ($u = $request->integer('user')) {
            $q->where('user_id', $u);
        }
        if ($r = $request->string('role')->value()) {
            $q->where('role', $r);
        }
        if ($e = $request->string('event')->value()) {
            $e === 'work' ? $q->whereIn('event', ActivityLog::WORK) : $q->where('event', $e);
        }
        if ($m = $request->string('model')->value()) {
            $q->where('subject_type', $m);
        }
        if ($request->boolean('failed') && ActivityLog::routeReady()) {
            $q->where('status', '>=', 400);
        }
        if ($sec = $request->string('section')->value()) {
            $this->whereSection($q, $sec);
        }
        if ($s = $request->string('q')->trim()->value()) {
            $q->where(fn ($w) => $w->where('title', 'like', "%$s%")
                ->orWhere('user_name', 'like', "%$s%")
                ->orWhere('url', 'like', "%$s%"));
        }
        // ⚠️ التاريخ بـwhereDate مش between على نص — «من» و«لحد»
        // بيتكتبوا Y-m-d والمقارنة النصية بتسيب أحداث اليوم الأخير بره
        if ($from = $request->date('from')) {
            $q->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->date('to')) {
            $q->whereDate('created_at', '<=', $to);
        }

        return $q;
    }

    /**
     * فلتر القسم: اسم الراوت متخزن في `route` (٢٢/٩)، وقبلها في `title`
     * لصفوف فتح الصفحات والأكشن. صفوف المراقب القديمة مالهاش اسم راوت
     * فمش بتدخل في الفلتر ده — بتبان تحت «كل الأقسام».
     */
    private function whereSection(Builder $q, string $section): void
    {
        if ($section === 'app') {
            $q->where('url', 'like', 'api/%');

            return;
        }

        $prefixes = ActivityStats::prefixesOf($section);
        $cols = ActivityLog::routeReady() ? ['route', 'title'] : ['title'];

        $q->where(function ($w) use ($prefixes, $cols) {
            foreach ($prefixes as $p) {
                foreach ($cols as $col) {
                    $w->orWhere($col, $p)->orWhere($col, 'like', $p.'.%');
                }
            }

            if ($prefixes === []) {
                $w->whereRaw('1 = 0');
            }
        });
    }

    private function exportLog(Builder $q, Request $request)
    {
        $rows = [];

        foreach ($q->limit(self::EXPORT_CAP)->get() as $r) {
            $rows[] = [
                $r->created_at->format('Y-m-d'), $r->created_at->format('H:i:s'),
                $r->user?->displayName() ?? $r->user_name ?? '', $r->role ? __('enums.role.'.$r->role) : '',
                __('activity.e_'.$r->event), ActivityLog::sectionLabel($r->sectionKey()), $r->screenLabel(),
                $this->what($r), $r->status ?? '', $r->ip ?? '', (string) $r->url,
            ];
        }

        return Csv::download('activity-log-'.now()->format('Y-m-d-Hi').'.csv', [
            __('common.date'), __('common.exp_time'), __('activity.c_user'), __('activity.c_role'),
            __('activity.c_event'), __('activity.c_section'), __('activity.c_screen'), __('activity.c_what'),
            __('activity.c_status'), 'IP', 'URL',
        ], $rows, [__('common.total'), '', count($rows), '', '', '', '', '', '', '', ''],
            Csv::meta(__('activity.tab_log'), $request->input('from'), $request->input('to')));
    }

    /** «عمل إيه» في سطر — للتصدير (الشاشة بترسمه بتفاصيل أكتر) */
    private function what(ActivityLog $r): string
    {
        if (in_array($r->event, ['created', 'updated', 'deleted'], true)) {
            $n = $r->changedCount();

            return trim(self::modelLabel($r->subject_type).' '.$r->title.($n ? ' ('.$n.')' : ''));
        }

        return $r->event === 'action' ? self::verbLabel((string) $r->routeName()) : '';
    }

    public static function modelLabel(?string $type): string
    {
        if (! $type) {
            return '';
        }

        $key = 'activity.model_'.$type;

        return __($key) === $key ? $type : __($key);
    }

    /** آخر مقطع في اسم الراوت هو الفعل: store / update / destroy / approve… */
    public static function verbLabel(string $route): string
    {
        $verb = str_contains($route, '.') ? substr($route, strrpos($route, '.') + 1) : $route;
        $key = 'activity.verb_'.$verb;

        return __($key) === $key ? $verb : __($key);
    }

    // ═══════════════════ ٤. صفحة اليوزر ═══════════════════

    public function user(Request $request, User $user)
    {
        [$a, $b] = $this->range($request, 6);

        $raw = ActivityStats::rows($a, $b, [$user->id])[$user->id] ?? [];
        $sum = ActivityStats::summarize($raw);
        $sessions = array_reverse(ActivityStats::sessions($raw));

        // اليوم باليوم
        $byDay = [];

        foreach ($raw as $r) {
            $byDay[date('Y-m-d', $r[0])][] = $r;
        }

        $days = [];

        foreach ($byDay as $d => $list) {
            $days[$d] = ActivityStats::summarize($list);
        }

        krsort($days);

        // الأقسام والشاشات — من صفوف الفترة (اسم الراوت محتاج الموديل نفسه)
        $sections = [];
        $screens = [];

        ActivityLog::where('user_id', $user->id)->whereBetween('created_at', [$a, $b])
            ->orderBy('id')->chunk(2000, function ($chunk) use (&$sections, &$screens) {
                foreach ($chunk as $r) {
                    $sec = $r->sectionKey() ?? '-';
                    $work = in_array($r->event, ActivityLog::WORK, true) ? 1 : 0;
                    $sections[$sec] ??= ['n' => 0, 'work' => 0];
                    $sections[$sec]['n']++;
                    $sections[$sec]['work'] += $work;

                    $scr = $r->screenLabel();
                    $screens[$scr] ??= ['n' => 0, 'work' => 0, 'section' => $sec];
                    $screens[$scr]['n']++;
                    $screens[$scr]['work'] += $work;
                }
            });

        uasort($sections, fn ($x, $y) => $y['n'] <=> $x['n']);
        uasort($screens, fn ($x, $y) => $y['n'] <=> $x['n']);

        $timeline = ActivityLog::where('user_id', $user->id)->whereBetween('created_at', [$a, $b])
            ->when($request->filled('event'), function ($q) use ($request) {
                $e = $request->string('event')->value();
                $e === 'work' ? $q->whereIn('event', ActivityLog::WORK) : $q->where('event', $e);
            })
            ->when($request->filled('day'), fn ($q) => $q->whereDate('created_at', $request->input('day')))
            ->latest('id')->paginate(100)->withQueryString();

        ActivityStats::withGaps($timeline->getCollection());

        $lastRow = ActivityLog::where('user_id', $user->id)->latest('id')->first();

        return view('erp.activity_user', [
            'user' => $user, 'sum' => $sum, 'sessions' => array_slice($sessions, 0, 60), 'days' => $days,
            'sections' => $sections, 'screens' => array_slice($screens, 0, 15, true), 'timeline' => $timeline,
            'from' => $a->toDateString(), 'to' => $b->toDateString(),
            'state' => ActivityLog::stateOf($lastRow?->created_at), 'lastRow' => $lastRow,
            'filters' => $request->only(['event', 'day']),
        ]);
    }

    /**
     * فترة الملخصات: الافتراضي آخر `$back`+1 يوم، ومتقيدة بـ92 يوم.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function range(Request $request, int $back): array
    {
        $b = rescue(fn () => $request->date('to'), null, false) ?? today();
        $a = rescue(fn () => $request->date('from'), null, false) ?? $b->copy()->subDays($back);

        if ($a->gt($b)) {
            [$a, $b] = [$b, $a];
        }

        if ($a->diffInDays($b) > self::MAX_DAYS) {
            $a = $b->copy()->subDays(self::MAX_DAYS);
        }

        return [$a->copy()->startOfDay(), $b->copy()->endOfDay()];
    }
}
