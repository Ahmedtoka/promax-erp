<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Client;
use App\Models\JourneyPlan;
use App\Models\TrackEvent;
use App\Models\User;
use App\Models\Visit;
use App\Models\Zone;
use App\Services\Journeys;
use App\Support\Governorates;
use App\Support\Scope;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * خطط السير + تخصيص المناطق والعملاء + الشاشة اللايف.
 *
 * ⚠️ التلاتة في كنترولر واحد لأنهم نفس الموضوع: مين بيزور مين وإمتى.
 * تفريقهم بيخلّي التخصيص في مكان والخطة في مكان، وبيحصل إن مندوب
 * ياخد عميل مش في زونه ومحدش ياخد باله.
 */
class JourneyController extends Controller
{
    // ═══════════════════════ خطط السير ═══════════════════════

    public function index(Request $request)
    {
        // ⚠️ سكوب الفرع — مدير المعادي بيشوف فريق المعادي بس
        // (١١/١١) المصدر بقى `RoutePool::reps` — نفس قايمة الراسم والمتابعة
        $viewer = $request->user();
        $reps = \App\Services\RoutePool::reps($viewer);

        $rep = $request->filled('rep')
            ? $reps->firstWhere('id', (int) $request->input('rep'))
            : $reps->first();

        $wipeCount = $this->wipeQuery($viewer)->count();

        // ═══ الأسبوع من السبت للجمعة (١١/١١ — نفس جدولة المحتملين) ═══
        // ⚠️ للعرض بس: الخطة نمط أسبوعي — الأسبوع بيحدد التواريخ فوق
        // الأعمدة وإيه «مستحق» فيه (كل أسبوعين/شهري).
        try {
            $weekStart = $request->filled('week')
                ? Carbon::parse((string) $request->input('week'))->startOfWeek(Carbon::SATURDAY)
                : today()->startOfWeek(Carbon::SATURDAY);
        } catch (\Throwable) {
            $weekStart = today()->startOfWeek(Carbon::SATURDAY);
        }

        if ($rep === null) {
            return view('ops.journeys', [
                'reps' => $reps, 'rep' => null, 'week' => [],
                'available' => collect(), 'weekdays' => JourneyPlan::WEEKDAYS,
                'frequencies' => JourneyPlan::FREQUENCIES, 'today' => today()->dayOfWeek,
                'wipeCount' => $wipeCount,
                'weekStart' => $weekStart,
                'board' => ['pool' => [], 'plans' => []],
            ]);
        }

        $week = Journeys::week($rep);
        $weekPlans = collect($week)->flatten();

        // ⚠️ المتاح للإضافة = بول المندوب اللي **لسه** مش في خطته
        $planned = $weekPlans->pluck('client_id')->unique();

        $available = \App\Services\RoutePool::poolQuery($rep, $viewer)
            ->whereNotIn('id', $planned)
            ->orderBy('name')
            ->get();

        \Illuminate\Database\Eloquent\Collection::make(
            $weekPlans->pluck('client')->filter()->unique('id')->values()
        )->loadMissing(['zone', 'channel', 'group']);

        $lastVisitAt = \App\Services\RoutePool::lastVisits(
            $available->pluck('id')->merge($weekPlans->pluck('client_id'))
        );

        $clientRow = fn (Client $c) => \App\Services\RoutePool::row($c, $lastVisitAt);

        // تاريخ كل يوم في الأسبوع المعروض (السبت أول عمود)
        $dateOf = fn (int $wd) => $weekStart->copy()->addDays(($wd - Carbon::SATURDAY + 7) % 7);

        $board = [
            'pool' => $available->map($clientRow)->values()->all(),
            'plans' => $weekPlans->map(fn (JourneyPlan $p) => [
                'id' => $p->id,
                'weekday' => (int) $p->weekday,
                'sort' => (int) $p->sort,
                'every_weeks' => (int) $p->every_weeks,
                'starts_on' => $p->starts_on?->toDateString(),
                'visit_at' => $p->visit_at ? substr((string) $p->visit_at, 0, 5) : '',
                'time' => $p->visitTimeLabel(),
                // مستحقة في الأسبوع المعروض؟ — الغير مستحقة بتترسم باهتة
                'due' => $p->dueOn($dateOf((int) $p->weekday)),
                'client' => $p->client !== null ? $clientRow($p->client) : null,
            ])->filter(fn ($r) => $r['client'] !== null)->values()->all(),
        ];

        // ═══ عرض الشهر — النمط الأسبوعي مفرود على تواريخ حقيقية ═══
        $month = (string) $request->input('month', $weekStart->copy()->addDays(3)->format('Y-m'));

        try {
            $monthStart = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        } catch (\Throwable) {
            $monthStart = today()->startOfMonth();
        }

        // الزيارات المقفولة في الشهر — عشان الماضي يتلون: اتزار/فات
        $monthVisits = Visit::where('user_id', $rep->id)
            ->whereBetween('created_at', [
                $monthStart->copy()->startOfDay(),
                $monthStart->copy()->endOfMonth()->endOfDay(),
            ])
            ->whereNotNull('checked_out_at')
            ->get()
            ->groupBy(fn ($v) => $v->created_at->toDateString().'|'.$v->client_id);

        $calendar = [];

        for ($d = $monthStart->copy(); $d->month === $monthStart->month; $d->addDay()) {
            $key = $d->toDateString();

            $calendar[$key] = $weekPlans
                ->filter(fn (JourneyPlan $p) => $p->dueOn($d->copy()))
                ->map(fn (JourneyPlan $p) => [
                    'name' => $p->client?->fullName() ?? '—',
                    'done' => $monthVisits->has($key.'|'.$p->client_id),
                ])->values()->all();
        }

        return view('ops.journeys', [
            'reps' => $reps,
            'rep' => $rep,
            'week' => $week,
            'available' => $available,
            'weekdays' => JourneyPlan::WEEKDAYS,
            'frequencies' => JourneyPlan::FREQUENCIES,
            'today' => today()->dayOfWeek,
            'monthStart' => $monthStart,
            'calendar' => $calendar,
            'wipeCount' => $wipeCount,
            'weekStart' => $weekStart,
            'board' => $board,
        ]);
    }

    /**
     * كويري خطط السير اللي الفاعل مسموح له يمسحها — مصدر واحد
     * للعدّ في الشاشة وللمسح نفسه، عشان الرقم في رسالة التأكيد
     * يبقى هو نفسه اللي بيتمسح فعلاً.
     */
    private function wipeQuery(?User $viewer)
    {
        $q = JourneyPlan::query();

        if ($viewer === null) {
            return $q->whereRaw('1 = 0');
        }

        // ⚠️ الأدمن بس بيمسح الشركة كلها. أي حد تاني — مدير أو رول اتمنح
        // `act.field.wipe` — فريقه بس زي المدير بالظبط: مناديبه (`manager_id`)
        // + نفسه. من غير مناديب = مفيش حاجة تتمسح (مش «الكل»).
        if (! $viewer->isAdmin()) {
            $q->whereIn('user_id', User::query()
                ->where(fn ($w) => $w->where('manager_id', $viewer->id)->orWhere('id', $viewer->id))
                ->select('id'));
        }

        return $q;
    }

    /**
     * ═══ مسح كل خطوط السير (طلب المالك ١١/٨) — «أعملها من أول وجديد» ═══
     *
     * ⚠️ **آمن على تاريخ الزيارات**: `visits.journey_plan_id` معرّف
     * بـ`nullOnDelete` في مايجريشن `000018_leads_and_journeys` —
     * مسح الخطة بيصفّر اللينك بس، والزيارة نفسها (وفلوسها وقيودها)
     * بتفضل زي ما هي. لو الـFK كان cascade كان الزرار ده هياكل سجل
     * الزيارات كله — اتفحص قبل ما يتبني.
     *
     * السكوب: الأدمن بيمسح كل الخطط، والمدير خطط فريقه بس. أي رول
     * تاني وصله الراوت باستثناء Access مايعملش مسح جماعي — 403.
     */
    public function wipe(Request $request)
    {
        $viewer = $request->user();

        abort_unless(\App\Support\Access::action($viewer, 'act.field.wipe'), 403);

        $deleted = 0;

        DB::transaction(function () use ($viewer, &$deleted) {
            $deleted = (int) $this->wipeQuery($viewer)->delete();
        });

        return back()->with('ok', __('journey.wiped', ['count' => $deleted]));
    }

