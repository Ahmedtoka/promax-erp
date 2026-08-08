<?php

namespace App\Http\Controllers;

use App\Exceptions\Rejected;
use App\Models\Channel;
use App\Models\Client;
use App\Models\CustodyItem;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\MerchVisit;
use App\Models\Product;
use App\Models\ReplenishmentRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Scope;
use Illuminate\Http\Request;

/**
 * إدارة القنوات + شغل البروموتر (زيارات الرفوف وطلبات الريفيل)
 *
 * ⚠️ **القناة بُعد تجميع وتقرير مش مصدر تسعير.** قرار 2026-07-31.
 * الشاشة بتجاوب على: القناة دي فيها كام عميل، وكام بضاعة، وعاملة كام
 * مبيعات. النسبة بتتحدد لكل عميل من عقده أو خصمه الخاص أو سلسلته.
 */
class ChannelController extends Controller
{
    // ================= القنوات =================

    public function index(Request $request)
    {
        return view('erp.channels', [
            'channels' => Channel::orderBy('id')->get(),
            'stats' => $this->channelStats(),
            'spread' => $this->discountSpread(),
            'subCounts' => Client::selectRaw('sub_channel, COUNT(*) as n')
                ->whereNotNull('sub_channel')->groupBy('sub_channel')
                ->pluck('n', 'sub_channel')->all(),
            // ⚠️ عملاء من غير قناة = عملاء مش داخلين في أي رقم في
            // الشاشة دي. لازم يبانوا، وإلا الإجماليات تحت بتقل عن
            // إجمالي السيستم ومحدش يعرف ليه.
            'orphans' => Client::whereNull('channel_id')->where('status', 'active')->count(),
            'managers' => User::whereIn('role', User::ASSIGNABLE_MANAGER_ROLES)
                ->where('active', true)->with('channels')->get(),
        ]);
    }

