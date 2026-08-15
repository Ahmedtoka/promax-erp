<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Transaction;
use Illuminate\Http\Request;

/**
 * السلاسل والمجموعات — Circle K وجورميه وغيرهم
 */
class GroupController extends Controller
{
    public function index(Request $request)
    {
        $q = ClientGroup::query()->with('channel')->withCount('clients');

        if ($s = $request->string('q')->trim()->value()) {
            $q->where('name', 'like', "%$s%");
        }
        if ($ch = $request->integer('channel')) {
            $q->where('channel_id', $ch);
        }

        // الافتراضي: أكتر الفروع الأول (قرار المالك 2026-08-06) —
        // السلاسل الكبيرة هي اللي بتتراجع كل يوم
        $groups = $q->orderByDesc('clients_count')->orderBy('name')->get();

        // أرقام كل سلسلة في استعلام واحد
        $stats = Client::visibleTo(Client::query(), $request->user())
            ->whereNotNull('group_id')
            ->selectRaw('group_id,
                SUM(purchases) as purchases,
                SUM(collections) as collections,
                SUM(balance) as balance')
            ->groupBy('group_id')
            ->get()->keyBy('group_id');

        return view('erp.groups', [
            'groups' => $groups,
            'stats' => $stats,
            'channels' => Channel::orderBy('id')->get(),
            'filters' => $request->only(['q', 'channel']),
            'ungrouped' => Client::visibleTo(Client::whereNull('group_id'), $request->user())
                ->where('category', '!=', 'internal')->count(),
        ]);
    }

    public function show(ClientGroup $group, Request $request)
    {
        $branches = Client::visibleTo(
            $group->clients()->with(['zone', 'contract', 'group.contract']),
            $request->user()
        )
            ->orderByDesc('purchases')
            ->get();

        $ids = $branches->pluck('id');

        $monthly = Transaction::whereIn('client_id', $ids)
            ->selectRaw("DATE_FORMAT(date, '%Y-%m') as m,
                         SUM(CASE WHEN kind = 'sale' THEN debit ELSE 0 END) as sales,
                         SUM(CASE WHEN kind = 'collection' THEN credit ELSE 0 END) as coll")
            ->groupBy('m')->orderBy('m')->get();

        return view('erp.group', [
            'g' => $group->load('channel'),
            'branches' => $branches,
            'monthly' => $monthly,
            'todaySales' => (float) Invoice::whereIn('client_id', $ids)
                ->whereDate('created_at', today())->sum('total'),
            'contracts' => $branches->filter(fn ($b) => $b->contract !== null),
            'zones' => \App\Models\Zone::orderBy('code')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request, creating: true);

        // الكود ثابت من الاسم — لو اتكرر بنزوّد رقم عشان الـ unique ما يقعش
        $base = ClientGroup::nextCode($data['name']);
        $code = $base;
        $n = 2;
        while (ClientGroup::where('code', $code)->exists()) {
            $code = substr($base, 0, 36).'-'.$n++;
        }
        $data['code'] = $code;

        $group = ClientGroup::create($data);

        return redirect()->route('erp.groups.show', $group)->with('ok', __('flash.chain_created'));
    }

    public function update(Request $request, ClientGroup $group)
    {
        $request->validate([
            'apply_discount' => ['nullable', 'numeric', 'min:0', 'max:100'],
            // ⚠️ **`channel` قيمة صريحة مش فاضي.** الفاضي معناه
            // «ماتلمسش الفروع»، و«حسب القناة» قرار حقيقي بيتخزن
            // `null` في العمود — لو الاتنين اتخلطوا، مافيش طريقة
            // تقول للسيستم «رجّع الفروع دي لافتراضي قناتها».
            'apply_payment_terms' => ['nullable', 'in:channel,'.implode(',', Client::PAY_TERMS)],
            'apply_payment_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'apply_payment_days_from' => ['nullable', 'in:'.implode(',', Contract::DAYS_FROM)],
        ]);

        $group->update($this->validated($request));

        // ═══ شروط الدفع على كل الفروع ═══
        //
        // ⚠️ **نفس نمط الخصم بالظبط** (قرار 2026-08-08): السلسلة
        // تجميعة مش كيان تجاري، فالشروط بتتختم على كل فرع كقيمة
        // بتاعته هو — مش عمود على السلسلة بيتوَرَّث. كده الفرع اللي
        // هيتفاوض لوحده بعدين يتعدّل لوحده من غير ما يخرج من السلسلة.
        //
        // ⚠️ **والعقد الساري لسه بيغلب.** `Client::paymentDays()`
        // بتقرا العقد الأول — فختم مدة على فرع ليه عقد بمدة تانية
        // مابيغيّرش حاجة في الحساب الفعلي، بيملا خانته الاحتياطية بس.
        if ($request->filled('apply_payment_terms')) {
            $terms = $request->string('apply_payment_terms')->toString();

            $fields = [
                // «حسب القناة» = فضّي العمود
                'payment_terms' => $terms === 'channel' ? null : $terms,
            ];

            // ⚠️ **الكاش مالوش مدة سداد — بنمسحها.** لو سيبناها،
            // الفرع بيفضل شايل «30 يوم» وهو كاش، وخانة «المتأخر»
            // بتتحسب من رقم مالوش معنى (نفس الباج اللي اتصلح على
            // مستوى العميل).
            if (in_array($fields['payment_terms'], [Client::PAY_CREDIT, Client::PAY_BOTH], true)) {
                $days = $request->input('apply_payment_days');

                if ($days !== null && $days !== '') {
                    $fields['payment_days'] = (int) $days;
                    $fields['payment_days_from'] = $request->input('apply_payment_days_from')
                        ?: Contract::DAYS_FROM_FIRST_SUPPLY;
                }
            } else {
                $fields['payment_days'] = null;
                $fields['payment_days_from'] = null;
            }

            $n = Client::visibleTo($group->clients(), $request->user())->update($fields);

            return back()->with('ok', __('client.chain_payment_applied', [
                'terms' => $fields['payment_terms'] === null
                    ? __('client.terms_by_channel')
                    : __('client.terms_'.$fields['payment_terms']),
                'count' => $n,
            ]));
        }

        // ⚠️ **الخانة الفاضية غير الصفر.** فاضية = ماتلمسش خصومات
        // الفروع (الوضع الطبيعي لأي حفظ)؛ صفر مكتوب = صفّرهم كلهم
        // عن قصد. لو الفاضي اتعامل كصفر، أي تعديل اسم كان بيمسح
        // خصومات السلسلة كلها في صمت.
        if ($request->filled('apply_discount')) {
            $pct = round((float) $request->input('apply_discount'), 2);

            // ⚠️ بيتكتب كخصم خاص على كل فرع — مش عمود على السلسلة.
            // قرار 2026-08-01: السلسلة تجميعة مش كيان تجاري، والخصم
            // بيعيش على الفرع عشان الفرع اللي هيتفاوض لوحده بعدين
            // يتعدّل لوحده. والفرع اللي ليه عقد سارٍ بخصم فاتورة،
            // خصم العقد هو اللي بيتحاسب بيه (أولوية `effectiveDiscount`).
            $n = Client::visibleTo($group->clients(), $request->user())->update(['discount' => $pct / 100]);

            return back()->with('ok', __('client.chain_discount_applied', [
                'pct' => $pct,
                'count' => $n,
            ]));
        }

        return back()->with('ok', __('flash.chain_saved'));
    }

    public function destroy(ClientGroup $group)
    {
        if ($group->clients()->exists()) {
            // مش بنمسح سلسلة فيها فروع — بنوقفها
            $group->update(['active' => false]);

            return back()->with('ok', __('flash.chain_suspended'));
        }

        $group->delete();

        return redirect()->route('erp.groups')->with('ok', __('flash.chain_deleted'));
    }

    /** ضم فروع للسلسلة أو فكّها */
    public function attach(Request $request, ClientGroup $group)
    {
        $data = $request->validate([
            'client_ids' => ['required', 'array'],
            'client_ids.*' => ['exists:clients,id'],
            'action' => ['required', 'in:attach,detach'],
        ]);

        // ⚠️ سكوب: المدير يضمّ/يفكّ عملاءه هو بس. من غير `visibleTo`
        // كان يقدر يبعت أي `client_id` (الفاليديشن `exists` بس) ويضم
        // فروع فريق تاني لسلسلته — وعضوية السلسلة بتورّث خصم/عقد. نفس
        // حارس الإخوة فوق (سطر 149/171). العدّ من الصفوف المتأثرة فعلاً.
        $n = Client::visibleTo(
            Client::whereIn('id', $data['client_ids']),
            $request->user()
        )->update([
            'group_id' => $data['action'] === 'attach' ? $group->id : null,
        ]);

        return back()->with('ok', $data['action'] === 'attach'
            ? __('flash.branches_attached', ['count' => $n])
            : __('flash.branches_detached', ['count' => $n]));
    }

    private function validated(Request $request, bool $creating = false): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            // ⚠️ الاسم الإنجليزي إجباري الوجود في الفورم — العميل
            // بيتعرض في الفاتورة والتصدير للمصلحة وكشوف السلسلة،
            // والاسم العربي في شاشة إنجليزية بيكسّر الصفحة بصرياً.
            'name_en' => ['nullable', 'string', 'max:120'],
            'channel_id' => ['nullable', 'exists:channels,id'],
            'sub_channel' => ['nullable', 'in:chain,convenience'],
            // ⚠️ **مفيش خصم ولا مسؤول على السلسلة.** قرار 2026-08-01:
            // السلسلة تجميعة عرض بس. كل فرع عميل مستقل بعقده وخصمه
            // ومسؤوله — والخصم على مستوى السلسلة كان بيتجاهل اتفاق
            // الفرع اللي اتفاوض لوحده.
            'notes' => ['nullable', 'string'],
        ]);
        // في الإنشاء السلسلة شغّالة افتراضيًا، في التعديل الشيك بوكس هو الحكم
        // (لو مش متبعت يبقى المستخدم شالها = موقوفة).
        $data['active'] = $creating ? $request->boolean('active', true) : $request->boolean('active');

        return $data;
    }
}
