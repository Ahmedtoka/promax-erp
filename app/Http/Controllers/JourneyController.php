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
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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

        $available = Client::with(['zone', 'group'])->where('status', 'active')
            ->where(function ($q) use ($rep) {
                $q->where('rep_id', $rep->id);
                if ($rep->zone_id) {
                    $q->orWhere('zone_id', $rep->zone_id);
                }
            })
            ->whereNotIn('id', $planned)
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

    public function destroy(JourneyPlan $journeyPlan)
    {
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

        DB::transaction(function () use ($data) {
            // ⚠️ **كل الصفوف لازم تكون نفس المندوب ونفس اليوم.**
            // `exists:` بتتأكد إن الصف موجود بس — بوست بايت أو معدّل
            // كان بيرقّم يوم مندوب تاني ويخربط خط سيره في صمت.
            $plans = JourneyPlan::whereIn('id', $data['order'])->get();

            abort_if(
                $plans->pluck('user_id')->unique()->count() > 1
                    || $plans->pluck('weekday')->unique()->count() > 1,
                422,
            );

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

        // ⚠️ `group` محمّلة عشان الاسم بيتعرض «السلسلة — الفرع» —
        // من غيرها fullName() بيضرب استعلام لكل صف من الـ300.
        $mine = $rep
            ? Client::with(['zone', 'group'])->where('rep_id', $rep->id)->where('status', 'active')->orderBy('name')->get()
            : collect();

        // العملاء اللي مالهمش مندوب — دول اللي بيضيعوا في السيستم
        $orphans = Branch::scope(Client::with(['zone', 'group']))
            ->whereNull('rep_id')
            ->where('status', 'active')
            ->when($request->filled('zone'), fn ($q) => $q->where('zone_id', $request->input('zone')))
            // ⚠️ البحث في السيرفر مش المتصفح — القايمة مقصوصة على 300،
            // والعميل رقم 301 مش هيظهر بأي فلترة في المتصفح.
            ->when($request->filled('q'), function ($q) use ($request) {
                $s = $request->string('q')->trim()->value();
                // البحث باسم السلسلة كمان — الاسم المعروض بيبدأ بيها
                $q->where(fn ($w) => $w->where('name', 'like', "%$s%")
                    ->orWhere('name_en', 'like', "%$s%")
                    ->orWhere('code', 'like', "%$s%")
                    ->orWhereHas('group', fn ($g) => $g->where('name', 'like', "%$s%")
                        ->orWhere('name_en', 'like', "%$s%")));
            })
            ->orderBy('name')
            ->limit(300)
            ->get();

        return view('ops.assignments', [
            'reps' => $reps,
            'rep' => $rep,
            'zones' => $zones,
            'mine' => $mine,
            'orphans' => $orphans,
            'orphanTotal' => Branch::scope(Client::query())
                ->whereNull('rep_id')->where('status', 'active')->count(),
            'filters' => $request->only(['zone', 'q']),
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

        $rep = User::findOrFail($data['user_id']);
        $clients = 0;

        // ⚠️ بره الكلوجر — `$request` مش متمرّر جواه
        $syncZones = $request->boolean('zones_form');

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
        $client->update(['rep_id' => null]);

        return back()->with('ok', __('journey.unassigned', ['client' => $client->displayName()]));
    }

    // ═══════════════════════ الشاشة اللايف ═══════════════════════

    /** صفوف التيرمينال — مشتركة بين العرض الأول والـJSON (2026-08-06) */
    private function liveRows(Request $request)
    {
        // ⚠️ العهدة بتتحمّل مفلترة على النهارده. `todayCustody()` بتعمل
        // كويري جديدة في كل نداء، والشاشة دي بتترفرش لوحدها كل دقيقة.
        $reps = Branch::scope(User::with([
            'zone',
            'custodies' => fn ($q) => $q->whereDate('date', today())->with('items.product'),
        ]))
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
     * فيد التنبيهات — آخر أحداث اليوم الحقيقية: فواتير وزيارات.
     *
     * ⚠️ مفيش أحداث مصطنعة — الفيد بيقرا من نفس جداول الأرقام
     * (فواتير + زيارات) فكل سطر فيه وراه فلوس أو تشيك إن فعلي.
     *
     * @return list<array{t: string, kind: string, text: string}>
     */
    private function liveAlerts($reps): array
    {
        $ids = $reps->pluck('id');

        $sales = \App\Models\Invoice::with(['user:id,name,name_en', 'client:id,name,name_en'])
            ->whereDate('created_at', today())
            ->whereIn('user_id', $ids)
            ->latest()->take(10)->get()
            ->map(fn ($inv) => [
                'at' => $inv->created_at,
                'kind' => 'sale',
                'text' => __('journey.alert_sale', [
                    'rep' => $inv->user?->displayName() ?? '—',
                    'client' => $inv->client?->displayName() ?? '—',
                    'value' => number_format((float) $inv->grand_total),
                ]),
            ]);

        $visits = \App\Models\Visit::with(['user:id,name,name_en', 'client:id,name,name_en'])
            ->whereDate('checked_in_at', today())
            ->whereIn('user_id', $ids)
            ->orderByDesc('checked_in_at')->take(8)->get()
            ->map(fn ($v) => [
                'at' => $v->checked_out_at ?? $v->checked_in_at,
                'kind' => $v->checked_out_at ? 'checkout' : 'checkin',
                'text' => __($v->checked_out_at ? 'journey.alert_checkout' : 'journey.alert_checkin', [
                    'rep' => $v->user?->displayName() ?? '—',
                    'client' => $v->client?->displayName() ?? '—',
                ]),
            ]);

        return $sales->concat($visits)
            ->sortByDesc('at')
            ->take(14)
            ->map(fn ($a) => ['t' => $a['at']->format('H:i'), 'kind' => $a['kind'], 'text' => $a['text']])
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

    /** داتا التيرمينال JSON — الشاشة بتسحبها كل 15 ثانية من غير ريلود */
    public function liveData(Request $request)
    {
        return response()->json($this->livePayload($request));
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
        $activeByZone = \App\Models\Client::where('status', 'active')
            ->whereNotNull('zone_id')
            ->selectRaw('zone_id, COUNT(*) as n')->groupBy('zone_id')->pluck('n', 'zone_id');
        $pendingByZone = \App\Models\Client::where('status', 'pending')
            ->whereNotNull('zone_id')
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

        return [
            'totals' => $this->liveTotals($rows),
            'zones' => $zones,
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
        // ⚠️ `Carbon::parse` على نص عبيط بترمي استثناء و500. الفحص
        // بيرجّع النهارده بدل ما الصفحة تقع من باراميتر في اللينك.
        $date = rescue(
            fn () => $request->filled('date') ? Carbon::parse($request->input('date')) : today(),
            fn () => today(),
            report: false,
        );

        return view('ops.rep_day', [
            'rep' => $user,
            'date' => $date,
            'rows' => Journeys::forDay($user, $date),
            'offPlan' => Journeys::offPlan($user, $date),
            'summary' => Journeys::summary($user, $date),
        ]);
    }
}
