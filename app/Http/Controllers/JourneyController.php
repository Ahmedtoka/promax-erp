<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Client;
use App\Models\JourneyPlan;
use App\Models\TrackEvent;
use App\Models\User;
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
        $reps = Branch::scope(User::with('zone'))
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

        $available = Client::where('status', 'active')
            ->where(function ($q) use ($rep) {
                $q->where('rep_id', $rep->id);
                if ($rep->zone_id) {
                    $q->orWhere('zone_id', $rep->zone_id);
                }
            })
            ->whereNotIn('id', $planned)
            ->orderBy('name')
            ->get();

        return view('ops.journeys', [
            'reps' => $reps,
            'rep' => $rep,
            'week' => $week,
            'available' => $available,
            'weekdays' => JourneyPlan::WEEKDAYS,
            'frequencies' => JourneyPlan::FREQUENCIES,
            'today' => today()->dayOfWeek,
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
            foreach ($data['order'] as $i => $id) {
                JourneyPlan::whereKey($id)->update(['sort' => $i + 1]);
            }
        });

        return back()->with('ok', __('journey.reordered'));
    }

    // ═══════════════════════ تخصيص المناطق والعملاء ═══════════════════════

    public function assignments(Request $request)
    {
        $reps = Branch::scope(User::with(['zone', 'zones']))
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

        $mine = $rep
            ? Client::with('zone')->where('rep_id', $rep->id)->where('status', 'active')->orderBy('name')->get()
            : collect();

        // العملاء اللي مالهمش مندوب — دول اللي بيضيعوا في السيستم
        $orphans = Branch::scope(Client::with('zone'))
            ->whereNull('rep_id')
            ->where('status', 'active')
            ->when($request->filled('zone'), fn ($q) => $q->where('zone_id', $request->input('zone')))
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
            'filters' => $request->only(['zone']),
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
