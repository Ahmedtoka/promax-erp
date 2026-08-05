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

    public function live(Request $request)
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

        // ⚠️ آخر موقع لكل مندوب في كويري واحدة. لوب بيسأل عن آخر
        // حدث لكل مندوب = كويري لكل صف، والصفحة دي بتترفرش لوحدها.
        $lastEvents = TrackEvent::whereIn('user_id', $reps->pluck('id'))
            ->whereDate('created_at', today())
            ->whereNotNull('lat')
            ->orderByDesc('created_at')
            ->get()
            ->unique('user_id')
            ->keyBy('user_id');

        $rows = $reps->map(function (User $rep) use ($lastEvents) {
            // من العلاقة المحمّلة فوق — مش كويري جديدة
            $custody = $rep->custodies->first();
            $summary = Journeys::summary($rep);
            $last = $lastEvents->get($rep->id);

            return [
                'rep' => $rep,
                'custody' => $custody,
                'remaining_units' => $custody?->remainingUnits() ?? 0,
                'remaining_value' => round($custody?->remainingValue('new') ?? 0, 2),
                'summary' => $summary,
                'last' => $last,
                'lat' => $last?->lat,
                'lng' => $last?->lng,
                'minutes_ago' => $last
                    ? (int) round(abs(now()->diffInMinutes($last->created_at)))
                    : null,
            ];
        });

        return view('ops.live', [
            'rows' => $rows,
            'onMap' => $rows->filter(fn ($r) => $r['lat'] !== null)->values(),
            'totals' => [
                'reps' => $rows->count(),
                'active' => $rows->filter(fn ($r) => $r['last'] !== null)->count(),
                'planned' => $rows->sum(fn ($r) => $r['summary']['planned']),
                'done' => $rows->sum(fn ($r) => $r['summary']['done']),
                'value' => round($rows->sum(fn ($r) => $r['remaining_value']), 2),
            ],
            'date' => today()->toDateString(),
        ]);
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
