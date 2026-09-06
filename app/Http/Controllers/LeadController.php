<?php

namespace App\Http\Controllers;

use App\Exceptions\Rejected;
use App\Models\AppNotification;
use App\Models\Channel;
use App\Models\Client;
use App\Models\Lead;
use App\Models\User;
use App\Models\Zone;
use App\Services\Leads;
use App\Support\Scope;
use Illuminate\Http\Request;

/**
 * العملاء المحتملين.
 *
 * ⚠️ التحويل لعميل بينشئ كيان تجاري حقيقي، فهو **أدمن ومدير بس**.
 * المندوب بيسجّل ويتابع ويحدّث الحالة، بس مايفتحش أكاونت لوحده —
 * ده نفس منطق طلبات العملاء الجدد الموجود أصلاً.
 */
class LeadController extends Controller
{
    /**
     * ═══ الكويري المفلترة المشتركة (٢٦/٨ → اتفصلت ١/٩) ═══
     *
     * السكوب + كل فلاتر الشاشة في مكان واحد — بيستخدمها `index`
     * (العرض) و`bulkSet` في وضع «كل نتايج الفلتر» (التسكين الجماعي
     * عبر الصفحات). **أي فلتر جديد يتضاف هنا** وإلا التحديد الشامل
     * هيسكّن ناس الفلتر مش شايفهم.
     *
     * ⚠️ الليدز مربوطة بالزون، والزون مربوط بالفرع — بنسكّنها
     * بزون الفرع عشان مدير فرع مايشوفش خط أنابيب فرع تاني.
     *
     * ⚠️⚠️ **مش `Branch::scope($q, $user, 'zone_id')`** (إصلاح
     * 2026-08-13). `Branch::scope` بيعمل `where(<العمود>, branch_id)`
     * — يعني كان بيقارن `leads.zone_id` برقم **الفرع**. جدول
     * `leads` أصلاً مالوش `branch_id` (مش في `Branch::SCOPED`)،
     * فالنتيجة كانت تسريب وضياع في الاتجاهين معاً. السكوب لازم
     * يعدّي **على الزون**.
     *
     * ⚠️⚠️ **كل الأعمدة متأهلة بـ`leads.`** (إصلاح ٢٦/٨): كويري
     * «متسكّن مع» بتعمل clone من هنا + join على `users`، و`users`
     * فيها نفس الأسماء (zone_id/name/phone/status...) — عمود غير
     * متأهل بيرمي 1052 «ambiguous» أول ما الفلتر يشتغل مع الـjoin.
     */
    private function filteredQuery(Request $request, User $user)
    {
        $q = Lead::query();

        if (! $user->seesAllBranches() && $user->branch_id !== null) {
            $branchId = $user->branch_id;

            $q->where(function ($w) use ($branchId) {
                // الزونز المركزية (`branch_id` فاضي) بتفضل مشتركة —
                // نفس قاعدة `Branch::scope` بالظبط
                $w->whereNull('leads.zone_id')
                    ->orWhereIn('leads.zone_id', Zone::query()
                        ->where(fn ($z) => $z->where('branch_id', $branchId)->orWhereNull('branch_id'))
                        ->select('id'));
            });
        }

        // ⚠️ المندوب بيشوف ليداته هو بس. من غير الفلتر ده كل مندوب
        // بيشوف قايمة الشركة كلها ويقدر ياخد ليد حد تاني.
        if ($user->isFieldUser()) {
            $q->where('leads.assigned_to', $user->id);
        }

        $q->when($request->filled('status'), fn ($x) => $x->where('leads.status', $request->input('status')))
            ->when($request->filled('zone'), fn ($x) => $x->where('leads.zone_id', $request->input('zone')))
            ->when($request->filled('rep'), fn ($x) => $x->where('leads.assigned_to', $request->input('rep')))
            // فلتر محفظة المدير (٦/٩) — «توزيعات المدير الفلاني»
            ->when($request->filled('mgr'), fn ($x) => $x->where('leads.manager_id', $request->input('mgr')))
            // ⚠️ فلتر المصدر لازم يتحقق من القايمة مش يعدّي خام —
            // `?source=<script>` كان بيرجع صفر نتايج ويتطبع في اللينك
            ->when(
                in_array($request->input('source'), Lead::SOURCES, true),
                fn ($x) => $x->where('leads.source', $request->input('source')),
            )
            // فلتر القسم/النشاط (٢٦/٨) — «كل الجيمات في الدقي»
            ->when($request->filled('cat'),
                fn ($x) => $x->where('leads.category_raw', $request->input('cat')))
            // العملاء المحتملين بلا مندوب — للتوزيع من الصفر
            ->when($request->boolean('unassigned'),
                fn ($x) => $x->whereNull('leads.assigned_to')->whereIn('leads.status', Lead::OPEN_STATUSES))
            // فلتر الشبيهات المستنية قرار (٢٦/٨) — المفتوحة بس:
            // اللي اتقرر فيها «صح» اتقفلت وخلاص، مالهاش مكان هنا
            ->when($request->boolean('dup'),
                fn ($x) => $x->whereNotNull('leads.dup_client_id')->where('leads.dup_dismissed', false)
                    ->whereIn('leads.status', Lead::OPEN_STATUSES))
            ->when($request->filled('search'), function ($x) use ($request) {
                $s = '%'.$request->input('search').'%';
                $x->where(function ($w) use ($s) {
                    $w->where('leads.name', 'like', $s)
                        ->orWhere('leads.name_en', 'like', $s)
                        ->orWhere('leads.phone', 'like', $s)
                        ->orWhere('leads.number', 'like', $s);
                });
            });

        return $q;
    }

