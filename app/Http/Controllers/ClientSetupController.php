<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\PriceList;
use App\Support\Divisions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * ═══════════════════════════════════════════════════════════════
 * إعداد السلاسل والعملاء — صف واحد لكل كيان  ·  ١٧ أغسطس ٢٠٢٦
 * ═══════════════════════════════════════════════════════════════
 *
 * طلب المالك بالحرف: «اسم السلسلة .. الديفيجن .. النوع .. السعر
 * اللي بيتعامل بيه .. الخصم .. السعر شامل ولا لا — وأدوس Save وكل
 * العملاء اللي داخل السلسلة ياخدوا نفس البيانات دي بالظبط».
 * وصفحة تانية بنفس الترتيب للعملاء الفرادى.
 *
 * ⚠️ **حفظ لكل صف مش فورم واحد كبير.** ٧٠١ عميل في فورم واحد
 * معناه أي غلطة فاليديشن في صف بترجّع الصفحة كلها وتضيّع كل
 * اللي اتكتب. الصف بيتحفظ لوحده والباقي زي ما هو.
 *
 * ⚠️ **«شامل الضريبة» بيتترجم لعمود `taxable` بالعكس**: شامل =
 * السعر نهائي ومفيش ضريبة بتتضاف فوقه (`taxable=false`)؛ مش
 * شامل = الضريبة بتتضاف على الفاتورة (`taxable=true`).
 */
class ClientSetupController extends Controller
{
    /** صفحة السلاسل — صف لكل سلسلة */
    public function chains()
    {
        $rows = ClientGroup::withCount('clients')
            ->with('contract')
            ->where('active', true)->orderBy('name')->get();

        // ═══ «الساري دلوقتي» تحت كل خانة (طلب المالك ١٨/٨/٢٠٢٦) ═══
        //
        // «عاوز أشوف إيه المسمع دلوقتي في السلسلة علشان لما أعمل
        // Apply يبقى باين إيه المسمع في الأبلكيشن». الأرقام من نفس
        // المحركات اللي الأبلكيشن بيقرا منها — `effectiveDiscount`
        // و`Pricing::listRowFor` — مش من الأعمدة الخام، عشان اللابل
        // يقول الحقيقة اللي المندوب شايفها فعلاً.
        //
        // ⚠️ eager للعلاقات اللي المحركات بتنادبها — من غيرها الصفحة
        // بتضرب مئات الكويريز على العقود.
        $branches = Client::with(['contract', 'group.contract', 'priceListRow'])
            ->whereIn('group_id', $rows->pluck('id'))
            ->where('status', '!=', 'rejected')
            ->get()
            ->groupBy('group_id');

        $live = [];

        foreach ($rows as $g) {
            $live[$g->id] = $this->liveSummary($branches[$g->id] ?? collect());
        }

        return view('erp.client_setup', [
            'mode' => 'chains',
            'rows' => $rows,
            'live' => $live,
            'lists' => PriceList::where('active', true)->orderBy('id')->get(),
            // فلاتر «مين محتاج شغل» بتاعة صفحة العملاء بس
            'show' => null,
            'showCounts' => null,
        ]);
    }

