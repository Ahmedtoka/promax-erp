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
        $reps = User::fieldVisibleTo(Branch::scope(User::with('zone')))
            ->whereIn('role', User::FIELD_ROLES)
            ->where('active', true)
            ->orderBy('name')
            ->get();

        $rep = $request->filled('rep')
            ? $reps->firstWhere('id', (int) $request->input('rep'))
            : $reps->first();

        if ($rep === null) {
            return view('ops.journeys', [
                'reps' => $reps, 'rep' => null, 'week' => [],
                'available' => collect(), 'weekdays' => JourneyPlan::WEEKDAYS,
                'frequencies' => JourneyPlan::FREQUENCIES, 'today' => today()->dayOfWeek,
            ]);
        }

        $week = Journeys::week($rep);

        // ⚠️ العملاء المتاحين للإضافة = عملاء المندوب اللي **لسه**
        // مش في خطته. عرض العملاء كلهم بيخلّي حد يحط عميل مندوب تاني
        // في الخطة والاتنين يروحوا نفس المحل.
        $planned = collect($week)->flatten()->pluck('client_id')->unique();

        $available = Client::visibleTo(
            Client::with(['zone', 'group'])->where('status', 'active')
                ->where(function ($q) use ($rep) {
                    $q->where('rep_id', $rep->id);
                    if ($rep->zone_id) {
                        $q->orWhere('zone_id', $rep->zone_id);
                    }
                })
                ->whereNotIn('id', $planned),
            $request->user()
        )
            ->orderBy('name')
            ->get();

        // ═══ عرض الشهر — النمط الأسبوعي مفرود على تواريخ حقيقية ═══
        // (طلب المالك 2026-08-03): «عاوز الخطة بالشهر وقدامي بالتواريخ».
        // الخطة نمط، فكل يوم في الشهر بنسأل dueOn() — والتردد (كل
        // أسبوعين/شهري) بيبان صح على التقويم بدل ما يفضل رقم مخفي.
        $month = (string) $request->input('month', today()->format('Y-m'));

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

        $allPlans = JourneyPlan::with('client.group')
            ->where('user_id', $rep->id)
            ->where('active', true)
            ->orderBy('weekday')->orderBy('sort')
            ->get();

        $calendar = [];

        for ($d = $monthStart->copy(); $d->month === $monthStart->month; $d->addDay()) {
            $key = $d->toDateString();

            $calendar[$key] = $allPlans
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
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'weekday' => ['required', 'integer', 'between:0,6'],
            'every_weeks' => ['required', 'in:1,2,4'],
            'client_ids' => ['required', 'array', 'min:1'],
            'client_ids.*' => ['integer', 'exists:clients,id'],
        ]);

        // ⚠️ **`store` كانت بتتجاهل فلتر `index` نفسه** (تدقيق ٨/٨):
        // الشاشة بتوري مناديب الفريق بس، لكن الـPOST كان بيقبل أي
        // `user_id` وأي `client_ids`. نفس حارس `assign` بالظبط.
        $rep = User::with('zones')->findOrFail($data['user_id']);

        Scope::assertRep($request->user(), $rep);

        foreach (Client::whereIn('id', $data['client_ids'])->get() as $c) {
            Scope::assertClient($request->user(), $c);
            Scope::assertSameTeam($rep, $c);
            Scope::assertInZone($rep, $c);
        }

        $added = 0;

        DB::transaction(function () use ($data, &$added) {
            // ⚠️ الترتيب بيكمّل من آخر رقم موجود في اليوم ده. البداية
            // من صفر في كل إضافة بتخلي العملاء الجداد يتصدّروا الخطة
            // والمندوب يبدأ يومه من آخر المشوار.
            $sort = (int) JourneyPlan::where('user_id', $data['user_id'])
                ->where('weekday', $data['weekday'])
                ->max('sort');

            foreach ($data['client_ids'] as $clientId) {
                // ⚠️ `firstOrCreate` مش `create` — العميل ممكن يكون
                // في الخطة خلاص والـ UNIQUE هيرمي استثناء يوقّف الباقي
                $plan = JourneyPlan::firstOrCreate(
                    [
                        'user_id' => $data['user_id'],
                        'client_id' => $clientId,
                        'weekday' => $data['weekday'],
                    ],
                    [
                        'every_weeks' => (int) $data['every_weeks'],
                        'sort' => ++$sort,
                        'active' => true,
                    ],
                );

                if ($plan->wasRecentlyCreated) {
                    $added++;
                }
            }
        });

        return back()->with('ok', __('journey.added', ['count' => $added]));
    }

    public function destroy(Request $request, JourneyPlan $journeyPlan)
    {
        // ⚠️ **كان بلا أي فحص ملكية** — أي مدير بيمسح من خطة أي
        // مندوب في الشركة بالـid. الفحص على المندوب صاحب الخطة.
        Scope::assertRep($request->user(), $journeyPlan->user);

        $journeyPlan->delete();

        return back()->with('ok', __('journey.removed'));
    }

    /** ترتيب الزيارات في اليوم */
    public function reorder(Request $request)
    {
        $data = $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['integer', 'exists:journey_plans,id'],
        ]);

        DB::transaction(function () use ($data, $request) {
            // ⚠️ **كل الصفوف لازم تكون نفس المندوب ونفس اليوم.**
            // `exists:` بتتأكد إن الصف موجود بس — بوست بايت أو معدّل
            // كان بيرقّم يوم مندوب تاني ويخربط خط سيره في صمت.
            $plans = JourneyPlan::with('user')->whereIn('id', $data['order'])->get();

            abort_if(
                $plans->pluck('user_id')->unique()->count() > 1
                    || $plans->pluck('weekday')->unique()->count() > 1,
                422,
            );

            // ⚠️ والفحص فوق بيضمن **اتساق** الصفوف بس، مش **ملكيتها**.
            // من غير السطر ده أي مدير بيعيد ترتيب يوم أي مندوب.
            Scope::assertRep($request->user(), $plans->first()?->user);

            foreach ($data['order'] as $i => $id) {
                JourneyPlan::whereKey($id)->update(['sort' => $i + 1]);
            }
        });

        return back()->with('ok', __('journey.reordered'));
    }

    // ═══════════════════════ تخصيص المناطق والعملاء ═══════════════════════

    public function assignments(Request $request)
    {
        $reps = User::fieldVisibleTo(Branch::scope(User::with(['zone', 'zones'])))
            ->whereIn('role', User::FIELD_ROLES)
            ->where('active', true)
            ->orderBy('name')
            ->get();

        $rep = $request->filled('rep')
            ? $reps->firstWhere('id', (int) $request->input('rep'))
            : $reps->first();

        // ⚠️ `withCount` مش `with` — عدّ عملاء كل زون بالتحميل الكامل
        // بيجيب آلاف الصفوف عشان يعرض رقم.
        $zones = Branch::scope(Zone::withCount(['clients' => fn ($q) => $q->where('status', 'active')]))
            ->where('active', true)
            ->orderBy('code')
            ->get();

        // ═══ قايمة موحّدة: كل عميل الفاعل شايفه ═══
        // (طلب المالك ١٠/٨): «تخصيص العملاء صعب — عاوز أدوس على العميل
        // يتحوّل للمندوب على طول». بدل كارتين (عملاء المندوب + اليتامى)
        // بقى جدول واحد فيه **كل** العملاء ومعاهم مندوبهم الحالي وزرار
        // تخصيص/شيل لكل صف، وبلوك تحديد جماعي بينقل المحدد لمرة واحدة.
        //
        // ⚠️ `visibleTo` (سكوب المدير) **مع** `Branch::scope` (سكوب الفرع)
        // — الاتنين مطلوبين مع بعض، بالظبط زي حارس `Scope::canClient`:
        // `visibleTo` لوحدها بتعدّي مدير فرع على عميل فرع تاني.
        // ⚠️ `rep` محمّلة عشان عمود «المندوب الحالي» — من غيرها كويري
        // لكل صف. و`group` عشان `fullName()` بيبدأ باسم السلسلة.
        $only = (string) $request->input('only', '');

        $clients = Branch::scope(Client::visibleTo(Client::with(['zone', 'group', 'rep']), $request->user()))
            ->where('status', 'active')
            ->when($request->filled('zone'), fn ($q) => $q->where('zone_id', $request->input('zone')))
            ->when($only === 'orphans', fn ($q) => $q->whereNull('rep_id'))
            ->when($only === 'mine' && $rep, fn ($q) => $q->where('rep_id', $rep->id))
            // ⚠️ البحث في السيرفر مش المتصفح — القايمة مقصوصة على 500،
            // والعميل بعد الحد مش هيظهر بأي فلترة في المتصفح.
            ->when($request->filled('q'), function ($q) use ($request) {
                $s = $request->string('q')->trim()->value();
                // البحث باسم السلسلة كمان — الاسم المعروض بيبدأ بيها
                $q->where(fn ($w) => $w->where('name', 'like', "%$s%")
                    ->orWhere('name_en', 'like', "%$s%")
                    ->orWhere('code', 'like', "%$s%")
                    ->orWhereHas('group', fn ($g) => $g->where('name', 'like', "%$s%")
                        ->orWhere('name_en', 'like', "%$s%")));
            })
            // ⚠️ اليتامى الأول — دول اللي بيضيعوا، والمالك عايزهم فوق.
            // `rep_id IS NULL` بترجع 1 لليتيم فالـDESC بيطلّعهم لفوق.
            ->orderByRaw('rep_id IS NULL DESC')
            ->orderBy('name')
            ->limit(500)
            ->get();

        return view('ops.assignments', [
            'reps' => $reps,
            'rep' => $rep,
            'zones' => $zones,
            'clients' => $clients,
            'orphanTotal' => Branch::scope(Client::visibleTo(Client::query(), $request->user()))
                ->whereNull('rep_id')->where('status', 'active')->count(),
            'filters' => $request->only(['zone', 'q', 'only']),
        ]);
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
                $clients = Client::whereIn('id', $data['client_ids'])
                    ->update(['rep_id' => $rep->id]);
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
            ->whereIn('role', User::FIELD_ROLES)
            ->where('active', true)
            ->orderBy('name')
            ->get();

        // ⚠️ آخر موقعين لكل مندوب في كويري واحدة — الأول للمكان
        // والتاني لحساب السرعة اللحظية. لوب بيسأل لكل مندوب = كويري
        // لكل صف، والصفحة دي بتترفرش لوحدها.
        $eventsByUser = TrackEvent::whereIn('user_id', $reps->pluck('id'))
            ->whereDate('created_at', today())
            ->whereNotNull('lat')
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

        // اللي جوه زيارة مفتوحة دلوقتي — بينوّر بنفسجي على الخريطة
        $inVisit = \App\Models\Visit::whereIn('user_id', $reps->pluck('id'))
            ->whereDate('checked_in_at', today())
            ->whereNull('checked_out_at')
            ->pluck('user_id')->flip();

        $rows = $reps->map(function (User $rep) use ($eventsByUser, $lastEvents, $salesToday, $inVisit) {
            // من العلاقة المحمّلة فوق — مش كويري جديدة
            $custody = $rep->custodies->first();
            $summary = Journeys::summary($rep);
            $last = $lastEvents->get($rep->id);
            $minutes = $last ? (int) round(abs(now()->diffInMinutes($last->created_at))) : null;

            // الحالة: زيارة ← متحرك (إشارة < 10 دقايق) ← واقف ← مفيش إشارة
            $status = match (true) {
                $inVisit->has($rep->id) => 'visit',
                $minutes !== null && $minutes < 10 => 'moving',
                $minutes !== null => 'idle',
                default => 'off',
            };

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

            return [
                'rep' => $rep,
                'custody' => $custody,
                'remaining_units' => $custody?->remainingUnits() ?? 0,
                'remaining_value' => round($custody?->remainingValue('new') ?? 0, 2),
                'summary' => $summary,
                'last' => $last,
                'lat' => $last?->lat,
                'lng' => $last?->lng,
                'minutes_ago' => $minutes,
                'status' => $status,
                'sales_today' => round((float) ($salesToday[$rep->id] ?? 0), 2),
                'km_today' => \App\Services\RepKpis::kmForDay($rep, now()),
                'speed' => $speed,
                'in_zone' => $inZone,
                'items' => $items,
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
            ->take(25)
            ->get()
            ->map(fn ($e) => [
                't' => $e->happened_at->format('H:i'),
                'kind' => $e->type,
                'icon' => $e->icon(),
                'color' => $e->color(),
                'rep' => $names[$e->user_id] ?? '—',
                'text' => trim($e->title.($e->subtitle ? ' · '.$e->subtitle : '')),
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
        ];
    }

    /** يوم مندوب واحد — بيتفتح من الشاشة اللايف */
    public function repDay(Request $request, User $user)
    {
        // ⚠️ **فلترة الشاشة اللايف بتخبّي الصف عن العين مش عن الراوت**
        // — `/ops/live/{user}` كان بيفتح يوم أي مندوب بالـid: مساره
        // وزياراته وفواتيره. الحارس هنا هو اللي بيقفلها فعلاً.
        Scope::assertRep($request->user(), $user);

        // ⚠️ `Carbon::parse` على نص عبيط بترمي استثناء و500. الفحص
        // بيرجّع النهارده بدل ما الصفحة تقع من باراميتر في اللينك.
        $date = rescue(
            fn () => $request->filled('date') ? Carbon::parse($request->input('date')) : today(),
            fn () => today(),
            report: false,
        );

        // ═══ صور ترتيب الرفوف بتاعة اليوم (2026-08-09) ═══
        // المدير بيشوف شغل المندوب على الرف قبل وبعد — مجمّعة بالعميل.
        $shelfPhotos = \App\Models\VisitPhoto::with('visit.client')
            ->whereIn('visit_id', \App\Models\Visit::where('user_id', $user->id)
                ->whereDate('created_at', $date)->select('id'))
            ->orderBy('id')
            ->get()
            ->groupBy(fn ($p) => $p->visit->client?->displayName() ?? '—');

        return view('ops.rep_day', [
            'rep' => $user,
            'date' => $date,
            'rows' => Journeys::forDay($user, $date),
            'offPlan' => Journeys::offPlan($user, $date),
            'summary' => Journeys::summary($user, $date),
            'shelfPhotos' => $shelfPhotos,
        ]);
    }
}
