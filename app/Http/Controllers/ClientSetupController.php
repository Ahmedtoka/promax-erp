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

    /** حفظ صف سلسلة — بينشر على كل فروعها */
    public function saveChain(Request $request, ClientGroup $group)
    {
        $data = $this->validated($request);

        DB::transaction(function () use ($group, $data, $request) {
            Client::visibleTo($group->clients(), $request->user())
                ->update($this->payload($data));
        });

        return back()->with('ok', __('client.setup_chain_saved', [
            'chain' => $group->displayName(),
            'count' => $group->clients()->count(),
        ]));
    }

    /** حفظ صف عميل واحد */
    public function saveClient(Request $request, Client $client)
    {
        $client->update($this->payload($this->validated($request)));

        return back()->with('ok', __('client.setup_client_saved', [
            'client' => $client->displayName(),
        ]));
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'division' => ['nullable', Divisions::rule()],
            'fulfillment_mode' => ['nullable', Rule::in([
                Divisions::FULFILLMENT_CASHVAN,
                Divisions::FULFILLMENT_DELIVERY,
                Divisions::FULFILLMENT_ONLINE,
            ])],
            'price_list_id' => ['nullable', 'exists:price_lists,id'],
            'discount' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'inclusive' => ['nullable', 'boolean'],
        ]);
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
}