    public function index(Request $request)
    {
        $user = $request->user();

        $q = $this->filteredQuery($request, $user)
            ->with(['zone', 'channel', 'assignee', 'manager', 'client', 'dupClient']);

        // ⚠️ تجميع في الداتابيز مش تحميل الجدول كله. بعد استيراد
        // شيت فيه آلاف الليدز، `->get()` كان بيجيبهم كلهم بعلاقاتهم
        // عشان يحسب 5 أرقام.
        $counts = (clone $q)
            ->selectRaw('status, COUNT(*) as n, COALESCE(SUM(expected_monthly), 0) as v')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $open = collect(Lead::OPEN_STATUSES);

        // ⚠️⚠️ **العدّادات كلها قبل الترتيب.** `count()` في لارافيل
        // مابيشيلش الـ`ORDER BY`، و`SELECT COUNT(*) ... ORDER BY score`
        // من غير `GROUP BY` بترمي خطأ على MySQL بـ`ONLY_FULL_GROUP_BY`
        // (الوضع الافتراضي على السيرفر اللايف). الكلوجر اللي تحت كان
        // بيتنفّذ **بعد** ما الترتيب اتحط على `$q`.
        $overdue = (clone $q)
            ->whereIn('status', Lead::OPEN_STATUSES)
            ->whereNotNull('next_action_on')
            ->whereDate('next_action_on', '<', today())
            ->count();

        // فرص قوية — نفس الثابت اللي `LeadScore::badgeClass()` بيخضّر عنده
        $strong = (clone $q)->whereIn('status', Lead::OPEN_STATUSES)
            ->where('score', '>=', \App\Support\LeadScore::STRONG)
            ->count();

        // ⚠️ **الترتيب الافتراضي بالقوة مش بالتاريخ** (2026-08-13).
        // بعد استيراد آلاف الليدز من دليل خارجي، `latest()` بيحط أضعف
        // الأماكن فوق لمجرد إنها كانت آخر صف في الملف — والمندوب
        // بيمشي من فوق. الترتيب هو المنتج هنا، مش القايمة.
        // `id` تاني عشان الترتيب يفضل ثابت بين الصفحات لما السكور يتساوى.
        $sort = $request->input('sort') === 'recent' ? 'recent' : 'score';

        $q->when($sort === 'score', fn ($x) => $x->orderByDesc('score')->orderByDesc('id'))
            ->when($sort === 'recent', fn ($x) => $x->orderByDesc('id'));

        // ═══ المحفظة (بايبلاين ٢٦/٨) — الخريطة وتوزيعة المناطق ═══
        //
        // نقط الخريطة من **نفس الكويري المفلترة** — الفلتر بيسمع في
        // الخريطة والجدول مع بعض. سقف 1500 نقطة عشان الصفحة ماتتخنقش.
        //
        // ⚠️⚠️ **`reorder()` على كل نسخة مجمّعة.** البلوك ده جاي **بعد**
        // ما الترتيب اتحط على `$q`، وتجميع بـORDER BY score من غير
        // GROUP BY = خطأ 1140 على اللايف (ONLY_FULL_GROUP_BY) — نفس
        // الفخ الموثق فوق في العدّادات، ووقعنا فيه تاني فعلاً (٢٦/٨).
        $mapLeads = (clone $q)->reorder()->whereNotNull('lat')->whereNotNull('lng')
            ->orderByDesc('score')->limit(1500)
            ->get(['id', 'name', 'lat', 'lng', 'status', 'zone_id', 'assigned_to', 'score'])
            ->map(fn ($l) => [
                'id' => $l->id,
                'name' => $l->name,
                'lat' => (float) $l->lat,
                'lng' => (float) $l->lng,
                'st' => $l->status,
                'zone' => $l->zone_id,
                'rep' => $l->assigned_to !== null,
                'score' => (float) $l->score,
            ])->values();

        // متوزع على مناديب ولا لأ — أرقام المحفظة الكبيرة
        $dist = (clone $q)->reorder()->selectRaw('
                COUNT(*) as total,
                COALESCE(SUM(assigned_to IS NOT NULL), 0) as assigned
            ')->first();

        // توزيعة المناطق: كل زون فيه كام، مفتوح كام، غير متوزع كام،
        // كسبنا كام — دي شاشة «خد ٥ من المنطقة دي»
        $zoneRows = (clone $q)->reorder()->selectRaw("
                zone_id,
                COUNT(*) as total,
                COALESCE(SUM(assigned_to IS NULL AND status IN ('new','contacted','visited','negotiating')), 0) as unassigned,
                COALESCE(SUM(status IN ('new','contacted','visited','negotiating')), 0) as open_n,
                COALESCE(SUM(status = 'won'), 0) as won_n
            ")
            ->groupBy('zone_id')
            ->orderByDesc('total')
            ->get();

        // ═══ الأقسام (٢٦/٨) — عدّ كل نشاط جوه نفس الفلاتر: شيبس
        // «اتفرج بالأقسام» + دروب داون الفلتر ═══
        $cats = (clone $q)->reorder()
            ->whereNotNull('category_raw')
            ->selectRaw('category_raw, COUNT(*) as n')
            ->groupBy('category_raw')
            ->orderByDesc('n')
            ->get();

        // ═══ مين متسكّن في كل زون (٢٦/٨) — «اتسكن مع مين» ═══
        // كويري واحدة: زون × مندوب × عدد — بتتلم في ماب للفيو
        $zoneReps = (clone $q)->reorder()
            ->whereNotNull('assigned_to')
            ->join('users', 'users.id', '=', 'leads.assigned_to')
            ->selectRaw('leads.zone_id, users.name as rep_name, COUNT(*) as n')
            ->groupBy('leads.zone_id', 'users.name')
            ->get()
            ->groupBy('zone_id');

        // الشبيهات المستنية قرار — عدّاد زرار الفحص (من غير فلاتر
        // الشاشة عن قصد: الرقم ده «شغل مستني» مش «نتيجة الفلتر»)
        $dupPending = Lead::whereNotNull('dup_client_id')
            ->where('dup_dismissed', false)
            ->whereIn('status', Lead::OPEN_STATUSES)
            ->count();

        // ═══ حجم الصفحة (١/٩): ٣٠/٦٠/١٠٠ أو «الكل» ═══
        //
        // «الكل» بيعرض كل نتايج الفلتر في صفحة واحدة — عشان التعليم
        // والتسكين الجماعي من غير تنقّل. بسقف 2000 صف عشان الصفحة
        // ماتتخنقش (نفس روح سقف مركز التقارير) — لو الفلتر أوسع من
        // كده ضيّق الأول.
        $per = in_array((int) $request->input('per'), [30, 60, 100], true)
            ? (int) $request->input('per')
            : ($request->input('per') === 'all' ? 'all' : 30);

        $pageSize = $per === 'all'
            ? max(1, min((int) ($dist->total ?? 0), 2000))
            : $per;

        return view('erp.leads', [
            'leads' => $q->paginate($pageSize)->withQueryString(),
            'per' => $per,
            'mapLeads' => $mapLeads,
            'zoneRows' => $zoneRows,
            'zoneReps' => $zoneReps,
            'cats' => $cats,
            'dist' => $dist,
            'dupPending' => $dupPending,
            // ⚠️ **كل** الزونز والمناديب، مش النشطين بس. لو زون
            // اتوقّف، الليد المرتبط بيه كان بيلاقي الاختيار مش موجود
            // في القايمة فبيرجع فاضي، وأول حفظ بيمسح التخصيص في صمت.
            'zones' => Zone::orderBy('code')->get(),
            'channels' => Channel::orderBy('name')->get(),
            // ⚠️ المدير بيشوف مناديبه هو بس في التوزيع (٦/٩) — الأدمن الكل
            'reps' => User::fieldVisibleTo(User::whereIn('role', User::FIELD_ROLES))
                ->where('active', true)->orderBy('name')->get(),
            // مديري القنوات — للأدمن بس: بينزّل الليدات عليهم
            'managers' => $user->role === 'admin'
                ? User::where('role', 'manager')->where('active', true)->orderBy('name')->get()
                : collect(),
            'statuses' => Lead::STATUSES,
            'sources' => Lead::SOURCES,
            'stats' => [
                'open' => (int) $open->sum(fn ($s) => $counts[$s]->n ?? 0),
                'won' => (int) ($counts['won']->n ?? 0),
                'lost' => (int) ($counts['lost']->n ?? 0),
                'overdue' => $overdue,
                'strong' => $strong,
                'pipeline' => round($open->sum(fn ($s) => (float) ($counts[$s]->v ?? 0)), 2),
            ],
            'sort' => $sort,
            'filters' => $request->only(['status', 'zone', 'rep', 'mgr', 'search', 'source', 'sort', 'cat', 'unassigned', 'dup', 'per']),
            'canConvert' => $user->isManager(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->rules($request);

        Lead::create($data + [
            'number' => Lead::nextNumber(),
            'status' => 'new',
            'created_by' => $request->user()->id,
        ]);

        return back()->with('ok', __('lead.added'));
    }

    public function update(Request $request, Lead $lead)
    {
        $user = $request->user();

        // ⚠️ المندوب بيعدّل **ليداته هو بس**. من غير الفحص ده أي
        // مندوب يقدر يبعت PUT على ليد زميله ويحطه لنفسه — الفلترة
        // في `index` بتخبّي الليد عن عينه بس مش عن الراوت.
        if ($user->isFieldUser() && $lead->assigned_to !== $user->id) {
            abort(403);
        }

        // ⚠️ الليد المتحوّل مايتعدلش — بياناته اتنقلت لعميل فعلي
        // وأي تعديل هنا بيخلّي الاتنين مختلفين من غير ما حد يعرف.
        if ($lead->isConverted()) {
            return back()->withErrors(['status' => __('lead.converted_readonly')]);
        }

        $data = $this->rules($request);

        // ⚠️ المندوب مايغيّرش المسؤول عن الليد — ده قرار المدير
        if ($user->isFieldUser()) {
            unset($data['assigned_to']);
        }

        $data['status'] = $data['status'] ?? $lead->status;

        // ⚠️ `won` بتتحط من التحويل بس. حد بيختارها من القايمة من غير
        // تحويل بيخلّي الليد «مكسوب» ومفيش عميل — ورقم في التقرير
        // مالوش مقابل في الواقع.
        if ($data['status'] === 'won') {
            return back()->withErrors(['status' => __('lead.win_by_convert')]);
        }

        $data['lost_reason'] = $data['status'] === 'lost'
            ? $request->input('lost_reason')
            : null;

        $lead->update($data);

        return back()->with('ok', __('lead.updated'));
    }

    public function convert(Request $request, Lead $lead)
    {
        // ⚠️ من غير التحقق ده، id غلط بيرمي QueryException على مفتاح
        // أجنبي — و `catch (Rejected)` مابيلقفهاش فبتطلع 500.
        $overrides = $request->validate([
            'zone_id' => ['nullable', 'exists:zones,id'],
            'channel_id' => ['nullable', 'exists:channels,id'],
        ]);

        try {
            $client = Leads::convert($lead, $request->user(), array_filter($overrides));
        } catch (Rejected $e) {
            return back()->withErrors(['convert' => $e->getMessage()]);
        } catch (\Illuminate\Database\QueryException $e) {
            // ⚠️ تصادم في كود العميل (تحويلين في نفس اللحظة). الترانزاكشن
            // رجعت فمفيش داتا ناقصة — بس اليوزر لازم يشوف رسالة
            // مفهومة بدل صفحة 500.
            return back()->withErrors(['convert' => __('lead.code_clash')]);
        }

        return redirect()->route('erp.clients.show', $client)
            ->with('ok', __('lead.converted', ['code' => $client->code]));
    }

    /** @return array<string, mixed> */
    /**
     * توزيع جماعي (بايبلاين ٢٦/٨): «خد N من المنطقة دي» — بياخد
     * أعلى N ليد **مفتوح وغير متوزع** بالسكور في الزون ويحطهم على
     * المندوب. أدمن ومدير بس (الراوت)، والزون بيتفعّل لو كان موقوف
     * ويتعلّم للمندوب — نفس روح Coverage: التوزيع بيجرّ الظهور وراه.
     */
    public function bulkAssign(Request $request)
    {
        $data = $request->validate([
            'zone_id' => ['required', 'integer', 'exists:zones,id'],
            // ⭐ نفس توزيع bulkSet (٦/٩): `r-ID` مندوب أو `m-ID` مدير (أدمن بس)
            'target' => ['required', 'string', 'regex:/^[rm]-\d+$/'],
            'count' => ['required', 'integer', 'min:1', 'max:200'],
        ]);

        [$kind, $targetId] = explode('-', $data['target'], 2);

        $ids = Lead::where('zone_id', $data['zone_id'])
            ->whereNull('assigned_to')
            ->whereIn('status', Lead::OPEN_STATUSES)
            ->orderByDesc('score')->orderBy('id')
            ->limit((int) $data['count'])
            ->pluck('id');

        if ($ids->isEmpty()) {
            return back()->withErrors(['zone_id' => __('lead.bulk_none')]);
        }

        if ($kind === 'm') {
            abort_unless($request->user()->role === 'admin', 403);
            $mgr = User::where('role', 'manager')->where('active', true)
                ->findOrFail((int) $targetId);

            Lead::whereIn('id', $ids)->update(['manager_id' => $mgr->id, 'assigned_to' => null]);

            AppNotification::send($mgr,
                fn () => '🎯 '.__('lead.n_mgr_title'),
                fn () => __('lead.n_mgr_body', ['n' => $ids->count()]));

            return back()->with('ok', __('lead.bulk_mgr_done', [
                'n' => $ids->count(), 'mgr' => $mgr->displayName(),
            ]));
        }

        $rep = User::whereIn('role', User::FIELD_ROLES)->where('active', true)
            ->findOrFail((int) $targetId);
        // ⚠️ حارس الفريق — المدير لمناديبه بس (الأدمن بيعدّي)
        Scope::assertRep($request->user(), $rep);

        Lead::whereIn('id', $ids)->update(['assigned_to' => $rep->id, 'manager_id' => $rep->manager_id]);

        // الزون لازم يبان للمندوب — تفعيل + تعليم (إضافة بس، زي Coverage)
        $zone = Zone::find($data['zone_id']);

        if ($zone !== null) {
            if (! $zone->active) {
                $zone->update(['active' => true]);
            }

            $rep->zones()->syncWithoutDetaching([$zone->id]);
        }

        // إشعار للمندوب نفسه بس — عقيدة النوتفيكيشن
        \App\Models\AppNotification::send($rep,
            fn () => '🎯 '.__('lead.n_bulk_title'),
            fn () => __('lead.n_bulk_body', ['n' => $ids->count(), 'zone' => $zone?->displayName() ?? '']));

        return back()->with('ok', __('lead.bulk_done', [
            'n' => $ids->count(), 'rep' => $rep->displayName(),
        ]));
    }

    /**
     * ═══ جدولة الأسبوع (سكشن المحتملين ٢٦/٨) ═══
     *
     * «بكره روح ده وده وبعده ده» — بورد ٧ أيام (السبت للجمعة) لمندوب
     * واحد: ليداته الغير مجدولة على الجنب، بتتوزع على الأيام بالضغط،
     * وحفظة واحدة بتزامن الأسبوع كله (مسح وإدخال — نفس روح sync).
     */
    public function planner(Request $request)
    {
        $reps = User::whereIn('role', User::FIELD_ROLES)
            ->where('active', true)->orderBy('name')->get(['id', 'name', 'code']);

        $repId = (int) $request->query('rep', 0) ?: ($reps->first()?->id ?? 0);
        $rep = $reps->firstWhere('id', $repId);

        // بداية الأسبوع = السبت (زي خطط السير — weekday 6 في كاربون)
        $week = $request->query('week');
        try {
            $start = $week ? \Illuminate\Support\Carbon::parse($week) : today();
        } catch (\Throwable) {
            $start = today();
        }
        $start = $start->copy()->startOfWeek(\Carbon\CarbonInterface::SATURDAY);

        $days = collect(range(0, 6))->map(fn ($i) => $start->copy()->addDays($i));

        // خطة الأسبوع المحفوظة
        $plans = $rep === null ? collect() : \App\Models\LeadPlan::with('lead.zone')
            ->where('user_id', $repId)
            ->whereBetween('plan_date', [$start->toDateString(), $days->last()->toDateString()])
            ->orderBy('plan_date')->orderBy('sort')
            ->get();

        $plannedIds = $plans->pluck('lead_id');

        // ليدات المندوب المفتوحة الغير مجدولة الأسبوع ده — بالسكور
        $pool = $rep === null ? collect() : Lead::with('zone')
            ->where('assigned_to', $repId)
            ->whereIn('status', Lead::OPEN_STATUSES)
            ->whereNotIn('id', $plannedIds)
            ->orderByDesc('score')
            // lat/lng/address لخريطة خط السير (٦/٩)
            ->get(['id', 'name', 'zone_id', 'score', 'category_raw', 'status', 'lat', 'lng', 'address']);

        return view('erp.leads_planner', [
            'reps' => $reps,
            'repId' => $repId,
            'start' => $start,
            'days' => $days,
            'plans' => $plans->groupBy(fn ($p) => $p->plan_date->toDateString()),
            'pool' => $pool,
        ]);
    }

    /**
     * حفظ الأسبوع — مزامنة كاملة: خطط المندوب في النطاق بتتمسح
     * وبتتكتب من جديد بالترتيب اللي جه من البورد. ⚠️ مسح خطط
     * **المندوب ده في الأسبوع ده بس** — مش أي حاجة تانية.
     */
    public function plannerSave(Request $request)
    {
        $data = $request->validate([
            'rep_id' => ['required', 'integer',
                \Illuminate\Validation\Rule::exists('users', 'id')
                    ->whereIn('role', User::FIELD_ROLES)->where('active', true)],
            'week' => ['required', 'date'],
            // {"2026-08-30": [12, 15], "2026-08-31": [7], ...}
            'days' => ['required', 'string'],
        ]);

        $start = \Illuminate\Support\Carbon::parse($data['week'])
            ->startOfWeek(\Carbon\CarbonInterface::SATURDAY);
        $end = $start->copy()->addDays(6);

        $days = json_decode($data['days'], true);

        if (! is_array($days)) {
            return back()->withErrors(['days' => __('lead.plan_bad')]);
        }

        // ليدات المندوب بس — أي id غريب في البايلود بيتفلتر في صمت
        $ownIds = Lead::where('assigned_to', (int) $data['rep_id'])
            ->whereIn('status', Lead::OPEN_STATUSES)
            ->pluck('id')->flip();

        $saved = 0;

        \Illuminate\Support\Facades\DB::transaction(function () use ($days, $data, $start, $end, $ownIds, &$saved) {
            \App\Models\LeadPlan::where('user_id', (int) $data['rep_id'])
                ->whereBetween('plan_date', [$start->toDateString(), $end->toDateString()])
                ->delete();

            foreach ($days as $date => $ids) {
                try {
                    $d = \Illuminate\Support\Carbon::parse((string) $date);
                } catch (\Throwable) {
                    continue;
                }

                if ($d->lt($start) || $d->gt($end) || ! is_array($ids)) {
                    continue;
                }

                foreach (array_values($ids) as $i => $leadId) {
                    if (! $ownIds->has((int) $leadId)) {
                        continue;
                    }

                    \App\Models\LeadPlan::create([
                        'user_id' => (int) $data['rep_id'],
                        'lead_id' => (int) $leadId,
                        'plan_date' => $d->toDateString(),
                        'sort' => $i,
                        'created_by' => auth()->id(),
                    ]);
                    $saved++;
                }
            }
        });

        // إشعار للمندوب نفسه — أسبوعه اتجدول
        $rep = User::find((int) $data['rep_id']);
        if ($rep !== null && $saved > 0) {
            \App\Models\AppNotification::send($rep,
                fn () => '📅 '.__('lead.n_plan_title'),
                fn () => __('lead.n_plan_body', ['n' => $saved]));
        }

        return redirect()->route('erp.leads.planner', [
            'rep' => $data['rep_id'], 'week' => $start->toDateString(),
        ])->with('ok', __('lead.plan_saved', ['n' => $saved]));
    }

    /**
     * ═══ متابعة الأسبوع (سكشن المحتملين ٢٦/٨) — عين المدير ═══
     *
     * لكل مندوب: اتجدوله كام ← راح فعلاً كام (تأكيد البيانات في نفس
     * اليوم = الإثبات) ← اتأكد كام إجمالاً ← فتح أكاونت كام.
     * والفايت من غير زيارة أحمر — «الحركة جمب بحركة».
     */
    public function week(Request $request)
    {
        $week = $request->query('week');
        try {
            $start = $week ? \Illuminate\Support\Carbon::parse($week) : today();
        } catch (\Throwable) {
            $start = today();
        }
        $start = $start->copy()->startOfWeek(\Carbon\CarbonInterface::SATURDAY);
        $days = collect(range(0, 6))->map(fn ($i) => $start->copy()->addDays($i));

        $plans = \App\Models\LeadPlan::with(['lead', 'user:id,name,code'])
            ->whereBetween('plan_date', [$start->toDateString(), $days->last()->toDateString()])
            ->orderBy('plan_date')->orderBy('sort')
            ->get()
            ->filter(fn ($p) => $p->lead !== null && $p->user !== null);

        // «راح فعلاً» = الليد اتأكد في نفس يوم خطته
        $visited = fn ($p) => $p->lead->confirmed_at !== null
            && $p->lead->confirmed_at->isSameDay($p->plan_date);

        $rows = $plans->groupBy('user_id')->map(function ($g) use ($visited) {
            $missed = $g->filter(fn ($p) => $p->plan_date->lt(today()) && ! $visited($p));

            return [
                'user' => $g->first()->user,
                'planned' => $g->count(),
                'visited' => $g->filter($visited)->count(),
                'missed' => $missed->count(),
                'won' => $g->filter(fn ($p) => $p->lead->status === 'won')->count(),
                'byDay' => $g->groupBy(fn ($p) => $p->plan_date->toDateString()),
            ];
        })->sortByDesc(fn ($r) => $r['missed'])->values();

        return view('erp.leads_week', [
            'start' => $start,
            'days' => $days,
            'rows' => $rows,
            'visitedFn' => $visited,
        ]);
    }

    /**
     * تسكين المحدد (٢٦/٨): تشيك بوكسات في الجدول + مندوب + Apply —
     * بيسكّن العملاء المحتملين المختارين على المندوب مرة واحدة.
     * المفتوح وغير المتحوّل بس، وزوناتهم بتتفعل وتتعلّم له (زي bulk).
     */
    public function bulkSet(Request $request)
    {
        // ═══ وضع «كل نتايج الفلتر» (١/٩) ═══
        //
        // تحديد الكل عبر الصفحات: الفورم بيبعت `all_filtered=1` + نفس
        // فلاتر الشاشة (hidden inputs)، والسيرفر بيعيد بناء الكويري
        // بـ`filteredQuery` بدل قايمة ids — عشان 593 ليد مايتبعتوش
        // كـ593 حقل ولا يتحدّدوا صفحة صفحة.
        $allMode = $request->boolean('all_filtered');

        $data = $request->validate([
            // في وضع الكل الـids مالهاش لازمة — الفلتر هو التحديد
            'ids' => [$allMode ? 'nullable' : 'required', 'array', 'max:2000'],
            'ids.*' => ['integer'],
            // ⭐ التوزيع بالرول (٦/٩ — طلب المالك): `r-ID` مندوب أو
            // `m-ID` مدير قناة. الأدمن بينزّل على المدير، والمدير
            // بينزّل على مناديبه هو بس.
            'target' => ['required', 'string', 'regex:/^[rm]-\d+$/'],
        ]);

        [$kind, $targetId] = explode('-', $data['target'], 2);

        $mgr = null;
        $rep = null;

        if ($kind === 'm') {
            // التوزيع لمدير — أدمن بس
            abort_unless($request->user()->role === 'admin', 403);
            $mgr = User::where('role', 'manager')->where('active', true)
                ->findOrFail((int) $targetId);
        } else {
            $rep = User::whereIn('role', User::FIELD_ROLES)->where('active', true)
                ->findOrFail((int) $targetId);
            // ⚠️ حارس الفريق: المدير يوزّع لمناديبه هو بس (الأدمن بيعدّي)
            Scope::assertRep($request->user(), $rep);
        }

        // ⚠️ الأعمدة متأهلة بـ`leads.` — filteredQuery ممكن تبقى معمولة
        // للـjoin (نفس درس 1052 الموثق فوقها)
        $base = $allMode
            ? $this->filteredQuery($request, $request->user())
            : Lead::query()->whereIn('leads.id', $data['ids'] ?? []);

        $leads = $base
            ->whereIn('leads.status', Lead::OPEN_STATUSES)
            ->whereNull('leads.client_id')
            ->get(['leads.id', 'leads.zone_id']);

        if ($leads->isEmpty()) {
            return back()->withErrors(['ids' => __('lead.bulk_none')]);
        }

        // ═══ توزيع لمدير: الليد بيدخل محفظته وبيتشال من أي مندوب —
        // هو اللي هيوزعه على فريقه بعدين ═══
        if ($mgr !== null) {
            Lead::whereIn('id', $leads->pluck('id'))
                ->update(['manager_id' => $mgr->id, 'assigned_to' => null]);

            AppNotification::send($mgr,
                fn () => '🎯 '.__('lead.n_mgr_title'),
                fn () => __('lead.n_mgr_body', ['n' => $leads->count()]));

            return back()->with('ok', __('lead.bulk_mgr_done', [
                'n' => $leads->count(), 'mgr' => $mgr->displayName(),
            ]));
        }

        // ═══ توزيع لمندوب — والمدير بيتزامن بمدير المندوب نفسه ═══
        Lead::whereIn('id', $leads->pluck('id'))
            ->update(['assigned_to' => $rep->id, 'manager_id' => $rep->manager_id]);

        // زونات المختارين تتفعل وتتعلّم للمندوب — عشان تظهر له
        $zoneIds = $leads->pluck('zone_id')->filter()->unique();

        if ($zoneIds->isNotEmpty()) {
            Zone::whereIn('id', $zoneIds)->where('active', false)->update(['active' => true]);
            $rep->zones()->syncWithoutDetaching($zoneIds->all());
        }

        AppNotification::send($rep,
            fn () => '🎯 '.__('lead.n_bulk_title'),
            fn () => __('lead.n_bulk_body', ['n' => $leads->count(), 'zone' => '']));

        return back()->with('ok', __('lead.bulk_done', [
            'n' => $leads->count(), 'rep' => $rep->displayName(),
        ]));
    }

    /**
     * ═══ مسح عميل محتمل (٦/٩ — طلب المالك) ═══
     *
     * الأدمن بيمسح أي ليد، والمدير بيمسح اللي في محفظته بس (متوزع
     * له أو لواحد من مناديبه). الليد اللي اتحول لعميل فعلاً ممنوع
     * يتمسح — هو التاريخ الوحيد لمصدر العميل ده.
     */
    public function destroy(Request $request, Lead $lead)
    {
        $actor = $request->user();

        if ($lead->isConverted()) {
            return back()->withErrors(['lead' => __('lead.del_converted')]);
        }

        if ($actor->role !== 'admin') {
            $mine = (int) $lead->manager_id === (int) $actor->id
                || ((int) $lead->assignee?->manager_id === (int) $actor->id && $lead->assigned_to !== null);

            abort_unless($mine, 403, __('lead.del_not_yours'));
        }

        // خطط الأسبوع اللي بتشاور عليه — مفيش FK cascade على الجدول
        \App\Models\LeadPlan::where('lead_id', $lead->id)->delete();

        $name = $lead->displayName();
        $lead->delete();

        return back()->with('ok', __('lead.deleted', ['name' => $name]));
    }

    /**
     * تصفير التوزيعات (٢٦/٨ — «خلي الكل بدون مناديب وأبدأ أوزع»):
     * بيشيل المندوب من **المفتوحين بس** — المقفول (كسبناه/خسرناه)
     * تاريخه بيفضل زي ما هو. أدمن بس وبتأكيد.
     */
    public function clearAssignments()
    {
        $n = Lead::whereIn('status', Lead::OPEN_STATUSES)
            ->whereNotNull('assigned_to')
            ->update(['assigned_to' => null]);

        return back()->with('ok', __('lead.cleared_all', ['n' => number_format($n)]));
    }

    /**
     * ═══ فحص الشبيهات (٢٦/٨) — «هل المحتمل ده عميل عندي فعلاً؟» ═══
     *
     * بيمسح الليدز المفتوحة (اللي مش متشافة قبل كده) ضد العملاء
     * الحقيقيين بتلات مفاتيح بالترتيب: التليفون المطبّع (الأقوى) ←
     * الاسم المطبّع ← العنوان المطبّع. بيكتب **اقتراح** بس
     * (dup_client_id + السبب) — القرار للمالك يدوي واحد واحد:
     * ✓ نفس العميل = الليد بيتقفل ويختفي · ✗ غلط = بيفضل محتمل
     * ومايتسألش عنه تاني (dup_dismissed).
     */
    public function dupCheck()
    {
        // فهارس العملاء — مرة واحدة
        $byPhone = [];
        $byName = [];
        $byAddress = [];

        $addrKey = function (?string $a): string {
            $a = mb_strtolower(trim((string) $a));

            return $a === '' ? '' : preg_replace('/[\s\-\.،,\/]+/u', '', $a);
        };

        foreach (Client::query()->get(['id', 'name', 'phone', 'address']) as $c) {
            if (($pk = \App\Support\Dupes::phoneKey($c->phone)) !== '') {
                $byPhone[$pk] ??= $c->id;
            }

            if (($nk = \App\Support\Dupes::nameKey($c->name)) !== '') {
                $byName[$nk] ??= $c->id;
            }

            if (($ak = $addrKey($c->address)) !== '') {
                $byAddress[$ak] ??= $c->id;
            }
        }

        $found = 0;
        $scanned = 0;

        Lead::whereIn('status', Lead::OPEN_STATUSES)
            ->where('dup_dismissed', false)
            ->whereNull('dup_client_id')
            ->chunkById(500, function ($chunk) use ($byPhone, $byName, $byAddress, $addrKey, &$found, &$scanned) {
                foreach ($chunk as $l) {
                    $scanned++;

                    // الترتيب بالثقة: تليفون ← اسم ← عنوان
                    $hit = null;
                    $why = null;

                    if (($pk = \App\Support\Dupes::phoneKey($l->phone)) !== '' && isset($byPhone[$pk])) {
                        [$hit, $why] = [$byPhone[$pk], 'phone'];
                    } elseif (($nk = \App\Support\Dupes::nameKey($l->name)) !== '' && isset($byName[$nk])) {
                        [$hit, $why] = [$byName[$nk], 'name'];
                    } elseif (($ak = $addrKey($l->address)) !== '' && isset($byAddress[$ak])) {
                        [$hit, $why] = [$byAddress[$ak], 'address'];
                    }

                    if ($hit !== null) {
                        $l->update(['dup_client_id' => $hit, 'dup_reason' => $why]);
                        $found++;
                    }
                }
            });

        return back()->with('ok', __('lead.dup_scan_done', ['s' => $scanned, 'n' => $found]))
            // ودّيه على فلتر الشبيهات على طول — دي شغلته الجاية
            ->withInput();
    }

    /** قرار المالك على الشبيه: same = نفس العميل (يتقفل) · different = غلط */
    public function dupDecide(Request $request, Lead $lead)
    {
        $data = $request->validate(['verdict' => ['required', 'in:same,different']]);

        if ($lead->dup_client_id === null) {
            return back()->withErrors(['verdict' => __('lead.dup_gone')]);
        }

        if ($data['verdict'] === 'different') {
            $lead->update(['dup_client_id' => null, 'dup_reason' => null, 'dup_dismissed' => true]);

            return back()->with('ok', __('lead.dup_kept'));
        }

        // نفس العميل: الليد بيتقفل مربوط بالعميل الموجود — مش «كسبناه»
        // (مكسبناش حاجة جديدة) ومش تحويل (مفيش عميل اتولد). خسران
        // بسبب موثّق + client_id بيقفله من أي تعديل (converted_readonly).
        $client = $lead->dupClient;
        $lead->update([
            'status' => 'lost',
            'lost_reason' => __('lead.dup_lost_reason', ['code' => $client?->code ?? '']),
            'client_id' => $lead->dup_client_id,
        ]);

        return back()->with('ok', __('lead.dup_merged', ['code' => $client?->code ?? '']));
    }

    private function rules(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'name_en' => ['nullable', 'string', 'max:190'],
            'phone' => ['nullable', 'string', 'max:30'],
            'contact_name' => ['nullable', 'string', 'max:190'],
            'address' => ['nullable', 'string', 'max:190'],
            'zone_id' => ['nullable', 'exists:zones,id'],
            'channel_id' => ['nullable', 'exists:channels,id'],
            'assigned_to' => ['nullable', 'exists:users,id'],
            'source' => ['nullable', 'in:'.implode(',', Lead::SOURCES)],
            'expected_monthly' => ['nullable', 'numeric', 'min:0'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'next_action_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            // ⚠️ الحالة كانت بتتكتب من غير تحقق — أي نص كان بيوصل
            // للداتابيز، والشارة بتطلع بالمفتاح الخام والليد بيختفي
            // من كل العدادات.
            'status' => ['nullable', 'in:'.implode(',', Lead::STATUSES)],
            'lost_reason' => ['nullable', 'string', 'max:190'],
        ]);
    }
}
