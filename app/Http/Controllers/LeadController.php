<?php

namespace App\Http\Controllers;

use App\Exceptions\Rejected;
use App\Models\Channel;
use App\Models\Lead;
use App\Models\User;
use App\Models\Zone;
use App\Services\Leads;
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
    public function index(Request $request)
    {
        $user = $request->user();

        // ⚠️ الليدز مربوطة بالزون، والزون مربوط بالفرع — بنسكّبها
        // بزون الفرع عشان مدير فرع مايشوفش خط أنابيب فرع تاني.
        //
        // ⚠️⚠️ **مش `Branch::scope($q, $user, 'zone_id')`** (إصلاح
        // 2026-08-13). `Branch::scope` بيعمل `where(<العمود>, branch_id)`
        // — يعني كان بيقارن `leads.zone_id` برقم **الفرع**. جدول
        // `leads` أصلاً مالوش `branch_id` (مش في `Branch::SCOPED`)،
        // فالنتيجة كانت تسريب وضياع في الاتجاهين معاً: المدير بيشوف
        // ليدز فرع تاني لو رقم الزون صادف رقم فرعه، وباقي ليداته
        // بتختفي خالص. السكوب لازم يعدّي **على الزون**.
        $q = Lead::with(['zone', 'channel', 'assignee', 'client']);

        if (! $user->seesAllBranches() && $user->branch_id !== null) {
            $branchId = $user->branch_id;

            $q->where(function ($w) use ($branchId) {
                // الزونز المركزية (`branch_id` فاضي) بتفضل مشتركة —
                // نفس قاعدة `Branch::scope` بالظبط
                $w->whereNull('zone_id')
                    ->orWhereIn('zone_id', Zone::query()
                        ->where(fn ($z) => $z->where('branch_id', $branchId)->orWhereNull('branch_id'))
                        ->select('id'));
            });
        }

        // ⚠️ المندوب بيشوف ليداته هو بس. من غير الفلتر ده كل مندوب
        // بيشوف قايمة الشركة كلها ويقدر ياخد ليد حد تاني.
        if ($user->isFieldUser()) {
            $q->where('assigned_to', $user->id);
        }

        $q->when($request->filled('status'), fn ($x) => $x->where('status', $request->input('status')))
            ->when($request->filled('zone'), fn ($x) => $x->where('zone_id', $request->input('zone')))
            ->when($request->filled('rep'), fn ($x) => $x->where('assigned_to', $request->input('rep')))
            // ⚠️ فلتر المصدر لازم يتحقق من القايمة مش يعدّي خام —
            // `?source=<script>` كان بيرجع صفر نتايج ويتطبع في اللينك
            ->when(
                in_array($request->input('source'), Lead::SOURCES, true),
                fn ($x) => $x->where('source', $request->input('source')),
            )
            ->when($request->filled('search'), function ($x) use ($request) {
                $s = '%'.$request->input('search').'%';
                $x->where(function ($w) use ($s) {
                    $w->where('name', 'like', $s)
                        ->orWhere('name_en', 'like', $s)
                        ->orWhere('phone', 'like', $s)
                        ->orWhere('number', 'like', $s);
                });
            });

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

        return view('erp.leads', [
            'leads' => $q->paginate(30)->withQueryString(),
            // ⚠️ **كل** الزونز والمناديب، مش النشطين بس. لو زون
            // اتوقّف، الليد المرتبط بيه كان بيلاقي الاختيار مش موجود
            // في القايمة فبيرجع فاضي، وأول حفظ بيمسح التخصيص في صمت.
            'zones' => Zone::orderBy('code')->get(),
            'channels' => Channel::orderBy('name')->get(),
            'reps' => User::whereIn('role', User::FIELD_ROLES)->orderBy('name')->get(),
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
            'filters' => $request->only(['status', 'zone', 'rep', 'search', 'source', 'sort']),
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