    /** صفحة العملاء الفرادى — صف لكل عميل */
    public function clients(Request $request)
    {
        // ⚠️ `contract` و`group.contract` eager — لابلز «الساري» بتنادي
        // `effectiveDiscount` و`listRowFor` لكل صف.
        $q = Client::visibleTo(Client::query())
            ->with(['group.contract', 'zone', 'priceListRow', 'contract'])
            ->where('status', '!=', 'rejected');

        // ⚠️ فلتر «بدون قسم» — ده اللي المالك هيشتغل عليه أساساً
        if ($request->boolean('unassigned')) {
            $q->whereNull('division');
        }

        // ═══ فلتر «مين محتاج شغل» (طلب المالك ١٩/٨/٢٠٢٦) ═══
        //
        // «الصفحة ميكونش فيها غير اللي مش متراجع عليهم — واللي اتعمل
        // في السلسلة متطبق على كل فروعها». الافتراضي `pending`:
        // العميل اللي ماتراجعش بنفسه **ومش** فرع لسلسلة متراجعة —
        // لأن ختم السلسلة كتب على فروعها فعلاً وقت الحفظ، فمراجعتهم
        // فرد فرد شغل مكرر. الفلاتر التانية للرجوع والمراجعة.
        //
        // ⚠️ محروس بـhasColumn — لو الكود وصل قبل مايجريشن المراجعة
        // بنعرض الكل بدل ما نضرب.
        $show = $request->string('show')->value() ?: 'pending';
        $hasFlags = \Illuminate\Support\Facades\Schema::hasColumn('clients', 'setup_reviewed_at')
            && \Illuminate\Support\Facades\Schema::hasColumn('client_groups', 'reviewed_at');

        // عدادات الأزرار — من نفس النطاق قبل فلتر العرض
        $counts = null;

        if ($hasFlags) {
            $base = fn () => (clone $q);
            $pendingScope = fn ($w) => $w->whereNull('setup_reviewed_at')
                ->where(fn ($x) => $x->whereNull('group_id')
                    ->orWhereHas('group', fn ($g) => $g->whereNull('reviewed_at')));

            $counts = [
                'pending' => $pendingScope($base())->count(),
                'in_chain' => $base()->whereNotNull('group_id')->count(),
                'solo' => $base()->whereNull('group_id')->count(),
                'reviewed' => $base()->whereNotNull('setup_reviewed_at')->count(),
                'all' => $base()->count(),
            ];

            match ($show) {
                'in_chain' => $q->whereNotNull('group_id'),
                'solo' => $q->whereNull('group_id'),
                'reviewed' => $q->whereNotNull('setup_reviewed_at'),
                'all' => null,
                default => $pendingScope($q),
            };
        }

        $rows = $q->orderBy('name')->get();

        $live = [];

        foreach ($rows as $c) {
            $live[$c->id] = $this->liveSummary(collect([$c]));
        }

        return view('erp.client_setup', [
            'mode' => 'clients',
            'rows' => $rows,
            'live' => $live,
            'lists' => PriceList::where('active', true)->orderBy('id')->get(),
            'show' => $show,
            'showCounts' => $counts,
        ]);
    }

    /**
     * ملخص «الساري دلوقتي» لمجموعة عملاء (فروع سلسلة أو عميل واحد).
     *
     * القيم من محركات التسعير نفسها — القيمة الواحدة بتتعرض زي ما
     * هي، والمختلف بيتعرض «مختلط» عشان المالك يعرف إن السلسلة لسه
     * مش موحّدة ويراجعها.
     */
    private function liveSummary($set): array
    {
        if ($set->isEmpty()) {
            return ['division' => null, 'ff' => null, 'list' => null,
                'disc' => null, 'disc_src' => null, 'inclusive' => null, 'mixed' => []];
        }

        $one = fn ($vals) => $vals->unique()->count() === 1 ? $vals->first() : false;

        $divisions = $set->map(fn ($c) => $c->division);
        $ffs = $set->map(fn ($c) => $c->fulfillment());
        $lists = $set->map(fn ($c) => \App\Services\Pricing::listRowFor($c)?->displayName() ?? '—');
        $discs = $set->map(fn ($c) => round($c->effectiveDiscount() * 100, 2));
        $incs = $set->map(fn ($c) => ! $c->taxable);
        $srcs = $set->map(fn ($c) => $c->discountSourceKey());

        return [
            'division' => $one($divisions),
            'ff' => $one($ffs),
            'list' => $one($lists),
            'disc' => $one($discs),
            'disc_range' => [$discs->min(), $discs->max()],
            'disc_src' => $one($srcs),
            'inclusive' => $one($incs),
        ];
    }