    /**
     * أرقام كل قناة.
     *
     * ⚠️ **كل رقم استعلام مجمّع واحد لكل القنوات**، مش استعلام لكل
     * قناة جوه لوب. الشاشة القديمة كانت بتعمل 5 استعلامات × عدد
     * القنوات؛ دي بتعمل 6 إجمالاً مهما زادت القنوات.
     *
     * @return array<int, array<string, float|int>>
     */
    private function channelStats(): array
    {
        // ═══ العملاء والفلوس ═══
        // ملاحظة: `returns` كلمة محجوزة في MySQL — لازم backticks
        $money = Client::query()
            ->whereNotNull('channel_id')
            ->selectRaw("channel_id,
                COUNT(*) as n_clients,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as n_active,
                SUM(`purchases`) as purchases,
                SUM(`collections`) as collections,
                SUM(`returns`) as n_returns,
                SUM(`balance`) as balance,
                SUM(CASE WHEN `balance` > 0 THEN 1 ELSE 0 END) as n_owing")
            ->groupBy('channel_id')
            ->get()->keyBy('channel_id');

        // ═══ بضاعة أمانة عند العملاء ═══
        // ⚠️ دي **مش مديونية** — البضاعة لسه ملك بروماكس لحد ما تتباع
        // من الرف. عرضها في نفس عمود الرصيد كان بيخلّي الرقمين يتجمعوا
        // في دماغ اللي بيقرا وهما نوعين مختلفين تماماً.
        $consignment = Transaction::query()
            ->join('clients', 'clients.id', '=', 'transactions.client_id')
            ->where('transactions.kind', 'consignment')
            ->whereNotNull('clients.channel_id')
            ->selectRaw('clients.channel_id, SUM(transactions.debit - transactions.credit) as amt')
            ->groupBy('clients.channel_id')
            ->pluck('amt', 'channel_id');

        // ═══ الكميات المباعة ═══
        $units = InvoiceItem::query()
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->join('clients', 'clients.id', '=', 'invoices.client_id')
            ->whereNotNull('clients.channel_id')
            ->selectRaw('clients.channel_id, SUM(invoice_items.qty) as units')
            ->groupBy('clients.channel_id')
            ->pluck('units', 'channel_id');

        // ═══ بضاعة في عربيات مناديب القناة (عهدة مفتوحة) ═══
        // ⚠️ بسعر البيع الجديد مش بالتكلفة. الشاشة دي تجارية،
        // والمقارنة مع المبيعات لازم تكون بنفس المقياس.
        // ⚠️ **`returned` كان ناقص من المعادلة** (تدقيق ٨/٨/٢٠٢٦).
        // `CustodyItem::remaining()` = `assigned − sold − returned`،
        // والكويري دي كانت بتحسب `assigned − sold` بس — فبضاعة
        // المندوب رجّعها للمخزن كانت لسه محسوبة كأنها في عربيته.
        // الشاشة كانت بتقول رقم أكبر من الحقيقة كل يوم فيه مرتجع.
        //
        // ⚠️ **و`price_new` كانت مقروءة على طول** بدل ما تعدي على
        // `Pricing` — العميل اللي على قايمة تانية بيتحسب بسعر مش
        // بتاعه. القايمة هنا مالهاش عميل (دي بضاعة في عربية)، فبنقرا
        // **سعر القايمة الافتراضية** صراحةً من `price_list_items`
        // عن طريق `Pricing::byList` — وده الرقم اللي الشاشة بتقارن
        // بيه المبيعات.
        //
        // ⚠️ الحساب اتنقل لـPHP مش SQL عن قصد: الأسعار بقت صفوف في
        // `price_list_items` مش عمود على `products`، ونسخ منطق
        // اختيار القايمة في SQL كان هيبقى مصدر تاني للسعر.
        $vanRows = CustodyItem::query()
            ->join('custodies', 'custodies.id', '=', 'custody_items.custody_id')
            ->join('users', 'users.id', '=', 'custodies.user_id')
            ->where('custodies.status', 'open')
            ->whereNotNull('users.channel_id')
            ->selectRaw('users.channel_id, custody_items.product_id,
                SUM(custody_items.assigned - custody_items.sold - custody_items.returned) as units')
            ->groupBy('users.channel_id', 'custody_items.product_id')
            ->get();

        $productsById = Product::whereIn('id', $vanRows->pluck('product_id')->unique())
            ->get()->keyBy('id');

        $inVans = $vanRows
            ->groupBy('channel_id')
            ->map(function ($rows, $channelId) use ($productsById) {
                $amt = 0.0;
                $units = 0;

                foreach ($rows as $r) {
                    $left = max(0, (int) $r->units);
                    $units += $left;

                    $p = $productsById->get($r->product_id);

                    if ($p !== null) {
                        $amt += $left * \App\Services\Pricing::byList($p, \App\Services\Pricing::LIST_NEW);
                    }
                }

                return (object) [
                    'channel_id' => $channelId,
                    'amt' => round($amt, 2),
                    'units' => $units,
                ];
            });

        // ═══ مبيعات النهارده ═══
        $today = Invoice::query()
            ->join('clients', 'clients.id', '=', 'invoices.client_id')
            ->whereNotNull('clients.channel_id')
            ->whereDate('invoices.created_at', today())
            ->selectRaw('clients.channel_id, SUM(invoices.total) as amt')
            ->groupBy('clients.channel_id')
            ->pluck('amt', 'channel_id');

        // ═══ الفريق ═══
        $team = User::query()
            ->whereNotNull('channel_id')->where('active', true)
            ->selectRaw('channel_id, COUNT(*) as n')
            ->groupBy('channel_id')->pluck('n', 'channel_id');

        $out = [];

        foreach (Channel::pluck('id') as $id) {
            $m = $money[$id] ?? null;
            $v = $inVans[$id] ?? null;

            $out[$id] = [
                'clients' => (int) ($m->n_clients ?? 0),
                'active_clients' => (int) ($m->n_active ?? 0),
                'owing' => (int) ($m->n_owing ?? 0),
                'purchases' => (float) ($m->purchases ?? 0),
                'collections' => (float) ($m->collections ?? 0),
                'returns' => (float) ($m->n_returns ?? 0),
                'balance' => (float) ($m->balance ?? 0),
                'consignment' => (float) ($consignment[$id] ?? 0),
                'units' => (int) ($units[$id] ?? 0),
                'in_vans' => (float) ($v->amt ?? 0),
                'in_vans_units' => (int) ($v->units ?? 0),
                'today' => (float) ($today[$id] ?? 0),
                'team' => (int) ($team[$id] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * توزيع الخصم الفعلي جوه القناة — **للقراءة بس**.
     *
     * ⚠️ الشاشة لازم تقول «العملاء دول على كام» من غير ما تدّي انطباع
     * إن الرقم بيتظبط من هنا. القناة بتعرض المدى عشان المدير يشوف
     * التشتت: قناة كلها 50% غير قناة من 10% لـ 60% — التانية معناها
     * إن مفيش سياسة تسعير موحّدة وفيه عميل بياخد شروط محدش راجعها.
     *
     * ⚠️ `chunkById` مش `get()` — الحساب بيعدّي على كل عميل نشط،
     * وتحميلهم كلهم في الذاكرة بيفجّرها لما العدد يكبر.
     *
     * @return array<int, array{min: float, max: float, avg: float, n: int}>
     */
    private function discountSpread(): array
    {
        $acc = [];

        Client::with(['contract', 'group.contract', 'group'])
            ->whereNotNull('channel_id')
            ->where('status', 'active')
            ->chunkById(200, function ($chunk) use (&$acc) {
                foreach ($chunk as $client) {
                    $rate = $client->effectiveDiscount();
                    $id = $client->channel_id;

                    if (! isset($acc[$id])) {
                        $acc[$id] = ['min' => $rate, 'max' => $rate, 'sum' => 0.0, 'n' => 0];
                    }

                    $acc[$id]['min'] = min($acc[$id]['min'], $rate);
                    $acc[$id]['max'] = max($acc[$id]['max'], $rate);
                    $acc[$id]['sum'] += $rate;
                    $acc[$id]['n']++;
                }
            });

        $out = [];

        foreach ($acc as $id => $row) {
            $out[$id] = [
                'min' => $row['min'],
                'max' => $row['max'],
                'avg' => $row['n'] ? $row['sum'] / $row['n'] : 0.0,
                'n' => $row['n'],
            ];
        }

        return $out;
    }

    public function update(Request $request, Channel $channel)
    {
        // ⚠️ **مفيش نسبة خصم.** القناة بُعد تجميع — النسبة بتتحدد لكل
        // عميل. لما كانت هنا، عميل جديد في «كي أكاونت» كان بياخد 50%
        // أوتوماتيك من غير ما حد يتفاوض عليها، وأول فاتورة بتطلع بخصم
        // محدش قرره.
        //
        // ⚠️ **الاسمين مع بعض.** `displayName()` بترجّع العربي كـfallback،
        // فالواجهة الإنجليزية بتعرض «كي أكاونت» في نص جملة إنجليزي
        // والغلط بيعدّي من غير ما حد ياخد باله.
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'name_en' => ['nullable', 'string', 'max:100'],
            'active' => ['nullable', 'boolean'],
        ]);

        $channel->update([
            'name' => $data['name'],
            'name_en' => $data['name_en'] ?? null,
            'active' => $request->boolean('active', true),
        ]);

        return back()->with('ok', __('flash.channel_updated'));
    }

    /** ربط مدير بقنوات */
    public function assignManager(Request $request, User $user)
    {
        $data = $request->validate([
            'channels' => ['nullable', 'array'],
            'channels.*' => ['exists:channels,id'],
        ]);

        $user->channels()->sync($data['channels'] ?? []);

        return back()->with('ok', __('flash.manager_channels_updated', ['name' => $user->displayName()]));
    }

    // ================= زيارات البروموتر =================

    public function merchVisits(Request $request)
    {
        // ⚠️ **سكوب الفريق** (تدقيق ٨/٨/٢٠٢٦): القايمة كانت على مستوى
        // الشركة — صور رفوف وزيارات بروموترات مديرين تانيين.
        $team = User::fieldVisibleTo(User::query(), $request->user())->select('id');

        $q = MerchVisit::with(['user', 'client.channel', 'refills.product'])
            ->whereIn('user_id', $team);

        if ($userId = $request->integer('user')) {
            $q->where('user_id', $userId);
        }
        if ($date = $request->string('date')->value()) {
            $q->whereDate('created_at', $date);
        }

        return view('erp.merch_visits', [
            'visits' => $q->latest()->paginate(25)->withQueryString(),
            'promoters' => User::fieldVisibleTo(
                User::where('role', 'promoter'), $request->user())->get(),
            'filters' => $request->only(['user', 'date']),
        ]);
    }

    // ================= طلبات الريفيل =================

    public function replenishments(Request $request)
    {
        // ⚠️ سكوب الفريق — نفس سبب `merchVisits` فوق. الفلترة على
        // العميل (مش على البروموتر) عشان الطلب بيتحوّل لـPO على
        // حساب العميل، والمدير المسؤول عن العميل هو صاحب القرار.
        $q = ReplenishmentRequest::with(['client', 'promoter', 'assignee', 'items.product'])
            ->whereIn('client_id', Client::visibleTo(Client::query(), $request->user())->select('id'));

        if ($status = $request->string('status')->value()) {
            $q->where('status', $status);
        }

        return view('erp.replenishments', [
            'requests' => $q->latest()->paginate(25)->withQueryString(),
            'drivers' => User::fieldVisibleTo(
                User::whereIn('role', ['driver', 'sales_agent']), $request->user())->get(),
            'filters' => $request->only('status'),
        ]);
    }

    /** تنزيل طلب الريفيل على مندوب — وبيتحول لأمر توريد */
    public function assignReplenishment(Request $request, ReplenishmentRequest $replenishmentRequest)
    {
        $data = $request->validate([
            'assigned_to' => ['required', 'exists:users,id'],
            // ⚠️ `channel` اتشالت — القناة مابقاش لها سعر. التسعير
            // بيتحدد من قائمة العميل وخصمه.
            'price_mode' => ['required', 'in:client,old,new'],
        ]);

        try {
            // المنطق كله في الموديل عشان الويب والأبلكيشن يمشوا بنفس الفلو
            // ⚠️ الفاعل بيتبعت للموديل عشان الحارس يتنفّذ هناك —
            // الراوت ده كان بلا حارس قناة ولا فحص مستلم، والتوأم في
            // الـAPI كان بيفحص الطلب مش المستلم.
            $po = $replenishmentRequest->assignTo(
                User::findOrFail($data['assigned_to']),
                $data['price_mode'],
                $request->user(),
            );
        } catch (Rejected $e) {
            // رفض متوقّع بس — خطأ SQL بيكمّل لـ 500 عن قصد
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return back()->with('ok', __('flash.replenishment_assigned', ['number' => $po->number]));
    }

    public function cancelReplenishment(Request $request, ReplenishmentRequest $replenishmentRequest)
    {
        // ⚠️ **كانت بلا أي حارس إطلاقاً** — أي مدير بيلغي طلب ريفيل
        // لأي فرع في الشركة، والبروموتر بيرجع الأسبوع الجاي يلاقي
        // الرف فاضي وطلبه ملغي من حد مالوش علاقة.
        Scope::assertClient($request->user(), $replenishmentRequest->client);

        $replenishmentRequest->update(['status' => 'cancelled']);

        return back()->with('ok', __('flash.replenishment_cancelled'));
    }
}