    /**
     * ═══ حفظ بورد الدراج أند دروب دفعة واحدة (٢١/٨) ═══
     *
     * الشاشة الجديدة بتتحرّك كلها في المتصفح (سحب من السايدبار، نقل
     * بين الأيام، إعادة ترتيب، شيل) و«احفظ ونشّط الخطة» بيبعت الصورة
     * النهائية هنا — معاملة واحدة بتسوّي الفرق: صف ليه `id` → تحديث
     * يومه وترتيبه · من غير `id` → إنشاء · خطة نشطة مش في الصورة → مسح.
     *
     * ⚠️ **التسوية بمعرّف الخطة مش بالعميل** — العميل ممكن يكون له
     * خطتين في يومين (زيارة مرتين في الأسبوع) والاتنين لازم يعيشوا.
     * ⚠️ الخطط الموجودة بتحتفظ بترددها ووقتها (`every_weeks` /
     * `starts_on` / `visit_at`) — البورد بيغيّر اليوم والترتيب بس.
     * ⚠️ **الموقوفة (`active = false`) مابتتلمسش** — البورد مابيعرضهاش
     * فمالوش حق يمسحها.
     * ⚠️ نفس حراس `store` بالحرف على أي عميل **جديد**.
     */
    public function sync(Request $request)
    {
        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'rows' => ['present', 'array'],
            'rows.*.id' => ['nullable', 'integer', 'exists:journey_plans,id'],
            'rows.*.client_id' => ['required', 'integer', 'exists:clients,id'],
            'rows.*.weekday' => ['required', 'integer', 'between:0,6'],
            'rows.*.sort' => ['required', 'integer', 'min:1'],
            // ═══ (١١/١١) التردد وأول زيارة والوقت من البورد نفسه ═══
            // اختياريين — بوست من غيرهم بيسيب القيم الموجودة زي ما هي
            'rows.*.every_weeks' => ['nullable', 'integer', 'in:1,2,4'],
            'rows.*.starts_on' => ['nullable', 'date_format:Y-m-d'],
            'rows.*.visit_at' => ['nullable', 'date_format:H:i'],
        ]);

        $rep = User::with('zones')->findOrFail($data['user_id']);

        Scope::assertRep($request->user(), $rep);

        $rows = collect($data['rows']);
        $existing = JourneyPlan::where('user_id', $rep->id)
            ->where('active', true)->get()->keyBy('id');

        // ⚠️ أي `id` جاي في البوست لازم يكون من خطط المندوب ده نفسه —
        // `exists:` بتتأكد من الوجود بس، وبوست معدّل كان يحرّك خطة
        // مندوب تاني.
        abort_if(
            $rows->pluck('id')->filter()
                ->contains(fn ($id) => ! $existing->has((int) $id)),
            422,
        );

        // الحراس على العملاء الجداد بس — الموجودين اتفحصوا يوم ما اتضافوا
        $newIds = $rows->whereNull('id')->pluck('client_id')->unique();

        foreach (Client::whereIn('id', $newIds)->get() as $c) {
            Scope::assertClient($request->user(), $c);
            Scope::assertSameTeam($rep, $c);
            Scope::assertInZone($rep, $c);
        }

        $added = 0;
        $removed = 0;

        DB::transaction(function () use ($rows, $existing, $rep, &$added, &$removed) {
            // اللي اتشال من البورد — النشط بس
            $keepIds = $rows->pluck('id')->filter()->map(fn ($v) => (int) $v);

            $removed = JourneyPlan::where('user_id', $rep->id)
                ->where('active', true)
                ->whereNotIn('id', $keepIds)
                ->delete();

            foreach ($rows as $r) {
                $sched = self::boardSchedule($r);

                // ⚠️ أول زيارة بتحدد اليوم — السيرفر بيشتق اليوم منها
                if (! empty($sched['starts_on'])) {
                    $r['weekday'] = Carbon::createFromFormat('Y-m-d', $sched['starts_on'])->dayOfWeek;
                }

                if (! empty($r['id'])) {
                    $existing->get((int) $r['id'])?->update($sched + [
                        'weekday' => (int) $r['weekday'],
                        'sort' => (int) $r['sort'],
                    ]);

                    continue;
                }

                // ⚠️ `firstOrCreate` — اليونيك (مندوب×عميل×يوم) ممكن
                // يتصادم لو نفس العميل اتسحب مرتين لنفس اليوم
                $plan = JourneyPlan::firstOrCreate(
                    [
                        'user_id' => $rep->id,
                        'client_id' => (int) $r['client_id'],
                        'weekday' => (int) $r['weekday'],
                    ],
                    $sched + [
                        'every_weeks' => 1,
                        'sort' => (int) $r['sort'],
                        'active' => true,
                    ],
                );

                if ($plan->wasRecentlyCreated) {
                    $added++;
                }
            }
        });

        return back()->with('ok', __('journey.board_saved', [
            'total' => $rows->count(),
            'added' => $added,
            'removed' => $removed,
        ]));
    }

    /**
     * حقول الجدولة اللي البورد بعتها فعلاً — المفتاح الغايب ما بيتلمسش.
     * فاضي = null (المستخدم مسح التاريخ/الوقت).
     */
    private static function boardSchedule(array $r): array
    {
        $out = [];

        if (array_key_exists('every_weeks', $r) && $r['every_weeks'] !== null && $r['every_weeks'] !== '') {
            $out['every_weeks'] = (int) $r['every_weeks'];
        }

        foreach (['starts_on', 'visit_at'] as $k) {
            if (array_key_exists($k, $r)) {
                $out[$k] = ($r[$k] ?? '') === '' ? null : $r[$k];
            }
        }

        return $out;
    }

    /**
     * ═══ انسخ الخطة من مندوب تاني (٢١/٨) ═══
     *
     * بينسخ النمط الأسبوعي كله (اليوم والترتيب والتردد) — والعميل
     * اللي مش من فريق/زون المستقبِل **بيتتخطى** مش بيوقّع العملية:
     * الهدف بداية سريعة مش نقل ملكية عملاء.
     */
    public function copyFrom(Request $request)
    {
        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'from_id' => ['required', 'different:user_id', 'exists:users,id'],
        ]);

        $rep = User::with('zones')->findOrFail($data['user_id']);
        $from = User::findOrFail($data['from_id']);

        Scope::assertRep($request->user(), $rep);
        Scope::assertRep($request->user(), $from);

        $added = 0;
        $skipped = 0;

        DB::transaction(function () use ($rep, $from, &$added, &$skipped) {
            $sorts = [];

            foreach (JourneyPlan::with('client')
                ->where('user_id', $from->id)->where('active', true)
                ->orderBy('weekday')->orderBy('sort')->get() as $p) {
                $c = $p->client;

                if ($c === null) {
                    $skipped++;

                    continue;
                }

                try {
                    Scope::assertSameTeam($rep, $c);
                    Scope::assertInZone($rep, $c);
                } catch (\Throwable) {
                    $skipped++;

                    continue;
                }

                $sorts[$p->weekday] ??= (int) JourneyPlan::where('user_id', $rep->id)
                    ->where('weekday', $p->weekday)->max('sort');

                $plan = JourneyPlan::firstOrCreate(
                    [
                        'user_id' => $rep->id,
                        'client_id' => $p->client_id,
                        'weekday' => $p->weekday,
                    ],
                    [
                        'every_weeks' => $p->every_weeks,
                        'sort' => ++$sorts[$p->weekday],
                        'active' => true,
                    ],
                );

                $plan->wasRecentlyCreated ? $added++ : $skipped++;
            }
        });

        return back()->with('ok', __('journey.copied', [
            'added' => $added,
            'skipped' => $skipped,
        ]));
    }

    // ═══════════════════════ تخصيص المناطق والعملاء ═══════════════════════

    /**
     * ═══════════════════════════════════════════════════════════
     * تخصيص العملاء والمناطق — الويزارد (إعادة بناء ٢٤ أغسطس ٢٠٢٦)
     * ═══════════════════════════════════════════════════════════
     *
     * موكاب المالك: «مندوب واحد · خطوة واحدة · مفيش حفظ من غير ما
     * تشوف الأثر». ٤ خطوات: اختار المندوب ← حدّد مناطقه (شجرة
     * محافظات بتشيك) ← راجع العملاء (فلاتر وتعارضات) ← الملخّص
     * والحفظ. وجنبها: حمولة الفريق + صحة التغطية — عشان المدير
     * والأدمن يعرفوا في أي لحظة «مين معاه إيه وإيه متغطي وإيه لأ».
     *
     * الداتا كلها بتتبعت JSON للمتصفح والخطوات جافاسكربت خالص —
     * الحفظ POST واحد (assignments.apply) بيكتب rep_id + zone_user
     * وبينده Coverage.
     */
    public function assignments(Request $request)
    {
        $viewer = $request->user();

        // ═══ تابات المديرين — الأدمن الكل والمدير كارته هو بس ═══
        $managers = Branch::scope(User::where('role', 'manager')->where('active', true))
            ->when($viewer?->role === 'manager', fn ($q) => $q->whereKey($viewer->id))
            ->orderBy('name')
            ->get();

        $mgrCounts = Client::whereIn('manager_id', $managers->pluck('id'))
            ->where('status', 'active')
            ->selectRaw('manager_id, COUNT(*) as n')
            ->groupBy('manager_id')
            ->pluck('n', 'manager_id');

        $manager = ($request->filled('manager')
                ? $managers->firstWhere('id', (int) $request->input('manager'))
                : null)
            ?? $managers->sortByDesc(fn ($m) => (int) ($mgrCounts[$m->id] ?? 0))->first();

        if ($manager === null) {
            return view('ops.assignments', [
                'managers' => collect(), 'mgrCounts' => collect(), 'manager' => null,
                'team' => [], 'zoneRows' => [], 'clientRows' => [], 'repZones' => [],
                'health' => ['orphans' => 0, 'unmarked' => 0, 'empty' => 0, 'nozone' => 0],
                'totals' => ['team' => 0, 'clients' => 0, 'zones' => 0, 'loaded' => 0],
            ]);
        }

        // ═══ الفريق الميداني بأعداده ═══
        $team = User::where('manager_id', $manager->id)
            ->whereIn('role', User::FIELD_ROLES)
            ->where('active', true)
            ->orderBy('name')
            ->get();

        $teamIds = $team->pluck('id');

        $repClientCounts = Client::where('status', 'active')
            ->whereIn('rep_id', $teamIds)
            ->selectRaw('rep_id, COUNT(*) as n')
            ->groupBy('rep_id')
            ->pluck('n', 'rep_id');

        // ⚠️ «زيارة» في الكارت = بنود خط السير النشطة — مش زيارات فعلية
        $repVisitCounts = JourneyPlan::whereIn('user_id', $teamIds)
            ->where('active', true)
            ->selectRaw('user_id, COUNT(*) as n')
            ->groupBy('user_id')
            ->pluck('n', 'user_id');

        $zoneUserRows = DB::table('zone_user')->whereIn('user_id', $teamIds)
            ->get(['user_id', 'zone_id']);

        // خريطة مناطق كل مندوب — التشيك بوكسات بتبدأ منها
        $repZones = $zoneUserRows->groupBy('user_id')
            ->map(fn ($g) => $g->pluck('zone_id')->map(fn ($z) => (int) $z)->values()->all())
            ->all();

        // ═══ عملاء بول المدير ═══
        // ⚠️ السقف 900 مش صامت — الفيو بيقارن بالعدد الحقيقي وبيقول
        // «+N مش معروضين» (فخ الـslice الصامتة — ٢٢/٨)
        $poolTotal = (int) ($mgrCounts[$manager->id] ?? 0);

        $clients = Client::where('manager_id', $manager->id)
            ->where('status', 'active')
            ->with(['zone', 'group', 'rep'])
            ->orderBy('name')
            ->limit(900)
            ->get();

        $clientRows = $clients->map(fn ($c) => [
            'id' => $c->id,
            'name' => $c->fullName(),
            'en' => (string) $c->name_en,
            'zone_id' => $c->zone_id ? (int) $c->zone_id : null,
            'zone' => $c->zone?->displayName() ?? '',
            'rep_id' => $c->rep_id ? (int) $c->rep_id : null,
            'rep' => $c->rep?->name ?? '',
        ])->values()->all();

        // ═══ شجرة المناطق: مناطق البول + المتعلّمة للفريق ═══
        $zoneIds = $clients->pluck('zone_id')->filter()
            ->merge($zoneUserRows->pluck('zone_id'))
            ->unique()->values();

        $zones = Zone::whereIn('id', $zoneIds)->orderBy('name')->get();

        $zoneClientCounts = $clients->whereNotNull('zone_id')->groupBy('zone_id');

        $zoneRows = $zones->map(function (Zone $z) use ($zoneClientCounts, $zoneUserRows, $team) {
            $in = $zoneClientCounts->get($z->id, collect());
            $markedBy = $zoneUserRows->where('zone_id', $z->id)->pluck('user_id')
                ->map(fn ($u) => (int) $u)->values();

            return [
                'id' => (int) $z->id,
                'name' => $z->displayName(),
                'gov' => Governorates::label($z->governorate),
                'active' => (bool) $z->active,
                'clients' => $in->count(),
                'orphans' => $in->whereNull('rep_id')->count(),
                'marked_by' => $markedBy->all(),
                'marked_names' => $team->whereIn('id', $markedBy)->pluck('name')->values()->all(),
            ];
        })->values()->all();

        // ═══ صحة التغطية — نفس قواعد فاحص الحلقات ═══
        $health = [
            'orphans' => $clients->whereNull('rep_id')->count(),
            // مسؤول عن العميل بس منطقته مش متعلّمة له — اختفاء صامت
            'unmarked' => $clients->filter(fn ($c) => $c->rep_id && $c->zone_id
                && ! in_array((int) $c->zone_id, $repZones[$c->rep_id] ?? [], true))->count(),
            'empty' => $team->filter(fn ($r) => (int) ($repClientCounts[$r->id] ?? 0) === 0)->count(),
            'nozone' => $clients->whereNull('zone_id')->count(),
        ];

        return view('ops.assignments', [
            'managers' => $managers,
            'mgrCounts' => $mgrCounts,
            'manager' => $manager,
            'team' => $team->map(fn (User $r) => [
                'id' => (int) $r->id,
                'name' => $r->name,
                'label' => $r->roleLabel(),
                'clients' => (int) ($repClientCounts[$r->id] ?? 0),
                'zones' => count($repZones[$r->id] ?? []),
                'visits' => (int) ($repVisitCounts[$r->id] ?? 0),
            ])->values()->all(),
            'zoneRows' => $zoneRows,
            'clientRows' => $clientRows,
            'repZones' => $repZones,
            'health' => $health,
            'totals' => [
                'team' => $team->count(),
                'clients' => $poolTotal,
                'zones' => count($zoneRows),
                'loaded' => count($clientRows),
            ],
        ]);
    }

    /**
     * ═══ حفظ الويزارد (٢٤/٨) — POST واحد للمندوب المختار ═══
     *
     * • مناطق المندوب بتتساوى بالمتعلّم بالظبط (`sync`) — الشاشة دي
     *   هي مصدر الحقيقة لتوزيع المناطق، والشيل هنا قرار صريح
     *   بيتعرض في الملخّص قبل الحفظ.
     * • العملاء المتعلّم عليهم بيتحوّل مسؤولهم (`rep_id`) للمندوب —
     *   **جوه بول نفس المدير بس**، مفيش نقل بين فرق المديرين.
     * • `Coverage::sync` لكل عميل اتنقل — التسكين بيجرّ التغطية.
     */
    public function applyAssignments(Request $request)
    {
        $data = $request->validate([
            'rep_id' => ['required', 'exists:users,id'],
            'zones' => ['array'],
            'zones.*' => ['integer', 'exists:zones,id'],
            'clients' => ['array'],
            'clients.*' => ['integer', 'exists:clients,id'],
        ]);

        $rep = User::findOrFail((int) $data['rep_id']);

        // نفس حارس التسكين القديم — الرول والنشاط والفريق
        Scope::assertRep($request->user(), $rep);

        $zoneIds = collect($data['zones'] ?? [])->map(fn ($z) => (int) $z)->unique()->values();
        $moved = 0;

        DB::transaction(function () use ($rep, $data, $zoneIds, &$moved) {
            $rep->zones()->sync($zoneIds->all());

            // المناطق المتعلّمة الموقوفة بتتفعّل — سلسلة الظهور
            Zone::whereIn('id', $zoneIds)->where('active', false)->update(['active' => true]);

            foreach (Client::whereIn('id', $data['clients'] ?? [])->get() as $c) {
                // ⚠️ مفيش نقل بين فرق المديرين — قاعدة الموكاب نفسها
                if ((int) $c->manager_id !== (int) $rep->manager_id) {
                    continue;
                }

                $c->update(['rep_id' => $rep->id]);
                \App\Services\Coverage::sync($c->fresh());
                $moved++;
            }
        });

        return back()->with('ok', __('assign.saved', [
            'name' => $rep->name,
            'clients' => $moved,
            'zones' => $zoneIds->count(),
        ]));
    }

    /**
     * ═══ «رشّح مندوب لكلٍ حسب زونه» (٢٤/٨) ═══
     *
     * كل عميل من غير مسؤول وله منطقة: بيتسكّن على المندوب اللي
     * منطقته متعلّمة له — ولو أكتر من واحد، الأقل حمولة. اللي
     * منطقته مش متعلّمة لحد (أو من غير منطقة) بيتساب زي ما هو
     * والرسالة بتقول عددهم — مفيش توزيع عشوائي.
     */
    public function autoAssignReps(Request $request)
    {
        $viewer = $request->user();

        $manager = Branch::scope(User::where('role', 'manager')->where('active', true))
            ->when($viewer?->role === 'manager', fn ($q) => $q->whereKey($viewer->id))
            ->findOrFail((int) $request->input('manager_id'));

        $team = User::where('manager_id', $manager->id)
            ->whereIn('role', User::FIELD_ROLES)
            ->where('active', true)
            ->get();

        $zoneMap = DB::table('zone_user')->whereIn('user_id', $team->pluck('id'))
            ->get(['user_id', 'zone_id'])
            ->groupBy('zone_id')
            ->map(fn ($g) => $g->pluck('user_id')->map(fn ($u) => (int) $u)->all());

        $loads = Client::where('status', 'active')
            ->whereIn('rep_id', $team->pluck('id'))
            ->selectRaw('rep_id, COUNT(*) as n')
            ->groupBy('rep_id')
            ->pluck('n', 'rep_id')
            ->map(fn ($n) => (int) $n)
            ->all();

        $orphans = Client::where('manager_id', $manager->id)
            ->where('status', 'active')
            ->whereNull('rep_id')
            ->get();

        $assigned = 0;
        $skipped = 0;

        DB::transaction(function () use ($orphans, $zoneMap, &$loads, &$assigned, &$skipped) {
            foreach ($orphans as $c) {
                $candidates = $c->zone_id ? ($zoneMap[$c->zone_id] ?? []) : [];

                if ($candidates === []) {
                    $skipped++;

                    continue;
                }

                // الأقل حمولة بياخد — التوزيع يفضل متوازن
                $best = collect($candidates)->sortBy(fn ($id) => $loads[$id] ?? 0)->first();

                $c->update(['rep_id' => $best]);
                \App\Services\Coverage::sync($c->fresh());
                $loads[$best] = ($loads[$best] ?? 0) + 1;
                $assigned++;
            }
        });

        return back()->with('ok', __('assign.auto_done', [
            'assigned' => $assigned,
            'skipped' => $skipped,
        ]));
    }

    /**
     * ═══ كروت بول الفريق (قرار المالك ١١ أغسطس ٢٠٢٦ مساءً) ═══
     *
     * «سكّن كل عملاء مدير التشانل بكل مناديبه وكل مناطقه» — الفصل
     * الأساسي بقى على مستوى المدير: كارت لكل مدير بيوري مناديبه
     * (صور)، عدد عملائه، ومناطق عملائه بعدد العميل في كل واحدة —
     * وكله «معلّم» لأن البول مشترك بالقاعدة مش بالتسكين. التسكين
     * الفردي تحت فضل شغّال بس بقى «المسؤول الأساسي» (التارجت).
     *
     * ⚠️ سكوب: المدير بيشوف كارته هو بس — نفس `Client::visibleTo`.
     *
     * @return list<array{manager: User, reps: \Illuminate\Support\Collection,
     *   client_count: int, zones: list<array{name: string, count: int}>}>
     */
    private function managerPools(?User $viewer): array
    {
        $managers = Branch::scope(User::where('role', 'manager')->where('active', true))
            ->when($viewer !== null && $viewer->role === 'manager',
                fn ($q) => $q->whereKey($viewer->id))
            ->orderBy('name')
            ->get();

        if ($managers->isEmpty()) {
            return ['cards' => [], 'orphans' => 0, 'total' => 0];
        }

        // كويريز مجمّعة — مش كويري لكل مدير
        $reps = User::whereIn('manager_id', $managers->pluck('id'))
            ->whereIn('role', User::FIELD_ROLES)
            ->where('active', true)
            ->orderBy('name')
            ->get()
            ->groupBy('manager_id');

        $clientCounts = Client::whereIn('manager_id', $managers->pluck('id'))
            ->where('status', 'active')
            ->selectRaw('manager_id, COUNT(*) as n')
            ->groupBy('manager_id')
            ->pluck('n', 'manager_id');

        // عدد عملاء كل مندوب (المسؤول الأساسي) — تحت اسمه في التاب (١١/٨)
        $repClientCounts = Client::where('status', 'active')
            ->whereNotNull('rep_id')
            ->selectRaw('rep_id, COUNT(*) as n')
            ->groupBy('rep_id')
            ->pluck('n', 'rep_id');

        // «بدون مندوب» جوه بول كل مدير — عملاء الفريق اللي لسه
        // مالهمش مسؤول أساسي؛ المالك بيبدأ منهم التسكين
        $noRepCounts = Client::whereIn('manager_id', $managers->pluck('id'))
            ->where('status', 'active')
            ->whereNull('rep_id')
            ->selectRaw('manager_id, COUNT(*) as n')
            ->groupBy('manager_id')
            ->pluck('n', 'manager_id');

        $zoneRows = Client::whereIn('manager_id', $managers->pluck('id'))
            ->where('status', 'active')
            ->whereNotNull('zone_id')
            ->selectRaw('manager_id, zone_id, COUNT(*) as n')
            ->groupBy('manager_id', 'zone_id')
            ->get();

        $zoneNames = Zone::whereIn('id', $zoneRows->pluck('zone_id')->unique())
            ->get()
            ->keyBy('id');

        $cards = $managers->map(fn (User $m) => [
            'manager' => $m,
            // كل مندوب ومعاه عدد عملاءه (المسؤول الأساسي) — والمدير
            // نفسه ضمن الفريق لو ليه عملاء أساسية
            'reps' => $reps->get($m->id, collect())
                ->map(fn (User $r) => ['user' => $r, 'clients' => (int) ($repClientCounts[$r->id] ?? 0)]),
            'manager_own' => (int) ($repClientCounts[$m->id] ?? 0),
            'client_count' => (int) ($clientCounts[$m->id] ?? 0),
            'no_rep' => (int) ($noRepCounts[$m->id] ?? 0),
            'zones' => $zoneRows->where('manager_id', $m->id)
                ->sortByDesc('n')
                ->map(fn ($r) => [
                    'name' => $zoneNames->get($r->zone_id)?->displayName() ?? '—',
                    'count' => (int) $r->n,
                ])->values()->all(),
        ])->values()->all();

        // ═══ سطر الجمع (طلب المالك ١١/٨ مساءً): عمرو + محمد + بدون
        // فريق = الإجمالي. من غيره الكروت كانت بتبان «مش مجمّعة» —
        // والفرق هو العملاء اللي `manager_id` بتاعهم فاضي.
        $orphans = (int) Branch::scope(Client::query())
            ->where('status', 'active')
            ->whereNull('manager_id')
            ->count();

        return [
            'cards' => $cards,
            'orphans' => $orphans,
            'total' => array_sum(array_column($cards, 'client_count')) + $orphans,
        ];
    }

    public function assign(Request $request)
    {
        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'client_ids' => ['array'],
            'client_ids.*' => ['integer', 'exists:clients,id'],
            'zone_ids' => ['array'],
            'zone_ids.*' => ['integer', 'exists:zones,id'],
        ]);

        $rep = User::with('zones')->findOrFail($data['user_id']);
        $clients = 0;

        // ⚠️ بره الكلوجر — `$request` مش متمرّر جواه
        $syncZones = $request->boolean('zones_form');

        // ═══ الحارس (تدقيق ٨/٨/٢٠٢٦) ═══
        // ⚠️ **دي كانت جذر السيناريو اللي حصل**: الشاشة مابتتحققش لا
        // من سكوب المندوب ولا سكوب العميل ولا اتساق المنطقة، فعميل
        // كان بيتسكّن على مندوب مدير تاني في منطقة تانية.
        Scope::assertRep($request->user(), $rep);

        if (! empty($data['client_ids'])) {
            $offenders = [];

            foreach (Client::whereIn('id', $data['client_ids'])->get() as $c) {
                Scope::assertClient($request->user(), $c);
                Scope::assertSameTeam($rep, $c);

                // ⚠️ المناطق اللي بتتسكّن في **نفس** الريكوست بتتحسب،
                // وإلا أول تسكين لمندوب جديد بيترفض.
                if (! Scope::inZone($rep, $c, $syncZones ? ($data['zone_ids'] ?? []) : null)) {
                    $offenders[] = $c->fullName();
                }
            }

            // ⚠️ الأسماء بتترجع للمستخدم — رسالة «مش في المنطقة» من
            // غير اسم العميل بتخلّي الشاشة مسدودة بلا سبب واضح.
            if ($offenders !== []) {
                return back()->withErrors([
                    'client_ids' => __('perm.scope_zone_denied').' — '.implode('، ', $offenders),
                ]);
            }
        }

        DB::transaction(function () use ($data, $rep, $syncZones, &$clients) {
            if (! empty($data['client_ids'])) {
                // ═══ التسكين بيضم للفريق (إصلاح ١١/٨ مساءً) ═══
                //
                // ⚠️ كان بيظبط `rep_id` بس و`manager_id` بيفضل فاضي —
                // فالعميل المتسكّن مابيدخلش بول فريق المدير، وكروت
                // «فرق القنوات» مجاميعها مش بتطابق الإجمالي، والزملا
                // مش شايفينه. القاعدة: تسكين على مندوب = انضمام لفريقه.
                // (مندوب بلا مدير: مانمسحش مدير موجود — بنسيبه زي ما هو.)
                $teamManager = $rep->role === 'manager' ? $rep->id : $rep->manager_id;

                $clients = Client::whereIn('id', $data['client_ids'])
                    ->update(array_filter([
                        'rep_id' => $rep->id,
                        'manager_id' => $teamManager,
                    ], fn ($v) => $v !== null));
            }

            // ⚠️ `sync` مش `attach` — الشاشة بتبعت القايمة الكاملة،
            // و `attach` بتزوّد بس فالمناطق المشيلة بتفضل مربوطة.
            //
            // ⚠️ و`$request->boolean('zones_form')` مش `isset` — لو
            // اليوزر شال علامة كل المناطق، المتصفح مابيبعتش المصفوفة
            // خالص، و `isset` بتتخطى الحفظ فالمسح مابيحصلش أبداً.
            if ($syncZones) {
                $rep->zones()->sync($data['zone_ids'] ?? []);
            }

            // ═══ التسكين بيجرّ التغطية وراه (٢١/٨) ═══
            // بلاغ المالك: «العميل عند المدير ومش ظاهر لمناديبه».
            // مناطق العملاء بتتفعّل وبتتعلّم للمندوب ولفريق مديره
            // أوتوماتيك — مفيش خطوة يدوية تُنسى بعد كده.
            \App\Services\Coverage::syncMany(
                Client::whereIn('id', $data['client_ids'] ?? [])->get()
            );
        });

        return back()->with('ok', __('journey.assigned', ['count' => $clients]));
    }

    public function unassign(Request $request, Client $client)
    {
        // ⚠️ **كان بلا فحص** — أي مدير بيفك تسكين أي عميل في الشركة
        // بالـid، والعميل بيبقى يتيم ومحدش بيزوره.
        Scope::assertClient($request->user(), $client);

        $client->update(['rep_id' => null]);

        return back()->with('ok', __('journey.unassigned', ['client' => $client->displayName()]));
    }

    // ═════════════ الخريطة الجغرافية وخط السير (١٣ أغسطس ٢٠٢٦) ═════════════

    /**
     * ═══════════════════════════════════════════════════════════
     * «المفروض اتفقنا انك توزع كل العملاء على المناديب حسب المدير»
     *                    — طلب المالك ١٣ أغسطس ٢٠٢٦
     * ═══════════════════════════════════════════════════════════
     *
     * معاينة التوزيع التلقائي: بتاخد عملاء المدير اللي **مالهمش
     * مسؤول أساسي** (`rep_id IS NULL`) وبتقترح لكل واحد مندوب.
     *
     * ⚠️ **معاينة بس — مفيش كتابة هنا خالص.** التأكيد بيروح
     * `geoAssign` وهو اللي بيكتب في ترانزاكشن واحدة. لو الاتنين
     * اتدمجوا، ضغطة الزرار كانت هتسكّن ٢٠٠ عميل من غير ما حد
     * يبص على الاقتراح.
     *
     * ═══ الخوارزم: قرابة المنطقة الأول، وبعدين التوازن ═══
     * 1. **مناديب البيع بس** افتراضياً. السواق بيوصّل أوامر لعملاء
     *    مش بتوعه أصلاً (`Scope::sameTeam` مابيفحصش `rep_id` عن قصد)،
     *    والبروموتر شغله رفوف الكي أكاونت — تسكين عميل عليهم
     *    بيبوّظ التارجت والتصفية. `drivers=1` بتضم السواقين بقرار
     *    صريح من الشاشة، والبروموتر **مستبعد دايماً**.
     * 2. العميل بيروح للمندوب اللي **ماسك أكتر عملاء في نفس منطقته**
     *    — المندوب اللي بيدخل الشارع ده أصلاً مايتحطش له محل في
     *    منطقة تانية عشان الأرقام تتساوى.
     * 3. منطقة **مالهاش صاحب** لسه: بتروح للمندوب **الأقل حملاً**،
     *    وبعدها باقي محلات نفس المنطقة بتلحقه (العدّاد بيتزوّد في
     *    الذاكرة) — يعني نقطة دخول متوازنة وخط سير مترابط، مش
     *    راوند-روبن بيرمي كل محل لواحد.
     * 4. اللي مالوش مندوب مسموح (`Scope::inZone`/`sameTeam` بترفض)
     *    بيرجع في الصف بلا اقتراح وبادج توضّح السبب.
     *
     * `suggest=0` بتلغي الاقتراح كله وترجّع القايمة خام — دي اللي
     * شيب «بدون مندوب» بيستخدمها للتسكين اليدوي.
     */
    public function geoDistribute(Request $request)
    {
        $data = $request->validate([
            'manager' => ['required', 'integer'],
            'zone' => ['nullable', 'integer'],
            'drivers' => ['nullable', 'boolean'],
            'suggest' => ['nullable', 'boolean'],
        ]);

        $viewer = $request->user();

        // ⚠️ الحارس على المدير نفسه بنفس سكوب كروت الشاشة بالحرف —
        // `findOrFail` على بيلدر متسكوب بترمي 404 للي بره سكوبه،
        // فمفيش «شوف فريق زميلك» بتعديل الباراميتر.
        $manager = Branch::scope(User::where('role', 'manager')->where('active', true))
            ->when($viewer !== null && $viewer->role === 'manager',
                fn ($q) => $q->whereKey($viewer->id))
            ->findOrFail((int) $data['manager']);

        $withDrivers = (bool) ($data['drivers'] ?? false);
        $suggest = ! array_key_exists('suggest', $data) || $data['suggest'] === null
            || (bool) $data['suggest'];

        $roles = $withDrivers ? ['sales_agent', 'driver'] : ['sales_agent'];

        // ⚠️ `with('zones')` إجباري — `Scope::inZone` بتقراها لكل عميل
        $reps = User::fieldVisibleTo(Branch::scope(User::with('zones')), $viewer)
            ->where('manager_id', $manager->id)
            ->whereIn('role', $roles)
            ->where('active', true)
            ->orderBy('name')
            ->get()
            ->filter(fn (User $r) => Scope::canRep($viewer, $r))
            ->values();

        $zoneId = ($data['zone'] ?? null) !== null ? (int) $data['zone'] : null;

        $clients = Branch::scope(Client::visibleTo(Client::with(['group', 'zone']), $viewer))
            ->where('status', 'active')
            ->where('manager_id', $manager->id)
            ->whereNull('rep_id')
            ->when($zoneId !== null, fn ($q) => $q->where('zone_id', $zoneId))
            ->orderBy('zone_id')
            ->orderBy('name')
            ->get();

        // الحمل الحالي لكل مندوب جوه بول المدير — كويري واحدة مجمّعة
        // ⚠️ المفاتيح **بـ`(int)` صريحة** زي باقي الشاشة: بعض إعدادات
        // PDO بترجّع أعمدة الأرقام نصوص، ومفتاح نص جنب `$rep->id` رقم
        // بيخلّي كل الأحمال تبان صفر والتوزيع يروح لأول مندوب كله.
        $load = [];

        $loadRows = Branch::scope(Client::visibleTo(Client::query(), $viewer))
            ->where('status', 'active')
            ->where('manager_id', $manager->id)
            ->whereNotNull('rep_id')
            ->selectRaw('rep_id, COUNT(*) as n')
            ->groupBy('rep_id')
            ->get();

        foreach ($loadRows as $row) {
            $load[(int) $row->rep_id] = (int) $row->n;
        }

        // مين ماسك كام في كل منطقة — نفس الكويري بس بالمنطقة
        $zoneRows = Branch::scope(Client::visibleTo(Client::query(), $viewer))
            ->where('status', 'active')
            ->where('manager_id', $manager->id)
            ->whereNotNull('rep_id')
            ->whereNotNull('zone_id')
            ->selectRaw('zone_id, rep_id, COUNT(*) as n')
            ->groupBy('zone_id', 'rep_id')
            ->get();

        $loadMap = [];

        foreach ($reps as $r) {
            $loadMap[(int) $r->id] = (int) ($load[(int) $r->id] ?? 0);
        }

        $zoneCount = [];

        foreach ($zoneRows as $row) {
            $rid = (int) $row->rep_id;

            // مناديب بره القايمة (سواق مستبعد مثلاً) مالهمش يشدّوا
            // منطقة ناحيتهم — القرابة بتتحسب على المرشحين بس
            if (! array_key_exists($rid, $loadMap)) {
                continue;
            }

            $zoneCount[(int) $row->zone_id][$rid] = (int) $row->n;
        }

        $added = [];
        $rows = [];

        foreach ($clients as $c) {
            $allowed = $reps
                ->filter(fn (User $r) => Scope::sameTeam($r, $c) && Scope::inZone($r, $c))
                ->values();

            $pick = null;

            if ($suggest && $allowed->isNotEmpty()) {
                $zk = $c->zone_id !== null ? (int) $c->zone_id : 0;

                // (٢) صاحب المنطقة
                $best = null;
                $bestN = 0;

                foreach ($allowed as $r) {
                    $n = (int) ($zoneCount[$zk][(int) $r->id] ?? 0);

                    if ($n > $bestN) {
                        $bestN = $n;
                        $best = $r;
                    }
                }

                // (٣) مفيش صاحب — الأقل حملاً، والباقي بيلحقه
                if ($best === null) {
                    foreach ($allowed as $r) {
                        if ($best === null || $loadMap[(int) $r->id] < $loadMap[(int) $best->id]) {
                            $best = $r;
                        }
                    }
                }

                $pick = $best;
                $pid = (int) $pick->id;

                $loadMap[$pid] = ($loadMap[$pid] ?? 0) + 1;
                $zoneCount[$zk][$pid] = ($zoneCount[$zk][$pid] ?? 0) + 1;
                $added[$pid] = ($added[$pid] ?? 0) + 1;
            }

            $rows[] = [
                'id' => (int) $c->id,
                'name' => $c->fullName(),
                'code' => (string) $c->code,
                'zone_id' => $c->zone_id !== null ? (int) $c->zone_id : null,
                'zone' => $c->zone?->displayName() ?? __('journey.geo_no_zone'),
                'governorate' => $c->governorateLabel(),
                'category' => $c->categoryLabel(),
                'category_class' => $c->categoryClass(),
                'suggested' => $pick !== null ? (int) $pick->id : null,
                'reps' => $allowed->map(fn (User $r) => [
                    'id' => (int) $r->id,
                    'name' => $r->displayName(),
                ])->all(),
            ];
        }

        return response()->json([
            'manager' => [
                'id' => (int) $manager->id,
                'name' => $manager->displayName(),
            ],
            'with_drivers' => $withDrivers,
            'reps' => $reps->map(fn (User $r) => [
                'id' => (int) $r->id,
                'name' => $r->displayName(),
                'role' => __('journey.geo_role_'.$r->role),
                'current' => (int) ($load[(int) $r->id] ?? 0),
                'added' => (int) ($added[(int) $r->id] ?? 0),
            ])->values()->all(),
            'clients' => $rows,
        ]);
    }

    /**
     * تأكيد التوزيع — كتابة `rep_id` وبس، في ترانزاكشن واحدة.
     *
     * ⚠️ **`manager_id` مابيتلمسش هنا** (عكس `assign()`): الشاشة دي
     * بتوزّع عملاء **المدير على مناديبه هو**، فالمدير متسجّل خلاص.
     * كتابته تاني كانت هتخفي أي داتا غلط بدل ما تبان في الكارت.
     *
     * ⚠️ نفس أربع حراس `geoPlan` بالحرف، وكلها **قبل** الترانزاكشن —
     * صف واحد مرفوض بيوقّف الدفعة كلها، مايبقاش نص توزيع محفوظ.
     */
    public function geoAssign(Request $request)
    {
        $data = $request->validate([
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.client_id' => ['required', 'integer', 'exists:clients,id'],
            'rows.*.user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $viewer = $request->user();
        $rows = collect($data['rows']);

        $reps = User::with('zones')->whereIn('id', $rows->pluck('user_id')->unique()->all())
            ->get()->keyBy('id');
        $clients = Client::whereIn('id', $rows->pluck('client_id')->unique()->all())
            ->get()->keyBy('id');

        foreach ($rows as $row) {
            $rep = $reps->get((int) $row['user_id']);
            $client = $clients->get((int) $row['client_id']);

            Scope::assertRep($viewer, $rep);
            Scope::assertClient($viewer, $client);
            Scope::assertSameTeam($rep, $client);
            Scope::assertInZone($rep, $client);
        }

        // تجميع بالمندوب — أبديت واحد لكل مندوب مش لكل عميل
        $byRep = $rows->groupBy(fn ($r) => (int) $r['user_id']);
        $count = 0;

        DB::transaction(function () use ($byRep, &$count) {
            foreach ($byRep as $userId => $group) {
                $ids = $group->pluck('client_id')->map(fn ($v) => (int) $v)->unique()->all();

                $count += (int) Client::whereIn('id', $ids)
                    ->update(['rep_id' => (int) $userId]);
            }
        });

        return response()->json([
            'ok' => true,
            'message' => __('journey.geo_dist_saved', [
                'count' => $count,
                'reps' => $byRep->count(),
            ]),
        ]);
    }

    // ═══════════════════════ الشاشة اللايف ═══════════════════════

    /** صفوف التيرمينال — مشتركة بين العرض الأول والـJSON (2026-08-06) */
    private function liveRows(Request $request)
    {
        // ⚠️ العهدة بتتحمّل eager مش `currentCustody()` — الميثود دي
        // بتعمل كويري جديدة لكل مندوب، والشاشة دي بتترفرش كل دقيقة.
        // ⚠️ **النهارده أو المفتوحة** (إصلاح ١٠/٨): الفلتر كان على
        // النهارده بس — فعهدة امبارح اللي لسه مفتوحة (البضاعة في
        // العربية) كانت بتختفي من اللايف الساعة ١٢ بالليل.
        // ⚠️ **`fieldVisibleTo` مش اختيارية هنا** (تدقيق ٨/٨/٢٠٢٦):
        // الشاشة اللايف كانت أخطر تسريب في السيستم — أي تشانل مانجر
        // بيشوف GPS وسرعة وقيمة عهدة ومبيعات **كل** مناديب الشركة.
        // كل شاشة تانية بتجيب فريق الميدان بتستخدمها، ودي كانت الوحيدة
        // اللي فاتت.
        $reps = User::fieldVisibleTo(Branch::scope(User::with([
            'zone',
            'custodies' => fn ($q) => $q
                ->where(fn ($w) => $w->whereDate('date', today())
                    ->orWhereNull('status')
                    ->orWhere('status', '<>', 'closed'))
                ->orderByDesc('date')
                ->with('items.product'),
        ])), $request->user())
            ->whereIn('role', User::FIELD_WORK_ROLES)
            ->where('active', true)
            ->orderBy('name')
            ->get();

        // ⚠️ آخر موقعين لكل مندوب في كويري واحدة — الأول للمكان
        // والتاني لحساب السرعة اللحظية. لوب بيسأل لكل مندوب = كويري
        // لكل صف، والصفحة دي بتترفرش لوحدها.
        // ⚠️ `lat` **و`lng`** الاتنين (١٢/٨) — صف بـlat من غير lng كان
        // بيتحول `(float) null = 0.0` ويرمي ماركر ومسافة وهمية لخط
        // الطول صفر (وسط المحيط) في كل الحسابات.
        $eventsByUser = TrackEvent::whereIn('user_id', $reps->pluck('id'))
            ->whereDate('created_at', today())
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('user_id');

        $lastEvents = $eventsByUser->map(fn ($g) => $g->first());

        // ═══ التيرمينال (2026-08-06): مبيعات اليوم + كيلومترات + حالة ═══
        // مبيعات كل مندوب النهارده — كويري واحدة مجمعة (grand = المدفوع)
        $salesToday = \App\Models\Invoice::whereDate('created_at', today())
            ->whereIn('user_id', $reps->pluck('id'))
            ->selectRaw('user_id, COALESCE(SUM(grand_total),0) as s')
            ->groupBy('user_id')->pluck('s', 'user_id');

        // ⚠️ «باع بكام» (إعادة البناء ١١/٨) = فواتير اليوم **+ أوامر
        // التوريد اللي اتسلمت النهارده** — السواق مبيعاته POs مش
        // فواتير، ومن غير الجمعة ده كان بيبان صفر طول اليوم.
        // الاتنين بالـgrand_total (اللي العميل بيدفعه — عقيدة الأرقام).
        $poToday = \App\Models\PurchaseOrder::whereDate('delivered_at', today())
            ->whereIn('assigned_to', $reps->pluck('id'))
            ->where('status', 'delivered')
            ->selectRaw('assigned_to, COUNT(*) as c, COALESCE(SUM(grand_total),0) as s')
            ->groupBy('assigned_to')->get()->keyBy('assigned_to');

        // زيارات اليوم لكل مندوب — «زار محلات ولا لأ»
        $visitsToday = \App\Models\Visit::whereIn('user_id', $reps->pluck('id'))
            ->whereDate('checked_in_at', today())
            ->selectRaw('user_id, COUNT(*) as c')
            ->groupBy('user_id')->pluck('c', 'user_id');

        // الزيارة المفتوحة دلوقتي — بينوّر بنفسجي على الخريطة، ومعاها
        // العميل عشان «آخر حالة»: المدير يشوف المندوب واقف عند مين
        $openVisits = \App\Models\Visit::with('client')
            ->whereIn('user_id', $reps->pluck('id'))
            ->whereDate('checked_in_at', today())
            ->whereNull('checked_out_at')
            ->get()->keyBy('user_id');

        // حالة الحضور (شغال/بريك/منصرف) — أيام اليوم ببانشاتها محمّلة
        // مرة واحدة **قراءة فقط** (مفيش firstOrCreate — الشاشة بتترفرش
        // كل ٣ ثواني)، وآخر بانش بيتحسب من الكولكشن مش من كويري لكل مندوب
        $attDays = \App\Models\AttendanceDay::with('punches')
            ->whereDate('date', today())
            ->whereIn('user_id', $reps->pluck('id'))
            ->get()
            ->keyBy('user_id');

        // ═══ فيد اليوم (بلا `open`) مرة واحدة — منه «آخر ٥ أحداث»
        // في بوب أب الشخص على شاشة التلفزيون (١٢/٨) ═══
        $feedByUser = TrackEvent::whereIn('user_id', $reps->pluck('id'))
            ->whereDate('happened_at', today())
            ->where('type', '!=', 'open')
            ->orderByDesc('happened_at')
            ->get(['id', 'user_id', 'type', 'title', 'subtitle', 'happened_at'])
            ->groupBy('user_id');

        // ═══ عربيات iTrack (٢٦/٨) — آخر موقع جهاز كل مندوب، كويري
        // واحدة للفريق كله (الشاشة بتترفرش كل ٣ ثواني) ═══
        $vansByUser = \App\Models\GpsDevice::where('active', true)
            ->whereIn('user_id', $reps->pluck('id'))
            ->whereNotNull('lat')->whereNotNull('lng')
            ->get()->keyBy('user_id');

        $rows = $reps->map(function (User $rep) use ($eventsByUser, $lastEvents, $salesToday, $poToday, $visitsToday, $openVisits, $attDays, $feedByUser, $vansByUser) {
            // من العلاقة المحمّلة فوق — مش كويري جديدة
            $custody = $rep->custodies->first();
            $summary = Journeys::summary($rep);
            $last = $lastEvents->get($rep->id);
            $minutes = $last ? (int) round(abs(now()->diffInMinutes($last->created_at))) : null;

            // الحالة: زيارة ← متحرك (إشارة < 10 دقايق) ← واقف ← مفيش إشارة
            // ⚠️ **بتفضل بنفس الحساب القديم بالحرف** — صفحة قديمة متكاشة
            // في متصفح مفتوح بتقرا `status` من نفس الـSSE. الحالة الجديدة
            // بتاعة شاشة التلفزيون تحت في `live_state` (إضافة مش تغيير).
            $status = match (true) {
                $openVisits->has($rep->id) => 'visit',
                $minutes !== null && $minutes < 10 => 'moving',
                $minutes !== null => 'idle',
                default => 'off',
            };

            // ═══ شاشة التلفزيون (١٢/٨) — كل الأوقات h:i A بتوقيت القاهرة
            // **صراحةً**: اللايف سيرفر ممكن يكون ناسي APP_TIMEZONE (مشكلة
            // معروفة) — التحويل الصريح صح في الحالتين: لو التوقيت مظبوط
            // فهو no-op، ولو UTC فبيتحوّل للقاهرة. ═══
            $hia = fn ($dt) => $dt?->copy()->timezone('Africa/Cairo')->format('h:i A');

            // ═══ الحضور — من اليوم المحمّل فوق (قراءة فقط) ═══
            $att = $attDays->get($rep->id);
            $lastPunch = $att?->punches->sortBy([['at', 'desc'], ['id', 'desc']])->first();

            $attState = $att === null ? 'none' : match ($lastPunch?->type) {
                \App\Models\AttendancePunch::IN,
                \App\Models\AttendancePunch::BACK => 'working',
                \App\Models\AttendancePunch::BREAK => 'break',
                \App\Models\AttendancePunch::OUT => 'out',
                default => 'none',
            };
            $onShift = in_array($attState, ['working', 'break'], true);

            // ═══ واقف ولا متحرك — من نقط التراك مش من عمر الإشارة ═══
            // «واقف» = مفيش حركة تُذكر: بنمشي من أحدث نقطة لورا طول ما
            // كل نقطة جوه 100 متر منها — أول نقطة برة الدايرة هي آخر
            // حركة حقيقية. لو آخر حركة من ≥ 10 دقايق يبقى واقف بمدة،
            // وأقل من كده يبقى متحرك. (رعشة GPS جوه الـ100 متر مش حركة.)
            $moveState = null;
            $moveMin = null;
            $pts = $eventsByUser->get($rep->id);

            if ($pts !== null && $pts->isNotEmpty()) {
                $p0 = $pts->first();
                $standSince = $p0->happened_at ?? $p0->created_at;

                foreach ($pts->slice(1) as $p) {
                    $d = \App\Services\RepKpis::haversine(
                        (float) $p->lat, (float) $p->lng, (float) $p0->lat, (float) $p0->lng,
                    );

                    if ($d > 0.1) {
                        break;
                    }

                    $standSince = $p->happened_at ?? $p->created_at;
                }

                $standMin = (int) round(abs(now()->diffInMinutes($standSince)));

                if ($standMin >= 10) {
                    $moveState = 'standing';
                    $moveMin = $standMin;
                } else {
                    $moveState = 'moving';
                }
            }

            // ═══ آلة حالة التلفزيون — كل حالة ومعاها مدتها ═══
            // زيارة مفتوحة ← أوفلاين (مش على الحضور: «مفيش إشارة» كانت
            // بتتقال لحد منصرف أصلاً) ← مفيش GPS النهارده وهو شغال ←
            // متحرك/واقف من نقط التراك.
            $openV = $openVisits->get($rep->id);

            if ($openV !== null) {
                $liveState = 'visit';
                $liveMin = $openV->checked_in_at !== null
                    ? (int) round(abs(now()->diffInMinutes($openV->checked_in_at)))
                    : null;
            } elseif (! $onShift) {
                $liveState = 'off';
                $liveMin = $attState === 'out' && $lastPunch !== null
                    ? (int) round(abs(now()->diffInMinutes($lastPunch->at)))
                    : null;
            } elseif ($moveState === null || ($minutes !== null && $minutes > 15)) {
                // مفيش GPS النهارده خالص، **أو** الإشارة قديمة (> 15
                // دقيقة): منقولش «واقف من 6 ساعات» وإحنا أصلاً مش
                // شايفينه — الشيب بيوري «آخر إشارة h:i A» والمدة.
                $liveState = 'nosignal';
                $liveMin = $minutes;
            } else {
                $liveState = $moveState;
                $liveMin = $moveMin;
            }

            // ═══ غرفة التحكم (2026-08-06) ═══
            // السرعة اللحظية من آخر نقطتين — بس لو الفرق أقل من 15 دقيقة،
            // وبسقف 120 كم/س: قفزة GPS بتطلع أرقام خرافية مش سرعة.
            $speed = null;
            $pair = $eventsByUser->get($rep->id)?->take(2);

            if ($pair && $pair->count() === 2) {
                [$a, $b] = [$pair[0], $pair[1]];
                $mins = abs($a->created_at->diffInSeconds($b->created_at)) / 60;

                if ($mins > 0.2 && $mins < 15) {
                    // ⚠️ haversine بترجع **كيلومترات** (r=6371) مش أمتار
                    $km = \App\Services\RepKpis::haversine(
                        (float) $a->lat, (float) $a->lng, (float) $b->lat, (float) $b->lng,
                    );
                    $speed = min(120, (int) round($km / ($mins / 60)));
                }
            }

            // داخل/خارج زونه — لو الزون له إحداثيات والمندوب له إشارة.
            // 2.5 كم نصف قطر افتراضي: الزونات مالهاش حدود مرسومة.
            $inZone = null;

            if ($last && $rep->zone?->lat !== null && $rep->zone?->lng !== null) {
                // بالكيلومتر — نفس نصف قطر دايرة الزون على الخريطة (2.5 كم)
                $inZone = \App\Services\RepKpis::haversine(
                    (float) $last->lat, (float) $last->lng,
                    (float) $rep->zone->lat, (float) $rep->zone->lng,
                ) <= 2.5;
            }

            // تفاصيل العهدة للبانل — أعلى 6 أصناف بالمتبقي والمباع
            $items = $custody
                ? $custody->items->sortByDesc('assigned')->take(6)->map(fn ($i) => [
                    'name' => $i->product->displayName(),
                    'assigned' => (int) $i->assigned,
                    'sold' => (int) $i->sold,
                    'remaining' => (int) $i->remaining(),
                ])->values()->all()
                : [];

            // ⚠️ قيمة العهدة بعقيدة التسعير (١١/٨): السواق بضاعته
            // متسعّرة «قديم» والسيلز بيبيع بـ«جديد». التقييم من
            // `CustodyValue` (١٢/٨) — نفس القاعدة (`listForRep`) بس
            // القوايم وبنودها ميمو للريكوست كله: مفيش كويري سعر لكل
            // صنف لكل مندوب كل ٣ ثواني.
            $repList = \App\Support\CustodyValue::listForRep($rep);
            $remTotals = \App\Support\CustodyValue::remainingTotals($custody);

            // مسار اليوم — من نفس `$eventsByUser` المحمّلة فوق (مفيش
            // كويري زيادة): ترتيب زمني تصاعدي عشان الخط يترسم صح،
            // وسقف 300 نقطة عشان حمولة الـSSE كل ٣ ثواني متتخنش.
            $chrono = ($eventsByUser->get($rep->id) ?? collect())
                ->sortBy(fn ($e) => ($e->happened_at ?? $e->created_at)->getTimestamp())
                ->values();

            $track = $chrono
                ->slice(-300)
                ->values()
                ->map(fn ($e) => [
                    'lat' => (float) $e->lat,
                    'lng' => (float) $e->lng,
                    't' => $hia($e->happened_at ?? $e->created_at),
                ])->all();

            // ═══ الكيلومترات — من النقط المحمّلة أصلاً (كويري أقل من
            // `kmForDay`) وبفلتر الشوشرة الموثّق في `RepKpis::cleanKm`،
            // ومقصوصة على نافذة الشغل: من أول تشيك إن لآخر انصراف.
            // مش مسجل حضور النهارده = صفر كيلومتر شغل. ═══
            $kmToday = $att?->first_in_at !== null
                ? \App\Services\RepKpis::cleanKm(
                    $chrono->map(fn ($e) => [
                        'lat' => (float) $e->lat,
                        'lng' => (float) $e->lng,
                        'at' => $e->happened_at ?? $e->created_at,
                    ])->all(),
                    $att->first_in_at,
                    $att->last_out_at,
                )
                : 0.0;

            // ═══ ماركر «صوّر الرف» (١٥ أغسطس ٢٠٢٦) ═══
            // ⚠️ **من الفيد المحمّل أصلاً — مفيش كويري زيادة.** الحمولة
            // بتترسل كل ٣ ثواني، فأي سؤال جديد للداتابيز هنا بيتضرب في
            // عدد المناديب × ٢٠ مرة في الدقيقة. حدث `shelf` بيتسجّل
            // مرة لكل مرحلة لكل زيارة، فالعدّ ده «كام لقطة رف النهارده».
            $shelfToday = ($feedByUser->get($rep->id) ?? collect())
                ->where('type', 'shelf')->count();

            // آخر ٥ أحداث للبوب أب — أوقات h:i A جاهزة من السيرفر
            $recent = ($feedByUser->get($rep->id) ?? collect())
                ->take(5)
                ->map(fn ($e) => [
                    't' => $hia($e->happened_at),
                    'icon' => $e->icon(),
                    'color' => $e->color(),
                    'text' => trim($e->title.($e->subtitle ? ' · '.$e->subtitle : '')),
                ])->values()->all();

            // ═══ عربية المندوب (iTrack ٢٦/٨) + إنذار التباعد ═══
            // «بعيد» = إشارتين طازتين (تليفون < 15 دقيقة وجهاز < 15
            // دقيقة) والمسافة بينهم > 1 كم — إشارة قديمة ماتتهمش حد.
            $van = $vansByUser->get($rep->id);
            $vanFresh = $van !== null && $van->gps_time !== null
                && $van->gps_time->gt(now()->subMinutes(15));
            $gapKm = null;

            if ($van !== null && $last !== null) {
                $gapKm = \App\Services\Itrack::distanceKm(
                    (float) $last->lat, (float) $last->lng,
                    (float) $van->lat, (float) $van->lng,
                );
            }

            $vanRow = $van === null ? null : [
                'lat' => (float) $van->lat,
                'lng' => (float) $van->lng,
                'plate' => $van->label(),
                'speed' => $van->speed,
                'acc' => (int) $van->acc,
                'time' => $hia($van->gps_time),
                'fresh' => $vanFresh,
                'gap_km' => $gapKm,
                'far' => $vanFresh && $minutes !== null && $minutes < 15
                    && $gapKm !== null && $gapKm > 1,
            ];

            return [
                'rep' => $rep,
                'custody' => $custody,
                'van' => $vanRow,
                'remaining_units' => $custody?->remainingUnits() ?? 0,
                'remaining_value' => round((float) ($remTotals[$repList?->id]['total'] ?? 0), 2),
                'summary' => $summary,
                'last' => $last,
                'lat' => $last?->lat,
                'lng' => $last?->lng,
                'minutes_ago' => $minutes,
                'status' => $status,
                'sales_today' => round((float) ($salesToday[$rep->id] ?? 0)
                    + (float) ($poToday->get($rep->id)?->s ?? 0), 2),
                'km_today' => $kmToday,
                'speed' => $speed,
                'in_zone' => $inZone,
                'items' => $items,
                // ═══ إعادة البناء (١١/٨) — «كل واحد معاه بكام وباع بكام
                // وزار ولا لأ وعمل أوامر ولا لأ وآخر حالة إيه» ═══
                'visits_today' => (int) ($visitsToday[$rep->id] ?? 0),
                'pos_today' => (int) ($poToday->get($rep->id)?->c ?? 0),
                'shelf_today' => $shelfToday,
                'work' => $onShift ? $attState : 'off',
                'open_client' => $openV?->client?->displayName(),
                'last_event' => $last !== null
                    ? trim($last->title.($last->subtitle ? ' · '.$last->subtitle : ''))
                    : null,
                'last_event_icon' => $last?->icon(),
                'track' => $track,
                // ═══ شاشة التلفزيون (١٢/٨) — كله additive ═══
                'att_state' => $attState,
                'att_in' => $hia($att?->first_in_at),
                'att_out' => $hia($att?->last_out_at),
                'live_state' => $liveState,
                'live_min' => $liveMin,
                'signal_at' => $hia($last?->happened_at ?? $last?->created_at),
                'events' => $recent,
            ];
        });

        return $rows;
    }

    /**
     * فيد التنبيهات — **كل حركة المندوب من سجل التتبع**.
     *
     * ⚠️ **كان بيقرا من الفواتير والزيارات بس** (اتغيّر 2026-08-07).
     * يعني المدير كان بيشوف البيع والتشيك إن/أوت وخلاص: المرتجع
     * والهدايا واستلام العهدة وتسليم أوامر التوريد وطلبات العملاء
     * الجديدة كانت بتحصل في الشارع ومحدش شايفها في غرفة التحكم.
     *
     * `track_events` هو السجل الوحيد اللي فيه **كل** الحركات بترتيبها
     * الزمني وبإحداثياتها — فالفيد بيتبني منه، والفواتير والزيارات
     * بتفضل مصدر الأرقام زي ما هي.
     *
     * ⚠️ **`happened_at` مش `created_at`.** الحدث ممكن يتسجّل متأخر
     * (الأبلكيشن كان أوفلاين ورفع لما النت رجع) — الترتيب بوقت
     * الحصول الفعلي وإلا الفيد بيبقى كذّاب.
     *
     * @return list<array{t: string, kind: string, icon: string, color: string, rep: string, text: string}>
     */
    private function liveAlerts($reps): array
    {
        $names = $reps->pluck('name', 'id');

        return \App\Models\TrackEvent::whereIn('user_id', $reps->pluck('id'))
            ->whereDate('happened_at', today())
            // فتح الأبلكيشن مش حركة شغل — بيتسجّل للإحصاء بس، ولو
            // دخل الفيد هيغرقه (المندوب بيفتح الأبلكيشن 50 مرة في اليوم)
            ->where('type', '!=', 'open')
            ->orderByDesc('happened_at')
            // ⚠️ 60 مش 25 (١١/٨) — التايم لاين بقى بيتفلتر بالمندوب
            // في المتصفح، و25 حدث لكل الفريق كانوا بيسيبوا للمندوب
            // الواحد حدثين تلاتة والباقي «فاضي» كذباً.
            ->take(60)
            ->get()
            // ⚠️ توقيت القاهرة صراحةً (١٢/٨) — «التايم لاين توقيته غلط»:
            // اللايف سيرفر ممكن يكون ناسي APP_TIMEZONE فبيفرمت UTC.
            // التحويل الصريح no-op لو التوقيت مظبوط وصح لو ناسيه.
            ->map(fn ($e) => [
                't' => $e->happened_at->copy()->timezone('Africa/Cairo')->format('h:i A'),
                'kind' => $e->type,
                'icon' => $e->icon(),
                'color' => $e->color(),
                'rep' => $names[$e->user_id] ?? '—',
                'text' => trim($e->title.($e->subtitle ? ' · '.$e->subtitle : '')),
                // فلترة التايم لاين بالضغط على المندوب — client-side
                'user_id' => (int) $e->user_id,
            ])
            ->values()->all();
    }

    public function live(Request $request)
    {
        // ⚠️ نفس حمولة الرفرش بالظبط — الشاشة بترسم فوراً من غير
        // فلاشة فاضية، وأول fetch بعد 15 ثانية بيكمل عادي.
        return view('ops.live', [
            'initial' => $this->livePayload($request),
            'date' => today()->toDateString(),
        ]);
    }

    /** داتا التيرمينال JSON — فولباك البولينج لو الـSSE مش شغال */
    public function liveData(Request $request)
    {
        return response()->json($this->livePayload($request));
    }

    /**
     * اللايف للموبايل (٢٨/٨) — بورد المدير/الأدمن في الأبلكيشن.
     * نفس `liveRows` بحرفها (وبنفس حارس `fieldVisibleTo` جواها —
     * تسريب الشاشة اللايف درس ٨/٨)، بس مقصوصة على اللي الكارت
     * محتاجه: الموديلات الكاملة والـitems والـevents بيتشالوا،
     * والعربية بترجع زي ما هي (أصلاً array جاهزة).
     */
    public function apiLive(Request $request)
    {
        $rows = $this->liveRows($request)->map(fn ($r) => [
            'id' => $r['rep']->id,
            'name' => $r['rep']->displayName(),
            'code' => $r['rep']->code,
            'role' => $r['rep']->role,
            'role_label' => $r['rep']->roleLabel(),
            'avatar_url' => $r['rep']->avatarUrl(),
            'status' => $r['status'],
            'work' => $r['work'],
            'att_in' => $r['att_in'],
            'att_out' => $r['att_out'],
            'live_state' => $r['live_state'],
            'live_min' => $r['live_min'],
            'signal_at' => $r['signal_at'],
            'minutes_ago' => $r['minutes_ago'],
            'lat' => $r['lat'] !== null ? (float) $r['lat'] : null,
            'lng' => $r['lng'] !== null ? (float) $r['lng'] : null,
            'in_zone' => $r['in_zone'],
            'speed' => $r['speed'],
            'km_today' => $r['km_today'],
            'sales_today' => (float) $r['sales_today'],
            'visits_today' => (int) $r['visits_today'],
            'pos_today' => (int) $r['pos_today'],
            'shelf_today' => $r['shelf_today'],
            'open_client' => $r['open_client'],
            'last_event' => $r['last_event'],
            'last_event_icon' => $r['last_event_icon'],
            'remaining_units' => (int) $r['remaining_units'],
            'remaining_value' => (float) $r['remaining_value'],
            'van' => $r['van'],
        ])->values();

        return response()->json(['rows' => $rows, 'at' => now()->format('h:i A')]);
    }

    /**
     * لايف فوري بـServer-Sent Events — الشاشة بتتحدث كل ٣ ثواني
     * من غير ما تسأل، بدل ما تدق كل ١٥ ثانية على الفاضي.
     *
     * ⚠️ **ليه سقف مدة وقطع عند فقد الاتصال؟** كل اتصال SSE ماسك
     * عملية PHP كاملة من الـpool طول ما هو مفتوح. على استضافة
     * مشتركة تلات شاشات مفتوحة ومنسية كفيلة تخنق السيرفر. فالاتصال
     * بيموت لوحده بعد خمس دقايق، والواجهة بتفتح واحد جديد — وده
     * كمان بيحرّر أي ذاكرة اتجمعت في العملية.
     */
    public function liveStream(Request $request): StreamedResponse
    {
        // ⚠️ **قفل السيشن قبل ما اللوب يبدأ.** لارافيل بيقفل ملف
        // السيشن طول الريكوست — وريكوست عايش خمس دقايق معناه إن أي
        // صفحة تانية في نفس المتصفح هتفضل معلقة مستنية القفل يتفك.
        if ($request->hasSession()) {
            $request->session()->save();
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $response = new StreamedResponse(function () use ($request) {
            // اللوب طويل بطبيعته — حد التنفيذ الافتراضي هيقتله في نص الشغل
            @set_time_limit(0);

            // ⚠️ **false** عشان العملية تموت لما اليوزر يقفل التاب،
            // مش تفضل بتحسب داتا محدش هيشوفها.
            ignore_user_abort(false);

            $deadline = time() + 300;   // سقف خمس دقايق ثم الواجهة بتعيد الاتصال

            while (true) {
                echo 'data: '.json_encode($this->livePayload($request), JSON_UNESCAPED_UNICODE)."\n\n";

                // نبضة كتعليق — البروكسي بيشوف حركة فمابيقطعش الاتصال
                echo ": ping\n\n";

                // الفلاش هو اللي بيوصّل البايتات فعلاً — من غيره الحمولة
                // بتفضل في البافر والشاشة مش بتتحرك
                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                flush();

                // اليوزر قفل التاب أو عدّينا السقف — نسيب العملية تخلص
                if (connection_aborted() || time() >= $deadline) {
                    break;
                }

                sleep(3);

                if (connection_aborted()) {
                    break;
                }
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        // ⚠️ nginx بيبفّر الردود افتراضياً — من غير الهيدر ده الشاشة
        // مش هتستقبل حاجة غير لما الاتصال يقفل
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('Connection', 'keep-alive');

        return $response;
    }

    /** الحمولة الموحدة لغرفة التحكم — أول رسمة والرفرش من نفس المصدر */
    private function livePayload(Request $request): array
    {
        $rows = $this->liveRows($request);

        // ═══ خريطة التغطية (2026-08-06) ═══
        // مغطي = زون فيه عملاء مفعّلين. مستهدف = فيه عملاء مستنيين
        // تفعيل أو ليدز بس لسه مفيش أكتيف — دي الزونات اللي ناويين
        // نغطيها. زون مالوش لا دول ولا دول مش بيترسم — 362 دايرة
        // فاضية هتغرق الخريطة.
        $activeByZone = Client::visibleTo(
            \App\Models\Client::where('status', 'active')->whereNotNull('zone_id'), auth()->user())
            ->selectRaw('zone_id, COUNT(*) as n')->groupBy('zone_id')->pluck('n', 'zone_id');
        $pendingByZone = Client::visibleTo(
            \App\Models\Client::where('status', 'pending')->whereNotNull('zone_id'), auth()->user())
            ->selectRaw('zone_id, COUNT(*) as n')->groupBy('zone_id')->pluck('n', 'zone_id');
        $leadsByZone = \App\Models\Lead::whereNotNull('zone_id')
            ->selectRaw('zone_id, COUNT(*) as n')->groupBy('zone_id')->pluck('n', 'zone_id');

        $zoneIds = $activeByZone->keys()
            ->merge($pendingByZone->keys())
            ->merge($leadsByZone->keys())
            ->unique();

        $zones = \App\Models\Zone::whereIn('id', $zoneIds)
            ->whereNotNull('lat')->whereNotNull('lng')
            ->get()
            ->map(fn ($z) => [
                'name' => $z->displayName(),
                'lat' => (float) $z->lat,
                'lng' => (float) $z->lng,
                'kind' => ($activeByZone[$z->id] ?? 0) > 0 ? 'covered' : 'target',
                'active' => (int) ($activeByZone[$z->id] ?? 0),
                'potential' => (int) (($pendingByZone[$z->id] ?? 0) + ($leadsByZone[$z->id] ?? 0)),
            ])->values();

        // طبقة المحافظات — اسم + مركز + كام عميل شغال فيها
        $activeByGov = Client::visibleTo(
            \App\Models\Client::where('status', 'active')->whereNotNull('governorate'), auth()->user())
            ->selectRaw('governorate, COUNT(*) as n')->groupBy('governorate')->pluck('n', 'governorate');

        $governorates = \App\Models\Governorate::whereNotNull('lat')->whereNotNull('lng')
            ->get()
            ->map(fn ($g) => [
                'name' => app()->getLocale() === 'ar' ? $g->name : ($g->name_en ?: $g->name),
                'lat' => (float) $g->lat,
                'lng' => (float) $g->lng,
                'clients' => (int) ($activeByGov[$g->key] ?? 0),
            ])->values();

        return [
            'totals' => $this->liveTotals($rows),
            'zones' => $zones,
            'governorates' => $governorates,
            'alerts' => $this->liveAlerts($rows->map(fn ($r) => $r['rep'])),
            'reps' => $rows->map(fn ($r) => [
                'id' => $r['rep']->id,
                'name' => $r['rep']->displayName(),
                'role' => $r['rep']->roleLabel(),
                'zone' => $r['rep']->zone?->displayName(),
                'lat' => $r['lat'] !== null ? (float) $r['lat'] : null,
                'lng' => $r['lng'] !== null ? (float) $r['lng'] : null,
                'status' => $r['status'],
                'minutes' => $r['minutes_ago'],
                'done' => $r['summary']['done'],
                'planned' => $r['summary']['planned'],
                'pct' => $r['summary']['pct'],
                'off_plan' => $r['summary']['off_plan'],
                'units' => $r['remaining_units'],
                'value' => $r['remaining_value'],
                'sales' => $r['sales_today'],
                'km' => $r['km_today'],
                'speed' => $r['speed'],
                'in_zone' => $r['in_zone'],
                'items' => $r['items'],
                'url' => route('ops.rep_day', $r['rep']),
                // ═══ إضافات إعادة البناء (١١/٨) — كلها additive عشان
                // صفحة قديمة متكاشة في متصفح مفتوح متقعش ═══
                'avatar_url' => $r['rep']->avatarUrl(),
                'initials' => $r['rep']->initials(),
                'work' => $r['work'],
                'open_client' => $r['open_client'],
                'last_event' => $r['last_event'],
                'last_event_icon' => $r['last_event_icon'],
                'visits' => $r['visits_today'],
                'pos' => $r['pos_today'],
                // ماركر صور الرف في البوب أب (١٥/٨) — additive
                'shelf' => $r['shelf_today'],
                'track' => $r['track'],
                // ═══ إضافات شاشة التلفزيون (١٢/٨) — additive برضه:
                // الصفحة القديمة بتتجاهلها والجديدة بتبني عليها ═══
                'role_key' => $r['rep']->role,
                'att' => [
                    'state' => $r['att_state'],
                    'in' => $r['att_in'],
                    'out' => $r['att_out'],
                ],
                'live' => [
                    'state' => $r['live_state'],
                    'min' => $r['live_min'],
                ],
                'signal_at' => $r['signal_at'],
                // ═══ عربية iTrack (٢٦/٨) — additive برضه: null لو
                // مفيش جهاز مربوط، و`far` بينوّر إنذار التباعد ═══
                'van' => $r['van'],
                'events' => $r['events'],
                'tracking_url' => route('ops.tracking', ['user' => $r['rep']->id]),
            ])->values(),
        ];
    }

    private function liveTotals($rows): array
    {
        return [
            'reps' => $rows->count(),
            'active' => $rows->filter(fn ($r) => $r['last'] !== null)->count(),
            'planned' => $rows->sum(fn ($r) => $r['summary']['planned']),
            'done' => $rows->sum(fn ($r) => $r['summary']['done']),
            'value' => round($rows->sum(fn ($r) => $r['remaining_value']), 2),
            'sales' => round($rows->sum(fn ($r) => $r['sales_today']), 2),
            // غرفة التحكم (2026-08-06)
            'units' => (int) $rows->sum(fn ($r) => $r['remaining_units']),
            'in_zone' => $rows->filter(fn ($r) => $r['in_zone'] === true)->count(),
            'out_zone' => $rows->filter(fn ($r) => $r['in_zone'] === false)->count(),
            'idle' => $rows->filter(fn ($r) => $r['status'] === 'idle')->count(),
            // إعادة البناء (١١/٨) — «الكل زار كام محل وسلّم كام أمر»
            'visits' => (int) $rows->sum(fn ($r) => $r['visits_today']),
            'pos' => (int) $rows->sum(fn ($r) => $r['pos_today']),
            // شاشة التلفزيون (١٢/٨) — «الحضور جوه الشاشة»: كام مندوب
            // وكام مدير في الشارع فعلاً (حضور مفتوح) وكام أوفلاين
            'reps_on' => $rows->filter(fn ($r) => $r['rep']->role !== 'manager'
                && in_array($r['att_state'], ['working', 'break'], true))->count(),
            'managers_on' => $rows->filter(fn ($r) => $r['rep']->role === 'manager'
                && in_array($r['att_state'], ['working', 'break'], true))->count(),
            'offline_n' => $rows->filter(fn ($r) => ! in_array($r['att_state'], ['working', 'break'], true))->count(),
        ];
    }

    /**
     * يوم مندوب واحد — بيتفتح من الشاشة اللايف.
     *
     * ═══ إعادة بناء ١٢ أغسطس ٢٠٢٦ — «يوم المندوب» بمعايير الأسبوع ده ═══
     *
     * ⚠️ **كل المراسي هنا بالـid مش بالرول** — عشان المدير الميداني
     * (قرار ١١/٨) يتحسب زي المندوب بالظبط: خطته `journey_plans.user_id`،
     * زياراته `visits.user_id`، فواتيره `invoices.user_id`، أوامره
     * `purchase_orders.assigned_to`، وتحصيلاته قيود بمرساة زياراته.
     * الصفحة كانت بتقرا الخطة والزيارات بس — فمدير اشتغل ميداني من
     * غير خطة لليوم ده كان بيطلع «كله أصفار» وفلوسه وحركته موجودة
     * فعلاً تحت الـid بتاعه ومحدش شايفها هنا.
     */
    public function repDay(Request $request, User $user)
    {
        // ⚠️ **فلترة الشاشة اللايف بتخبّي الصف عن العين مش عن الراوت**
        // — `/ops/live/{user}` كان بيفتح يوم أي مندوب بالـid: مساره
        // وزياراته وفواتيره. الحارس هنا هو اللي بيقفلها فعلاً.
        // (المدير بيعدّي منه: على نفسه، أو الأدمن عليه — عقيدة ١١/٨.)
        Scope::assertRep($request->user(), $user);

        // ⚠️ `Carbon::parse` على نص عبيط بترمي استثناء و500. الفحص
        // بيرجّع النهارده بدل ما الصفحة تقع من باراميتر في اللينك.
        $date = rescue(
            fn () => $request->filled('date') ? Carbon::parse($request->input('date')) : today(),
            fn () => today(),
            report: false,
        );
        $dateD = $date->toDateString();

        // ⚠️ كل الأوقات h:i A بتوقيت القاهرة **صراحةً** — اللايف سيرفر
        // ممكن يكون ناسي APP_TIMEZONE (مشكلة معروفة): التحويل no-op لو
        // التوقيت مظبوط، وصح لو UTC. نفس قاعدة الشاشة اللايف (١٢/٨).
        $hia = fn ($dt) => $dt?->copy()->timezone('Africa/Cairo')->format('h:i A');

        // ═══ الخطة مرة واحدة ═══
        // ⚠️ كانت بتتحسب **تلات مرات**: `forDay` + `offPlan` (بتنده
        // `forDay` من جوه) + `summary` (بتنده الاتنين). الصفوف بتتحسب
        // مرة، و`offPlan` بتاخدها جاهزة، والسامري بيتبني منهم هنا.
        $rows = Journeys::forDay($user, $date);
        $offPlan = Journeys::offPlan($user, $date, $rows);

        $planned = $rows->count();
        $done = $rows->where('status', 'done')->count();

        $summary = [
            'planned' => $planned,
            'done' => $done,
            'in_visit' => $rows->where('status', 'in_visit')->count(),
            'pending' => $rows->where('status', 'pending')->count(),
            'off_plan' => $offPlan->count(),
            'pct' => $planned > 0 ? round($done / $planned * 100, 1) : 0.0,
        ];

        // ═══ فلوس اليوم — العقيدة الموحّدة (١١/٨) بالحرف ═══
        // **مبيعات المندوب = فواتيره (user_id) + أوامر التوريد
        // المسلَّمة (assigned_to)** بالـ`grand_total` (اللي العميل
        // بيدفعه). وفلوس الأوامر **من القيود مش من الأمر** — نفس
        // كويريز `RepSettlementController::figuresBetween` بالحرف:
        // قراية `grand_total` من الأمر كانت هتعدّ الأمانة كمان.
        $inv = \App\Models\Invoice::where('user_id', $user->id)
            ->whereDate('created_at', $dateD)
            ->selectRaw('COUNT(*) AS cnt, COALESCE(SUM(grand_total),0) AS total')
            ->first();

        $po = \App\Models\Transaction::where('source_type', \App\Models\PurchaseOrder::class)
            ->whereIn('source_id',
                \App\Models\PurchaseOrder::where('assigned_to', $user->id)->select('id'))
            ->whereDate('created_at', $dateD)
            ->selectRaw("COALESCE(SUM(CASE WHEN kind = 'sale' THEN debit ELSE 0 END),0) AS sale,
                COALESCE(SUM(CASE WHEN kind = 'collection' THEN credit ELSE 0 END),0) AS cash")
            ->first();

        // التحصيلات الميدانية — قيود `collection` بمرساة زيارات اليوزر
        // (نفس مرساة التصفية)، مقسومة نقدي/غير نقدي. «غير النقدي» =
        // الإجمالي − الكاش، مش مجموع طرق معروضة — طريقة شاذة
        // ماتضيّعش فلوس من الكارت.
        $coll = \App\Models\Transaction::where('kind', 'collection')
            ->where('source_type', \App\Models\Visit::class)
            ->whereIn('source_id', Visit::where('user_id', $user->id)->select('id'))
            ->whereDate('created_at', $dateD)
            ->selectRaw('COALESCE(SUM(credit),0) AS total,
                COALESCE(SUM(CASE WHEN method = ? THEN credit ELSE 0 END),0) AS cash',
                [\App\Models\Transaction::METHOD_CASH])
            ->first();

        $money = [
            'sales' => round((float) $inv->total + (float) $po->sale, 2),
            'inv_total' => round((float) $inv->total, 2),
            'inv_count' => (int) $inv->cnt,
            'po_sales' => round((float) $po->sale, 2),
            'coll_total' => round((float) $coll->total, 2),
            'coll_cash' => round((float) $coll->cash, 2),
            'coll_other' => round((float) $coll->total - (float) $coll->cash, 2),
        ];

        // ═══ قيمة العهدة الباقية — العهدة **الحالية** (عقيدة ١٠/٨) ═══
        // ⚠️ دي حالة «دلوقتي» مش بتاريخ منتقى — والشاشة بتقول كده
        // صراحةً تحت الرقم. التقييم بقايمة المندوب المعتمدة (السواق
        // قديمة والسيلز/المدير جديدة) من `CustodyValue` — ميمو
        // للريكوست، مفيش كويري سعر لكل صنف.
        $custody = $user->currentCustody();
        $custody?->load('items.product');
        $custodyList = \App\Support\CustodyValue::listForRep($user);
        $custodyTotals = \App\Support\CustodyValue::remainingTotals($custody);
        $custodyValue = round((float) ($custodyTotals[$custodyList?->id]['total'] ?? 0), 2);

        // كيلومترات اليوم — بفلتر شوشرة الـGPS الموثّق (`cleanKm`)
        $km = \App\Services\RepKpis::kmForDay($user, $date->copy());

        // ═══ الحضور — قراءة فقط من `AttendanceDay` ═══
        // ⚠️ مفيش `Attendance::state()` هنا — دي بتعمل `firstOrCreate`
        // (كتابة)، وصفحة عرض ليوم ممكن يكون في الماضي ماينفعش تفتح
        // أيام حضور. نفس قاعدة بورد المناديب بالحرف.
        $att = \App\Models\AttendanceDay::with('punches')
            ->where('user_id', $user->id)
            ->whereDate('date', $dateD)
            ->first();

        $attendance = [
            'in' => $hia($att?->first_in_at),
            'break_at' => $hia($att?->punches
                ->firstWhere('type', \App\Models\AttendancePunch::BREAK)?->at),
            'break_min' => (int) ($att?->break_minutes ?? 0),
            'out' => $hia($att?->last_out_at),
            'worked' => $att !== null
                ? \App\Models\AttendanceDay::hhmm($att->liveMinutes())
                : null,
        ];

        // ═══ تايم لاين اليوم — كل حركاته من سجل التتبع ═══
        // زي صفحة التراكينج بس ليستة من غير خريطة. `open` مستبعد عن
        // قصد — نفس سبب فيد اللايف: المندوب بيفتح الأبلكيشن عشرات
        // المرات في اليوم والتايم لاين بيغرق.
        $timeline = TrackEvent::where('user_id', $user->id)
            ->whereDate('happened_at', $dateD)
            ->where('type', '!=', 'open')
            ->orderBy('happened_at')
            ->get()
            ->map(fn ($e) => [
                'time' => $hia($e->happened_at),
                'icon' => $e->icon(),
                'color' => $e->color(),
                'title' => $e->title,
                'subtitle' => $e->subtitle,
            ])->values();

        // ═══ منتقي الموظف — التنقل بين أيام الفريق من غير رجوع للايف ═══
        // ⚠️ `FIELD_WORK_ROLES` مش `FIELD_ROLES` — المدير الميداني جوه
        // القايمة (طلب المالك ١٢/٨). و`canRep` بتصفّي اللي الفاعل مش
        // مسموح له يفتحه (مدير زميل مثلاً) — مفيش أوبشن بيودّي على 403.
        $repOptions = User::fieldVisibleTo(
            Branch::scope(User::whereIn('role', User::FIELD_WORK_ROLES)), $request->user())
            ->where('active', true)
            ->orderBy('name')
            ->get()
            ->filter(fn (User $u) => Scope::canRep($request->user(), $u))
            ->values();

        // ═══ صور ترتيب الرفوف بتاعة اليوم (2026-08-09) ═══
        // المدير بيشوف شغل المندوب على الرف قبل وبعد — مجمّعة بالعميل.
        $shelfPhotos = \App\Models\VisitPhoto::with('visit.client')
            ->whereIn('visit_id', Visit::where('user_id', $user->id)
                ->whereDate('created_at', $date)->select('id'))
            ->orderBy('id')
            ->get()
            ->groupBy(fn ($p) => $p->visit->client?->displayName() ?? '—');

        // ═══ ناتج كل زيارة في اليوم (١٥ أغسطس ٢٠٢٦) ═══
        // ⚠️ **باتش واحد لكل الصفوف** (`VisitOutcomes`) — الصفوف كانت
        // بتقول «تمت» وخلاص، من غير ما تقول طلع منها إيه. صف صف كان
        // معناه ٦ كويريز لكل زيارة في اليوم.
        $dayVisitIds = $rows->pluck('visit')->filter()
            ->pluck('id')
            ->merge($offPlan->pluck('id'))
            ->unique()->values()->all();

        return view('ops.rep_day', [
            'rep' => $user,
            'date' => $date,
            'rows' => $rows,
            'offPlan' => $offPlan,
            'summary' => $summary,
            'money' => $money,
            'custodyValue' => $custodyValue,
            'custodyList' => $custodyList,
            'custody' => $custody,
            'km' => $km,
            'att' => $attendance,
            'hasAtt' => $att !== null,
            'timeline' => $timeline,
            'repOptions' => $repOptions,
            'shelfPhotos' => $shelfPhotos,
            'visitOut' => \App\Support\VisitOutcomes::map($dayVisitIds),
        ]);
    }
}
