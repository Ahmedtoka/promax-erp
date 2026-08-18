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
        return view('erp.client_setup', [
            'mode' => 'chains',
            'rows' => ClientGroup::withCount('clients')
                ->where('active', true)->orderBy('name')->get(),
            'lists' => PriceList::where('active', true)->orderBy('id')->get(),
        ]);
    }

    /** صفحة العملاء الفرادى — صف لكل عميل */
    public function clients(Request $request)
    {
        $q = Client::visibleTo(Client::query())
            ->with(['group', 'zone', 'priceListRow'])
            ->where('status', '!=', 'rejected');

        // ⚠️ فلتر «بدون قسم» — ده اللي المالك هيشتغل عليه أساساً
        if ($request->boolean('unassigned')) {
            $q->whereNull('division');
        }

        return view('erp.client_setup', [
            'mode' => 'clients',
            'rows' => $q->orderBy('name')->get(),
            'lists' => PriceList::where('active', true)->orderBy('id')->get(),
        ]);
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
