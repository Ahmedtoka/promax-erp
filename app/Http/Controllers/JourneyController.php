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
        $reps = User::fieldVisibleTo(Branch::scope(User::with('zone')))
            ->whereIn('role', User::FIELD_WORK_ROLES)
            ->where('active', true)
            ->orderBy('name')
            ->get();

        // ═══ المدير الميداني (١١ أغسطس ٢٠٢٦): بيعمل خط سير **لنفسه** ═══
        // القايمة فوق «فريق الشارع» — والمدير مش في `FIELD_ROLES` عن
        // قصد (عشان تقارير الفريق). هنا بس بيضيف نفسه عشان ينظّم
        // شغله، و`Scope::assertRep` في store/destroy/reorder بتسمح
        // له **على نفسه بس**. شاشات الأدمن زي ما هي.
        $viewer = $request->user();

        if ($viewer !== null && $viewer->role === 'manager'
            && ! $reps->contains('id', $viewer->id)) {
            $reps->push($viewer->load('zone'));
        }

        $rep = $request->filled('rep')
            ? $reps->firstWhere('id', (int) $request->input('rep'))
            : $reps->first();

        // عدّ زرار المسح الشامل — «هتمسح X خطة» في رسالة التأكيد.
        // نفس سكوب `wipe()` بالظبط: الأدمن الكل، والمدير فريقه.
        $wipeCount = $this->wipeQuery($viewer)->count();

        if ($rep === null) {
            return view('ops.journeys', [
                'reps' => $reps, 'rep' => null, 'week' => [],
                'available' => collect(), 'weekdays' => JourneyPlan::WEEKDAYS,
                'frequencies' => JourneyPlan::FREQUENCIES, 'today' => today()->dayOfWeek,
                'wipeCount' => $wipeCount,
                'weekStart' => today()->startOfWeek(Carbon::SUNDAY),
                'board' => ['pool' => [], 'plans' => []],
            ]);
        }

        $week = Journeys::week($rep);

        // ⚠️ العملاء المتاحين للإضافة = عملاء المندوب اللي **لسه**
        // مش في خطته. عرض العملاء كلهم بيخلّي حد يحط عميل مندوب تاني
        // في الخطة والاتنين يروحوا نفس المحل.
        //
        // ═══ بول الفريق (١١/٨ مساءً) ═══ «عملاء المندوب» بقت بول
        // فريقه: أي عميل تحت نفس مديره ينفع يتخطط له — تغطية الغايب
        // من غير نقل عملاء. عميل فريق تاني برضه مش بيظهر.
        $planned = collect($week)->flatten()->pluck('client_id')->unique();

        $available = Client::visibleTo(
            // ⚠️ `channel` للبورد (٢١/٨) — شيبس الفلترة بالقناة في السايدبار
            Client::with(['zone', 'group', 'channel'])->where('status', 'active')
                ->where(function ($q) use ($rep) {
                    // المدير الميداني (١١/٨): محطات خطته من عملاءه
                    // المتسكّنين له (`manager_id`) — نفس مرساة الزيارة.
                    if ($rep->role === 'manager') {
                        $q->where('manager_id', $rep->id);

                        return;
                    }

                    $q->where('rep_id', $rep->id);

                    // البول المشترك — كل عملاء مديره
                    if ($rep->manager_id !== null) {
                        $q->orWhere('manager_id', $rep->manager_id);
                    }

                    if ($rep->zone_id) {
                        $q->orWhere('zone_id', $rep->zone_id);
                    }
                })
                ->whereNotIn('id', $planned),
            $request->user()
        )
            ->orderBy('name')
            ->get();

        // ═══ ملاح الأسبوع (بورد ٢١/٨) — تواريخ فوق أعمدة الأيام ═══
        //
        // الخطة نمط أسبوعي؛ الأسبوع المختار **للعرض والسياق** (تواريخ
        // الأعمدة + شهر النبضة الافتراضي) مش لتخزين تواريخ.
        try {
            $weekStart = $request->filled('week')
                ? Carbon::parse((string) $request->input('week'))
                    ->startOfWeek(Carbon::SUNDAY)
                : today()->startOfWeek(Carbon::SUNDAY);
        } catch (\Throwable) {
            $weekStart = today()->startOfWeek(Carbon::SUNDAY);
        }

        // ═══ داتا البورد (الدراج أند دروب ٢١/٨) — جاهزة للجافاسكربت ═══
        //
        // ⚠️ آخر زيارة بكويري مجمّع واحد للبول كله — مش كويري لكل عميل
        $weekPlans = collect($week)->flatten();

        \Illuminate\Database\Eloquent\Collection::make(
            $weekPlans->pluck('client')->filter()->unique('id')->values()
        )->loadMissing(['zone', 'channel', 'group']);

        $poolIds = $available->pluck('id')
            ->merge($weekPlans->pluck('client_id'))->unique()->values();

        $lastVisitAt = $poolIds->isEmpty()
            ? collect()
            : Visit::whereIn('client_id', $poolIds)
                ->whereNotNull('checked_in_at')
                ->selectRaw('client_id, MAX(checked_in_at) as t')
                ->groupBy('client_id')
                ->pluck('t', 'client_id');

        $clientRow = fn (Client $c) => [
            'id' => $c->id,
            'name' => $c->fullName(),
            'zone' => $c->zone?->displayName(),
            'channel' => $c->channel?->displayName(),
            'balance' => (float) $c->balance,
            // كتلة بحث عابرة اللغات — نفس فكرة الأبلكيشن
            'q' => mb_strtolower(trim($c->fullName().' '.$c->name_en.' '
                .($c->group?->name_en ?? '').' '.($c->zone?->displayName() ?? ''))),
            'last_days' => ($t = $lastVisitAt->get($c->id)) !== null
                ? Carbon::parse($t)->diffInDays(now()) : null,
        ];

        $board = [
            'pool' => $available->map($clientRow)->values()->all(),
            'plans' => $weekPlans->map(fn (JourneyPlan $p) => [
                'id' => $p->id,
                'weekday' => (int) $p->weekday,
                'sort' => (int) $p->sort,
                'every_weeks' => (int) $p->every_weeks,
                'time' => $p->visitTimeLabel(),
                'client' => $p->client !== null ? $clientRow($p->client) : null,
            ])->filter(fn ($r) => $r['client'] !== null)->values()->all(),
        ];

        // ═══ عرض الشهر — النمط الأسبوعي مفرود على تواريخ حقيقية ═══
        // (طلب المالك 2026-08-03): «عاوز الخطة بالشهر وقدامي بالتواريخ».
        // الخطة نمط، فكل يوم في الشهر بنسأل dueOn() — والتردد (كل
        // أسبوعين/شهري) بيبان صح على التقويم بدل ما يفضل رقم مخفي.
        $month = (string) $request->input('month', $weekStart->format('Y-m'));

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

        // الأدمن بيمسح الكل — وغيره فريقه بس (`fieldVisibleTo`:
        // مناديب المدير + نفسه، بعد قرار ١١/٨ إن المدير بيشتغل ميداني)
        if ($viewer === null || ! $viewer->isAdmin()) {
            $q->whereIn('user_id', User::fieldVisibleTo(User::query(), $viewer)->select('id'));
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

        abort_unless($viewer !== null
            && ($viewer->isAdmin() || $viewer->role === 'manager'), 403);

        $deleted = 0;

        DB::transaction(function () use ($viewer, &$deleted) {
            $deleted = (int) $this->wipeQuery($viewer)->delete();
        });

        return back()->with('ok', __('journey.wiped', ['count' => $deleted]));
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
                if (! empty($r['id'])) {
                    $existing->get((int) $r['id'])?->update([
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
                    [
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

    public function assignments(Request $request)
    {
        $reps = User::fieldVisibleTo(Branch::scope(User::with(['zone', 'zones'])))
            ->whereIn('role', User::FIELD_WORK_ROLES)
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

        $clients = Branch::scope(Client::visibleTo(Client::with(['zone', 'group', 'rep', 'manager']), $request->user()))
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
            'pools' => $this->managerPools($request->user()),
        ]);
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
     * «شاشة فيها إجمالي العملاء بالمحافظة والمناطق والمناديب،
     *  وأقدر منها أعمل خط سير أسرع» — طلب المالك ١٣ أغسطس ٢٠٢٦
     * ═══════════════════════════════════════════════════════════
     *
     * تلات مستويات: فرق المديرين فوق ← شجرة محافظة/منطقة في النص ←
     * لوحة المنطقة بعملاءها (AJAX) اللي بيتعمل منها خط السير.
     *
     * ⚠️ **الكتابة بتمر على نفس مسار `store()`** — نفس الموديل ونفس
     * حراس `Scope` ونفس منع التكرار. مفيش كاتب موازي لخطط السير:
     * كاتب تاني معناه قاعدة تتصلّح في مكان وتفضل غلط في التاني.
     *
     * ⚠️ **العميل بيتجمّع بعمود `clients.governorate` مش بمحافظة
     * الزون.** العمود على العميل هو المصدر المعتمد (`Client::governorateLabel`)
     * — عميل اتنقل لزون في محافظة تانية وعموده لسه القديم بيبان
     * في مكانه الصح، والتجميع بمحافظة الزون كان هينقله في صمت.
     *
     * ⚠️ **مفيش N+1**: الشجرة كلها من كويريز مجمّعة (`GROUP BY`)،
     * وعملاء المنطقة مابيتحمّلوش غير لما اللوحة تتفتح — 670+ عميل
     * في الصفحة الأولى كان هيخلّيها تقيلة على الفاضي.
     */
    public function geo(Request $request)
    {
        $viewer = $request->user();

        // ═══ ١. كروت فرق المديرين ═══
        // ⚠️ المدير بيشوف كارته هو بس — نفس سكوب `managerPools`.
        $managers = Branch::scope(User::where('role', 'manager')->where('active', true))
            ->when($viewer !== null && $viewer->role === 'manager',
                fn ($q) => $q->whereKey($viewer->id))
            ->orderBy('name')
            ->get();

        $managerIds = $managers->pluck('id');

        // كويري واحدة لكل الفرق — مش كويري لكل مدير
        // ⚠️ `Branch::scope` + `fieldVisibleTo` هنا كمان: الشاشة
        // دلوقتي `role:admin,manager` بس، وأول ما تتفتح لمدير فرع
        // الكويري دي هي الوحيدة اللي كانت هتعدّي الفروع.
        $teamReps = $managerIds->isEmpty()
            ? collect()
            : User::fieldVisibleTo(Branch::scope(User::query()), $viewer)
                ->whereIn('manager_id', $managerIds)
                ->whereIn('role', User::FIELD_ROLES)
                ->where('active', true)
                ->orderBy('name')
                ->get();

        // ═══ عدّ البول: مدير × مندوب — كويري واحدة ═══
        // ⚠️ **مش `GROUP BY rep_id` لوحده.** عدّ المندوب على مستوى
        // الشركة كله بيتحط جنب إجمالي المدير فبيطلع صف شيبس مجموعها
        // مش الرقم الكبير اللي فوقه: عميل مدير تاني متسكّن على مندوب
        // من الفريق ده كان بيزوّد الشيب، وعميل بلا `rep_id` كان
        // بيدخل الإجمالي ومايظهرش في أي شيب. التجميع بالاتنين مع بعض
        // بيخلّي **كل عميل في البول له شيب واحد بالظبط**، والمجموع
        // بيطابق العنوان بالمليم.
        $poolRows = $managerIds->isEmpty()
            ? collect()
            : Branch::scope(Client::visibleTo(Client::query(), $viewer))
                ->where('status', 'active')
                ->whereIn('manager_id', $managerIds)
                ->selectRaw('manager_id, rep_id, COUNT(*) as n')
                ->groupBy('manager_id', 'rep_id')
                ->get();

        // تغطية جغرافية لكل مدير: مناطق + محافظات مميزة
        $cover = Branch::scope(Client::visibleTo(Client::query(), $viewer))
            ->where('status', 'active')
            ->whereNotNull('manager_id')
            ->selectRaw('manager_id, COUNT(DISTINCT zone_id) as zn, COUNT(DISTINCT governorate) as gn')
            ->groupBy('manager_id')
            ->get()
            ->keyBy('manager_id');

        // مناديب ظاهرين في البول بس مش في فريق المدير (داتا قديمة)
        // — بيتجابوا في كويري واحدة عشان الشيب مايختفيش والمجموع
        // مايتفركشش
        $strayIds = $poolRows->pluck('rep_id')->filter()
            ->diff($teamReps->pluck('id'))
            ->diff($managerIds)
            ->unique()->values();

        $strays = $strayIds->isEmpty()
            ? collect()
            : User::whereIn('id', $strayIds->all())->get()->keyBy('id');

        $byManager = $poolRows->groupBy('manager_id');
        $repsByManager = $teamReps->groupBy('manager_id');

        // ═══ فصل الرولز جوه الفريق (طلب المالك ١٣ أغسطس ٢٠٢٦) ═══
        // «افصلي المناديب عن السواقين» — الشيبس كانت خليط واحد، فمدير
        // عنده ٦ ناس مابتقولش كام مندوب بيع وكام سواق. الصفوف بقت
        // مسمّاة بالرول، وكل شيب لسه بعدد عملاء صاحبه — فالمجموع
        // بيفضل مطابق للرقم الكبير بالمليم زي ما كان.
        $teams = $managers->map(function (User $m) use ($byManager, $repsByManager, $strays, $cover) {
            $rows = $byManager->get($m->id, collect());

            // 0 = «بدون مندوب أساسي» — مفتاح صالح في PHP وrep_id عمره
            // ما هيبقى 0 في الداتابيز
            $counts = $rows->mapWithKeys(fn ($r) => [(int) ($r->rep_id ?? 0) => (int) $r->n]);

            $team = $repsByManager->get($m->id, collect());

            $groups = [];

            // ⚠️ الترتيب من `User::FIELD_ROLES` نفسها مش قايمة محلية —
            // رول ميداني جديد بيظهر هنا لوحده من غير ما حد يفتكر.
            foreach (User::FIELD_ROLES as $role) {
                $members = $team->where('role', $role)
                    ->map(fn (User $r) => [
                        'user' => $r,
                        'clients' => (int) ($counts[$r->id] ?? 0),
                    ])
                    ->values();

                if ($members->isEmpty()) {
                    continue;
                }

                $groups[] = [
                    'role' => $role,
                    'label' => __('journey.geo_role_'.$role),
                    'reps' => $members->all(),
                    'clients' => (int) $members->sum('clients'),
                ];
            }

            // مناديب من بره الفريق ليهم عملاء في البول — بيبانوا عشان
            // المجموع يقفل، واللي بيقرا يعرف إن فيه تسكين غريب
            $others = collect();

            foreach ($counts as $rid => $n) {
                if ($rid === 0 || $rid === (int) $m->id || $team->contains('id', $rid)) {
                    continue;
                }

                if ($strays->has($rid)) {
                    $others->push(['user' => $strays->get($rid), 'clients' => $n]);
                }
            }

            if ($others->isNotEmpty()) {
                $groups[] = [
                    'role' => 'other',
                    'label' => __('journey.geo_role_other'),
                    'reps' => $others->values()->all(),
                    'clients' => (int) $others->sum('clients'),
                ];
            }

            return [
                'manager' => $m,
                'groups' => $groups,
                // عدد مناديب البيع — زرار «توزيع تلقائي» مالوش لازمة
                // من غيرهم (السواق والبروموتر مستبعدين افتراضياً)
                'sales_count' => (int) $team->where('role', 'sales_agent')->count(),
                'driver_count' => (int) $team->where('role', 'driver')->count(),
                'own' => (int) ($counts[$m->id] ?? 0),
                'no_rep' => (int) ($counts[0] ?? 0),
                'clients' => (int) $rows->sum('n'),
                'zones' => (int) ($cover[$m->id]->zn ?? 0),
                'govs' => (int) ($cover[$m->id]->gn ?? 0),
            ];
        })->values()->all();

        // ═══ ٢. فلتر الفريق — باراميتر مش فلترة متصفح ═══
        // ⚠️ الفلترة في الكويري عن قصد: مع 670+ عميل، فلترة الشجرة
        // في المتصفح معناها إن الأرقام (المحافظات والمناطق والنِسَب)
        // تفضل بتاعة الكل والعناوين تكدب على اللي بيقرا.
        $picked = $request->filled('manager')
            ? $managers->firstWhere('id', (int) $request->input('manager'))
            : null;

        $search = trim((string) $request->input('q', ''));

        // ═══ فلتر «بدون مندوب» (إصلاح ١٣ أغسطس ٢٠٢٦) ═══
        // ⚠️ **ده كان باج**: شيب «بدون مندوب» في كارت المدير كان جوه
        // اللينك بتاع الكارت كله، فالدوسة عليه كانت بتفلتر على المدير
        // وبس — والشاشة اللي بتفتح مليانة **مناديب** في عمود «بيغطيها».
        // بقى فلتر حقيقي في السيرفر: الشجرة والأرقام واللوحة كلها
        // بتتقص على `rep_id IS NULL`.
        $noRepOnly = $request->boolean('norep');

        // مُنتج الكويري الأساسية — كل نداء بيرجّع بيلدر نضيف عشان
        // `selectRaw` بتاع كويري ماتسربش على اللي بعدها
        $base = function () use ($viewer, $picked, $search, $noRepOnly) {
            $q = Branch::scope(Client::visibleTo(Client::query(), $viewer))
                ->where('status', 'active');

            if ($picked !== null) {
                $q->where('manager_id', $picked->id);
            }

            if ($noRepOnly) {
                $q->whereNull('rep_id');
            }

            if ($search !== '') {
                Client::search($q, $search);
            }

            return $q;
        };

        // ═══ ٣. الشجرة — أربع كويريز مجمّعة وبس ═══
        // (أ) عدّ العملاء بالمحافظة × المنطقة — بتخدم المستويين مع بعض
        $cells = $base()
            ->selectRaw('governorate, zone_id, COUNT(*) as n')
            ->groupBy('governorate', 'zone_id')
            ->get();

        // (ب) اللي مالوش خطة سير شغالة — بنفس التجميع
        $noPlanCells = $base()
            ->whereNotIn('id', JourneyPlan::where('active', true)->select('client_id'))
            ->selectRaw('governorate, zone_id, COUNT(*) as n')
            ->groupBy('governorate', 'zone_id')
            ->get()
            ->mapWithKeys(fn ($r) => [($r->governorate ?: '_none').'|'.($r->zone_id ?: '_none') => (int) $r->n]);

        // (ج) مين بيغطي كل منطقة — من `rep_id` بتاع عملاءها
        $zoneRepCells = $base()
            ->whereNotNull('rep_id')
            ->whereNotNull('zone_id')
            ->selectRaw('zone_id, rep_id, COUNT(*) as n')
            ->groupBy('zone_id', 'rep_id')
            ->get();

        // (د) الأرقام العامة — كويري واحدة بتلات مجاميع
        $agg = $base()
            ->selectRaw('COUNT(*) as total, SUM(rep_id IS NULL) as no_rep, SUM(lat IS NULL) as no_loc')
            ->first();

        // لوك-أب صغيّرة (عشرات الصفوف): أسماء المناطق + المناديب
        $zoneNames = Zone::whereIn('id', $cells->pluck('zone_id')->filter()->unique()->all())
            ->get()->keyBy('id');

        $repUsers = User::whereIn('id', $zoneRepCells->pluck('rep_id')->unique()->all())
            ->get()->keyBy('id');

        $repsByZone = $zoneRepCells->groupBy('zone_id');

        $total = (int) ($agg->total ?? 0);
        $noPlanTotal = (int) $noPlanCells->sum();

        // ═══ بناء الشجرة في الذاكرة — مفيش كويري جوه اللوب ═══
        $tree = [];

        foreach ($cells as $cell) {
            $gk = $cell->governorate ?: '_none';
            $zk = $cell->zone_id ? (int) $cell->zone_id : null;
            $n = (int) $cell->n;

            $tree[$gk] ??= [
                'key' => $gk,
                'label' => $gk === '_none' ? __('geo.no_governorate') : Governorates::label($gk),
                'clients' => 0,
                'no_plan' => 0,
                'zones' => [],
            ];

            $tree[$gk]['clients'] += $n;

            $noPlan = $noPlanCells[$gk.'|'.($zk ?: '_none')] ?? 0;
            $tree[$gk]['no_plan'] += $noPlan;

            $zone = $zk !== null ? $zoneNames->get($zk) : null;

            $tree[$gk]['zones'][] = [
                'id' => $zk,
                'name' => $zone?->displayName() ?? __('journey.geo_no_zone'),
                'code' => $zone?->code,
                'clients' => $n,
                'no_plan' => $noPlan,
                'reps' => $zk === null ? [] : $repsByZone->get($zk, collect())
                    ->sortByDesc('n')
                    ->map(fn ($r) => ['user' => $repUsers->get($r->rep_id), 'n' => (int) $r->n])
                    ->filter(fn ($r) => $r['user'] !== null)
                    ->values()->all(),
            ];
        }

        // ⚠️ ترتيب المحافظات جغرافي (`Governorates::keys`) مش أبجدي —
        // القاهرة الكبرى فوق لأنها أغلب العملاء، و«بدون محافظة» آخر
        // حاجة لأنها بند تنضيف مش مكان.
        $order = array_flip(Governorates::keys());

        uasort($tree, fn ($a, $b) => ($order[$a['key']] ?? 900) <=> ($order[$b['key']] ?? 900));

        foreach ($tree as $gk => $row) {
            usort($tree[$gk]['zones'], fn ($a, $b) => $b['clients'] <=> $a['clients']);
        }

        // ═══ ٤. أول زيارة لكل (يوم × تردد) — معاينة التاريخ ═══
        // ⚠️ **مفيش جدول تواريخ.** الخطة نمط أسبوعي، والمالك عايز
        // «يختار تاريخ» — فبنحسب أول استحقاق من `dueOn` نفسها ونعرضه
        // جنب الاختيار. لو حسبناه في الجافاسكربت كان هيتفرّع منطق
        // التردد لنسختين، وأول تعديل في `epoch()` هيخلّيهم مختلفين.
        $nextDue = [];

        foreach (JourneyPlan::WEEKDAYS as $w) {
            foreach (JourneyPlan::FREQUENCIES as $f) {
                $d = JourneyPlan::nextDue($w, $f);

                $nextDue[$w.'-'.$f] = $d === null
                    ? '—'
                    : __('journey.day_'.$w).' — '.$d->format('Y-m-d');
            }
        }

        return view('ops.geo_planner', [
            'teams' => $teams,
            'picked' => $picked,
            'tree' => array_values($tree),
            'total' => $total,
            // ⚠️ «بدون محافظة» بند تنضيف مش محافظة — عدّه في الرقم
            // كان بيدي «١٤ محافظة» والشركة شغالة في ١٣
            'govCount' => count(array_diff(array_keys($tree), ['_none'])),
            'zoneCount' => $cells->pluck('zone_id')->filter()->unique()->count(),
            'noPlanTotal' => $noPlanTotal,
            'noRepTotal' => (int) ($agg->no_rep ?? 0),
            'noLocTotal' => (int) ($agg->no_loc ?? 0),
            'nextDue' => $nextDue,
            'filters' => ['q' => $search, 'manager' => $picked?->id, 'norep' => $noRepOnly],
        ]);
    }

    /**
     * لوحة المنطقة — JSON خفيف بيتحمّل وقت الفتح بس.
     *
     * ⚠️ **AJAX مش تضمين.** 670+ عميل موزّعين على ~49 منطقة: تضمينهم
     * كلهم في الصفحة معناه صفحة بميجابايت الناس هتفتحها عشان تبص على
     * منطقة واحدة. اللوحة بتجيب منطقتها وبس.
     *
     * ⚠️ **العرض متسكوب زي الكتابة**: `visibleTo` + `Branch::scope`.
     * فلترة القايمة مش حماية، بس الرد ده بيتقري من الشاشة فلازم
     * يبقى نفس السكوب اللي الحارس هيطبّقه وقت الحفظ.
     *
     * ⚠️ **نفس فلتر `q` بتاع الشجرة لازم يتبعت هنا** — صف بيقول
     * «٣ محلات» يفتح لوحة فيها ٤٠، و«اختيار الكل» يخطّط لمحلات
     * اللي بيبص عمره ما شافها.
     *
     * ⚠️ **الخطط بتتقسم قسمين**: `plans` اللي الفاعل مسؤول عنها
     * (`Scope::canRep`) — دي اللي ليها زرار شيل والفورم بيتملى منها،
     * و`foreign` خطط فرق تانية بتتعرض للعلم بس. لو رجّعناهم مع بعض،
     * زرار «شيل» كان بيبعت الاتنين في دفعة واحدة و`geoUnplan` يرمي
     * 403 على الكل — والمستخدم يشوف «حصل خطأ» من غير سبب.
     */
    public function geoZone(Request $request, Zone $zone)
    {
        $viewer = $request->user();
        $search = trim((string) $request->input('q', ''));

        // ⚠️ نفس فلتر «بدون مندوب» بتاع الشجرة لازم يوصل هنا — صف
        // بيقول «٤ محلات بلا مندوب» يفتح لوحة فيها ٤٠ محل، والمالك
        // يفتكر الفلتر مابيشتغلش.
        $noRepOnly = $request->boolean('norep');

        $clients = Branch::scope(
            Client::visibleTo(Client::with(['group', 'rep']), $viewer)
        )
            ->where('status', 'active')
            ->where('zone_id', $zone->id)
            ->when($request->filled('manager'),
                fn ($q) => $q->where('manager_id', (int) $request->input('manager')))
            ->when($noRepOnly, fn ($q) => $q->whereNull('rep_id'))
            ->when($search !== '', fn ($q) => Client::search($q, $search))
            ->orderBy('name')
            ->get();

        // ═══ سياق المحافظة (طلب المالك ١٣/٨) ═══
        // «في الشاشة الثانية عاوز المحافظة والمناطق» — اللوحة كانت
        // بتقول اسم المنطقة وبس. دلوقتي فيها **المحافظة › المنطقة**
        // وكام محل في كل مستوى، عشان اللي بيخطط يعرف نسبة المنطقة
        // من محافظتها قبل ما يوزّع أيام الأسبوع.
        //
        // ⚠️ العدّ بعمود `clients.governorate` مش بمحافظة الزون —
        // نفس مصدر الشجرة بالظبط (`geo()`)، وإلا الرقمين بيختلفوا.
        $govKey = (string) ($zone->governorate ?? '');

        $govBase = fn () => Branch::scope(Client::visibleTo(Client::query(), $viewer))
            ->where('status', 'active')
            ->when($request->filled('manager'),
                fn ($q) => $q->where('manager_id', (int) $request->input('manager')))
            ->when($noRepOnly, fn ($q) => $q->whereNull('rep_id'))
            ->when($search !== '', fn ($q) => Client::search($q, $search))
            ->when($govKey !== '', fn ($q) => $q->where('governorate', $govKey),
                fn ($q) => $q->whereNull('governorate'));

        $govClients = (int) $govBase()->count();
        $govZones = (int) $govBase()->distinct()->count('zone_id');

        // ⚠️ `active` بس — نفس تعريف «مخطط» اللي الشجرة والـKPIs
        // شغالين بيه (`geo()` بتعدّ اللي مالوش خطة `active`). خطة
        // موقوفة كانت هتوري بادج أخضر في اللوحة والصف نفسه محسوب
        // «مش في خطة» في الشجرة.
        $plans = $clients->isEmpty()
            ? collect()
            : JourneyPlan::with('user')
                ->whereIn('client_id', $clients->pluck('id'))
                ->where('active', true)
                ->orderBy('weekday')->orderBy('sort')
                ->get()
                ->groupBy('client_id');

        // ⚠️ `with('zones')` إجباري — `Scope::inZone` بتقرا مناطق
        // المندوب، ومن غير التحميل المسبق دي كويري لكل مندوب لكل عميل.
        $reps = User::fieldVisibleTo(Branch::scope(User::with('zones')), $viewer)
            ->whereIn('role', User::FIELD_WORK_ROLES)
            ->where('active', true)
            ->orderBy('name')
            ->get()
            ->filter(fn (User $r) => Scope::canRep($viewer, $r))
            ->values();

        $rows = $clients->map(function (Client $c) use ($reps, $plans, $viewer) {
            // المسموح لهم فعلاً على العميل ده — نفس الفحص اللي
            // `geoPlan` هيعمله في السيرفر، عشان القايمة ماتوريش
            // اختيار بيترفض بعدين
            $allowed = $reps
                ->filter(fn (User $r) => Scope::sameTeam($r, $c) && Scope::inZone($r, $c))
                ->map(fn (User $r) => ['id' => $r->id, 'name' => $r->displayName()])
                ->values()->all();

            $all = $plans->get($c->id, collect());

            // ⚠️ نفس فلتر `geoPlan` بالحرف — لو اللوحة رجّعت خطة
            // الفاعل مش مسؤول عنها كخطة عادية، «شيل» يرمي 403
            // والحفظ يضيف محطة تانية لنفس المحل بدل ما يحرّك القديمة
            $mine = $all->filter(fn (JourneyPlan $p) => Scope::canRep($viewer, $p->user))->values();
            $foreign = $all->reject(fn (JourneyPlan $p) => Scope::canRep($viewer, $p->user))->values();

            return [
                'id' => $c->id,
                'name' => $c->fullName(),
                'code' => (string) $c->code,
                'category' => $c->categoryLabel(),
                'category_class' => $c->categoryClass(),
                'rep_id' => $c->rep_id ? (int) $c->rep_id : null,
                'rep' => $c->rep?->displayName(),
                'has_location' => $c->lat !== null,
                'reps' => $allowed,
                'plans' => $mine->map(fn (JourneyPlan $p) => [
                    'id' => $p->id,
                    'user_id' => (int) $p->user_id,
                    'user' => $p->user?->displayName() ?? '—',
                    'weekday' => (int) $p->weekday,
                    'every_weeks' => (int) $p->every_weeks,
                    // مرساة التاريخ والوقت — بتملى خانتي الصف عند الفتح
                    // (`Y-m-d` للتاريخ و`H:i` لخانة الوقت، والعرض `h:i A`)
                    'starts_on' => $p->starts_on?->format('Y-m-d'),
                    'visit_at' => $p->visitTimeValue(),
                    'visit_label' => $p->visitTimeLabel(),
                    'label' => __('journey.geo_planned_as', [
                        'rep' => $p->user?->displayName() ?? '—',
                        'day' => $p->weekdayLabel(),
                        'freq' => $p->frequencyLabel(),
                    ]),
                ])->values()->all(),
                'foreign' => $foreign->map(fn (JourneyPlan $p) => __('journey.geo_planned_as', [
                    'rep' => $p->user?->displayName() ?? '—',
                    'day' => $p->weekdayLabel(),
                    'freq' => $p->frequencyLabel(),
                ]))->values()->all(),
            ];
        })->values()->all();

        return response()->json([
            'zone' => [
                'id' => $zone->id,
                'name' => $zone->displayName(),
                'code' => (string) $zone->code,
                'governorate' => $zone->governorateLabel(),
                'gov_clients' => $govClients,
                'gov_zones' => $govZones,
                'zone_clients' => $clients->count(),
                // كام محل في المنطقة لسه بلا مسؤول أساسي — منها بيبان
                // زرار «وزّع المنطقة دي» جوه اللوحة
                'zone_no_rep' => (int) $clients->whereNull('rep_id')->count(),
            ],
            'clients' => $rows,
        ]);
    }

    /**
     * حفظ خط السير للمحلات المحددة — دفعة واحدة في ترانزاكشن واحدة.
     *
     * ⚠️ **نفس حراس `store()` بالحرف**: `assertRep` + `assertClient` +
     * `assertSameTeam` + `assertInZone` (وفيها تخطي بول الفريق بتاع
     * ١١/٨). الحارس **قبل** الترانزاكشن — صف واحد مرفوض بيوقّف الدفعة
     * كلها، مايبقاش نص خط سير محفوظ ونصه لأ.
     *
     * ═══ منع التكرار — تلات حالات بالترتيب ═══
     * 1. نفس (مندوب × عميل × يوم) موجودة → **تحديث التردد** وتفعيلها.
     * 2. للعميل خطة **واحدة** بس (لأي مندوب الفاعل مسؤول عنه) →
     *    **بتتحرّك**: المندوب واليوم والتردد بيتغيّروا في نفس الصف.
     *    ده اللي يمنع نفس المحل يتحط لمندوبين والاتنين يروحوه.
     * 3. للعميل أكتر من خطة (زيارتين في الأسبوع بقرار) → **بنضيف**
     *    صف جديد ومابنلمسش القديم. تحويل الحالة دي لتحديث كان هيقلّم
     *    خطة أسبوعين لواحدة في صمت.
     *
     * ⚠️ و`firstOrCreate` في حالة الإضافة زي `store()` — الـUNIQUE
     * `(user_id, client_id, weekday)` بيرمي استثناء يوقّف الباقي.
     */
    public function geoPlan(Request $request)
    {
        $data = $request->validate([
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.client_id' => ['required', 'integer', 'exists:clients,id'],
            'rows.*.user_id' => ['required', 'integer', 'exists:users,id'],
            'rows.*.weekday' => ['required', 'integer', 'between:0,6'],
            'rows.*.every_weeks' => ['required', 'integer', 'in:1,2,4'],
            // ═══ تاريخ أول زيارة ووقتها (١٣ أغسطس ٢٠٢٦) ═══
            // الاتنين اختياريين — الحفظ من غيرهم بيفضل شغّال زي ما كان.
            'rows.*.starts_on' => ['nullable', 'date_format:Y-m-d'],
            'rows.*.visit_at' => ['nullable', 'date_format:H:i'],
        ]);

        $viewer = $request->user();

        // ⚠️ **اليوم بيتشتق من التاريخ في السيرفر** لما التاريخ يتبعت.
        // الشاشة بتعمل نفس الاشتقاق عشان المستخدم يشوفه، بس الاعتماد
        // عليها معناه إن بوست معدّل يحفظ خطة يوم اتنين بتاريخ بداية
        // يوم خميس — نمط مايستحقش في تاريخه المعروض أبداً.
        $rows = collect($data['rows'])->map(function (array $row) {
            $start = $row['starts_on'] ?? null;

            if ($start !== null && $start !== '') {
                $row['weekday'] = (int) Carbon::createFromFormat('Y-m-d', $start)
                    ->startOfDay()->dayOfWeek;
            } else {
                $row['starts_on'] = null;
            }

            if (($row['visit_at'] ?? '') === '') {
                $row['visit_at'] = null;
            }

            return $row;
        });

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

        // خطط العملاء دول كلها مرة واحدة — مش كويري لكل صف
        $existing = JourneyPlan::with('user')
            ->whereIn('client_id', $rows->pluck('client_id')->unique()->all())
            ->get()
            ->filter(fn (JourneyPlan $p) => Scope::canRep($viewer, $p->user))
            ->groupBy('client_id');

        // بذرة الترتيب لكل (مندوب × يوم) — بتزيد في الذاكرة بعد كده
        $seed = JourneyPlan::whereIn('user_id', $reps->keys()->all())
            ->selectRaw('user_id, weekday, MAX(sort) as mx')
            ->groupBy('user_id', 'weekday')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->user_id.'|'.$r->weekday => (int) $r->mx])
            ->all();

        $added = 0;
        $moved = 0;

        // فحص واحد للريكوست كله — مش لكل صف
        $hasSchedule = \Illuminate\Support\Facades\Schema::hasColumn('journey_plans', 'starts_on');

        DB::transaction(function () use ($rows, $existing, $hasSchedule, &$seed, &$added, &$moved) {
            foreach ($rows as $row) {
                $clientId = (int) $row['client_id'];
                $userId = (int) $row['user_id'];
                $weekday = (int) $row['weekday'];
                $freq = (int) $row['every_weeks'];

                // ⚠️ المرساة بتتكتب في **كل** المسارات التلاتة بنفس
                // الشكل — لو مسار واحد نساها، تعديل خطة موجودة كان
                // هيسيب تاريخ بداية قديم جنب يوم جديد.
                //
                // ⚠️ **والحارس مش رفاهية**: السيرفر اللايف بيترفع
                // بالإيد، فممكن الكود يوصل قبل المايجريشن. من غير
                // الفحص ده، حفظ خط السير — اللي كان شغّال — كان
                // هيرمي «عمود مش موجود» على كل دفعة.
                $schedule = $hasSchedule ? [
                    'starts_on' => $row['starts_on'] ?? null,
                    'visit_at' => $row['visit_at'] ?? null,
                ] : [];

                $mine = $existing->get($clientId, collect());

                // ١. نفس المندوب ونفس اليوم — تحديث التردد وبس
                $same = $mine->first(fn (JourneyPlan $p) => (int) $p->user_id === $userId
                    && (int) $p->weekday === $weekday);

                if ($same !== null) {
                    $same->update($schedule + ['every_weeks' => $freq, 'active' => true]);

                    continue;
                }

                $key = $userId.'|'.$weekday;
                $seed[$key] = ($seed[$key] ?? 0) + 1;

                // ٢. خطة واحدة بس للعميل — بتتحرّك مكانها
                if ($mine->count() === 1) {
                    $mine->first()->update($schedule + [
                        'user_id' => $userId,
                        'weekday' => $weekday,
                        'every_weeks' => $freq,
                        'sort' => $seed[$key],
                        'active' => true,
                    ]);
                    $moved++;

                    continue;
                }

                // ٣. مفيش خطة، أو أكتر من واحدة (متعدد الأيام بقرار)
                $plan = JourneyPlan::firstOrCreate(
                    ['user_id' => $userId, 'client_id' => $clientId, 'weekday' => $weekday],
                    $schedule + ['every_weeks' => $freq, 'sort' => $seed[$key], 'active' => true],
                );

                if ($plan->wasRecentlyCreated) {
                    $added++;
                } else {
                    $plan->update($schedule + ['every_weeks' => $freq, 'active' => true]);
                }
            }
        });

        return response()->json([
            'ok' => true,
            'message' => __('journey.geo_saved', ['added' => $added, 'moved' => $moved]),
        ]);
    }

    /** شيل محطات من خط السير — نفس حارس `destroy()` صف صف */
    public function geoUnplan(Request $request)
    {
        $data = $request->validate([
            'plan_ids' => ['required', 'array', 'min:1'],
            'plan_ids.*' => ['integer', 'exists:journey_plans,id'],
        ]);

        $plans = JourneyPlan::with('user')->whereIn('id', $data['plan_ids'])->get();

        foreach ($plans as $plan) {
            Scope::assertRep($request->user(), $plan->user);
        }

        $removed = 0;

        DB::transaction(function () use ($plans, &$removed) {
            $removed = (int) JourneyPlan::whereIn('id', $plans->pluck('id')->all())->delete();
        });

        return response()->json([
            'ok' => true,
            'message' => __('journey.geo_removed', ['count' => $removed]),
        ]);
    }

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

        $rows = $reps->map(function (User $rep) use ($eventsByUser, $lastEvents, $salesToday, $poToday, $visitsToday, $openVisits, $attDays, $feedByUser) {
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

            return [
                'rep' => $rep,
                'custody' => $custody,
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