    /**
     * حفظ صفوف السلاسل — الكل أو صف واحد.
     *
     * ⚠️ **فورم واحد كبير + زرار الصف بيبعت `only`** (طلب المالك
     * ١٧/٨: «عاوز Save All»). الفورمات المنفصلة القديمة ماينفعش
     * تتحفظ كلها بضغطة؛ والفورم الواحد من غير `only` كان بيضيّع
     * ميزة حفظ صف لوحده. الاتنين بقوا شغالين من نفس الفورم.
     */
    public function saveChains(Request $request)
    {
        $rows = $this->validatedRows($request);
        $only = $request->integer('only');
        $saved = 0;
        $branches = 0;

        DB::transaction(function () use ($rows, $only, $request, &$saved, &$branches) {
            foreach ($rows as $id => $data) {
                if ($only && (int) $id !== $only) {
                    continue;
                }

                $group = ClientGroup::find((int) $id);

                if ($group === null) {
                    continue;
                }

                $branches += Client::visibleTo($group->clients(), $request->user())
                    ->update($this->payload($data));

                // ═══ الختم على العقود كمان (قرار المالك ١٨/٨/٢٠٢٦) ═══
                //
                // «الشاشة دي تسمع في كل السيستم — والعقد ريفرنس».
                // المالك حط صفر هنا ولقى صفحة السلسلة والفروع لسه
                // شايفين خصم العقد: التحديث كان بيكتب على العميل بس
                // والعقد بيغلبه في `effectiveDiscount`. الحفظ بقى بيختم
                // **عقد السلسلة وعقود الفروع الشخصية السارية** بنفس
                // النسبة والقايمة — «كل الفروع تاخد نفس البيانات
                // بالظبط» تعني حتى اللي ليهم عقود خاصة.
                //
                // ⚠️ العقود المنتهية/الموقوفة مابتتلمسش — دي تاريخ.
                $stamp = $this->contractStamp($data);

                if ($stamp !== []) {
                    $ct = $group->contract;
                    if ($ct !== null && $ct->active && ! $ct->isExpired()) {
                        $ct->update($stamp);
                    }

                    $branchClients = Client::visibleTo($group->clients(), $request->user())
                        ->with('contract')->get();

                    foreach ($branchClients as $bc) {
                        $own = $bc->contract;
                        if ($own !== null && $own->active && ! $own->isExpired()) {
                            $own->update($stamp);
                        }
                    }
                }

                // علامة المراجعة — بتتكتب وبتتشال من نفس الخانة.
                // التاريخ بيتجدد مع كل حفظة والعلامة شغالة، عشان
                // «آخر مراجعة» تفضل صادقة.
                // ⚠️ محروسة بـhasColumn — الملفات بتترفع بإيد قبل
                // المايجريشن أحياناً، ومن غير الحارس أول حفظة بترمي
                // «Unknown column reviewed_at».
                if (\Illuminate\Support\Facades\Schema::hasColumn('client_groups', 'reviewed_at')) {
                    $group->reviewed_at = ! empty($data['reviewed']) ? now() : null;
                    $group->save();
                }

                $saved++;
            }
        });

        return back()->with('ok', __('client.setup_all_saved', [
            'count' => $saved, 'clients' => $branches,
        ]));
    }

    /** حفظ صفوف العملاء — الكل أو صف واحد */
    public function saveClients(Request $request)
    {
        $rows = $this->validatedRows($request);
        $only = $request->integer('only');
        $saved = 0;

        DB::transaction(function () use ($rows, $only, $request, &$saved) {
            foreach ($rows as $id => $data) {
                if ($only && (int) $id !== $only) {
                    continue;
                }

                $client = Client::visibleTo(Client::query(), $request->user())
                    ->find((int) $id);

                if ($client === null) {
                    continue;
                }

                $client->update($this->payload($data));

                // نفس ختم صفحة السلاسل — بس على عقد العميل **الشخصي**
                // بس. عقد السلسلة مابيتلمسش من صف عميل واحد: تعديله
                // كان هيغيّر إخواته كلهم في صمت.
                $stamp = $this->contractStamp($data);
                $own = $client->contract;

                if ($stamp !== [] && $own !== null && $own->active && ! $own->isExpired()) {
                    $own->update($stamp);
                }

                // ⚠️ نفس حارس hasColumn بتاع السلاسل فوق
                if (\Illuminate\Support\Facades\Schema::hasColumn('clients', 'setup_reviewed_at')) {
                    $client->setup_reviewed_at = ! empty($data['reviewed']) ? now() : null;
                    $client->save();
                }

                $saved++;
            }
        });

        return back()->with('ok', __('client.setup_all_saved', [
            'count' => $saved, 'clients' => $saved,
        ]));
    }

    /** @return array<int|string, array> */
    private function validatedRows(Request $request): array
    {
        return $request->validate([
            'rows' => ['required', 'array'],
            'rows.*.division' => ['nullable', Divisions::rule()],
            'rows.*.fulfillment_mode' => ['nullable', Rule::in([
                Divisions::FULFILLMENT_CASHVAN,
                Divisions::FULFILLMENT_DELIVERY,
                Divisions::FULFILLMENT_ONLINE,
            ])],
            'rows.*.price_list_id' => ['nullable', 'exists:price_lists,id'],
            'rows.*.discount' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'rows.*.inclusive' => ['nullable', 'boolean'],
            // علامة «اتراجعت» — تتبع تقدم المالك في ضبط السيستم
            'rows.*.reviewed' => ['nullable', 'boolean'],
        ])['rows'];
    }

    /**
     * الحمولة المكتوبة — **نفس البيانات بالظبط زي ما المالك قال**.
     *
     * ⚠️ قايمة السعر بتكتب **العمودين** (`id` + الكود النصي) — نفس
     * درس الختم الجماعي: `Pricing::listRowFor` بتقرا الاتنين،
     * وكتابة واحد بيسيب تناقض بيرجع يبان في أول استيراد.
     */
    private function payload(array $data): array
    {
        $out = [
            'division' => $data['division'] ?? null,
            'fulfillment_mode' => $data['fulfillment_mode'] ?? null,
            // شامل = مفيش ضريبة فوق السعر
            'taxable' => ! (bool) ($data['inclusive'] ?? false),
            'discount' => isset($data['discount']) && $data['discount'] !== null
                ? round((float) $data['discount'], 2) / 100
                : 0,
        ];

        if (! empty($data['price_list_id'])) {
            $list = PriceList::find((int) $data['price_list_id']);

            if ($list !== null) {
                $out['price_list_id'] = $list->id;
                $out['price_list'] = $list->code;
            }
        }

        return $out;
    }

    /**
     * اللي بيتختم على العقود السارية وقت الحفظ — النسبة والقايمة.
     *
     * ⚠️ **الصفر بيتختم زي أي رقم** — دي أصل الحكاية: المالك كتب صفر
     * وشاف العقد لسه بـ30%. الخانة الفاضية برضه بتتكتب صفر على
     * العميل (نفس منطق `payload`)، فالعقد ياخد صفر معاها — الصف
     * بيتطبق «بالظبط» زي ما هو معروض.
     *
     * ⚠️ العقد بيتخزن الخصم كسر (0.30) زي العميل بالظبط.
     */
    private function contractStamp(array $data): array
    {
        $stamp = [
            'discount' => isset($data['discount']) && $data['discount'] !== null
                ? round((float) $data['discount'], 2) / 100
                : 0,
        ];

        if (! empty($data['price_list_id'])) {
            $list = PriceList::find((int) $data['price_list_id']);

            if ($list !== null) {
                $stamp['price_list_id'] = $list->id;
                $stamp['price_list'] = $list->code;
            }
        }

        return $stamp;
    }
}
