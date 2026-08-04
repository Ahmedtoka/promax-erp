<?php

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Models\Client;
use App\Models\ClientRequest;
use App\Models\Invoice;
use App\Models\PickOrder;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\TrackEvent;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\Zone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * العمليات الميدانية — المناديب، العهدة، أوامر التوريد، موافقات العملاء، التراكينج
 */
class OpsController extends Controller
{
    // ================= لوحة العمليات =================

    public function dashboard()
    {
        // ⚠️ سكوب الفرع على لوحة العمليات
        $field = \App\Models\Branch::scope(
            User::whereIn('role', User::FIELD_ROLES)->with('zone'),
        )->get();

        return view('ops.dashboard', [
            'field' => $field->map(fn ($u) => $this->userStats($u)),
            'todaySales' => Invoice::whereDate('created_at', today())->sum('total'),
            'todayPos' => PurchaseOrder::whereDate('delivered_at', today())->sum('total'),
            'openRequests' => ClientRequest::whereIn('status', ['pending', 'review'])->count(),
            'visitsDone' => DB::table('visits')->whereDate('created_at', today())
                ->whereNotNull('checked_out_at')->count(),
            'events' => TrackEvent::with('user')->whereDate('happened_at', today())
                ->orderByDesc('happened_at')->take(30)->get(),
        ]);
    }

    private function userStats(User $u): array
    {
        $custody = $u->todayCustody();
        $custody?->load('items.product');

        return [
            'user' => $u,
            'custody' => $custody,
            'remaining' => $custody?->remainingUnits() ?? 0,
            'remainingValue' => $custody?->remainingValue($u->isDriver() ? 'old' : 'new') ?? 0,
            'sales' => Invoice::where('user_id', $u->id)->whereDate('created_at', today())->sum('total'),
            'visits' => $u->visits()->whereDate('created_at', today())->count(),
            'visitsDone' => $u->visits()->whereDate('created_at', today())->whereNotNull('checked_out_at')->count(),
            'pos' => PurchaseOrder::where('assigned_to', $u->id)->whereDate('created_at', today())->count(),
            'posDone' => PurchaseOrder::where('assigned_to', $u->id)->where('status', 'delivered')
                ->whereDate('delivered_at', today())->count(),
            // ⚠️ «قيمة التسليمات» = اللي السواق حصّله فعلاً، فبالإجمالي
            // شامل الضريبة. الصافي مكانه تقارير المبيعات.
            'posValue' => PurchaseOrder::where('assigned_to', $u->id)->where('status', 'delivered')
                ->whereDate('delivered_at', today())->sum('grand_total'),
            'openVisit' => $u->openVisit(),
        ];
    }

    public function rep(Request $request, User $user)
    {
        // ⚠️ نفس القاعدة: الشاشة بتوري عهدة المندوب وفواتيره وتحركاته
        abort_unless($request->user()->canSeeBranch($user->branch_id), 403);

        $custody = $user->todayCustody();
        $custody?->load('items.product');

        return view('ops.rep', [
            'u' => $user,
            'stats' => $this->userStats($user),
            'custody' => $custody,
            'invoices' => Invoice::with('client')->where('user_id', $user->id)
                ->latest()->take(30)->get(),
            'visits' => $user->visits()->with('client')->take(30)->get(),
            'events' => $user->trackEvents()->whereDate('happened_at', today())->get(),
            'products' => Product::orderBy('code')->get(),
        ]);
    }

    // ================= العهدة =================

    // ⚠️ `loadVan` (التحميل المباشر) **اتشال** (قرار المالك 2026-08-03):
    // كان بيجهّز ويسلّم في نفس الثانية من غير استلام المندوب من
    // الأبلكيشن — التحميل الرسمي بقى من فلو تسليم العهدة:
    // CustodyHandoutController::store ← تجهيز الطلبات ← تأكيد ← استلام.

    public function closeCustody(User $user)
    {
        $custody = $user->todayCustody();
        $custody?->update(['status' => 'closed', 'closed_at' => now()]);

        return back()->with('ok', __('flash.van_closed'));
    }

    // ================= أوامر التوريد =================

    public function purchaseOrders(Request $request)
    {
        $q = PurchaseOrder::with(['client', 'courier', 'items']);
        if ($status = $request->string('status')->value()) {
            $q->where('status', $status);
        }

        return view('ops.pos', [
            'pos' => $q->latest()->paginate(30)->withQueryString(),
            'couriers' => User::where('role', 'driver')->get(),
            'clients' => Client::orderBy('name')->get(['id', 'name']),
            'products' => Product::orderBy('code')->get(),
            'filters' => $request->only('status'),
        ]);
    }

    public function storePurchaseOrder(Request $request)
    {
        $data = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'source' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:190'],
            'assigned_to' => ['nullable', 'exists:users,id'],
            'price_mode' => ['required', 'in:channel,old,new'],
            'due_date' => ['nullable', 'date'],
            // ═══ فلو الكي أكاونت (2026-08-04): موعد بالساعة + مخزن
            // التجهيز + فلاج «محتاج موافقة الحسابات» ═══
            'due_at' => ['nullable', 'date'],
            'warehouse_id' => ['nullable', 'exists:warehouses,id'],
            'approval' => ['nullable', 'boolean'],
            'qty' => ['required', 'array'],
            'qty.*' => ['nullable', 'integer', 'min:0'],
            'unit' => ['nullable', 'array'],
            'unit.*' => ['nullable', 'in:piece,box,case'],
        ]);

        // ⚠️ فلو الموافقة لازم له مندوب ومخزن — التجهيز بيتعمل منهم
        // وقت موافقة الحسابات، مش وقت الإنشاء.
        if ($request->boolean('approval') && (empty($data['assigned_to']) || empty($data['warehouse_id']))) {
            return back()->withErrors(['assigned_to' => __('ops.po_needs_rep_wh')])->withInput();
        }

        // ⚠️ **وحدة الإدخال بتتضرب هنا مش في الجافاسكريبت.** «5 كراتين»
        // بتتخزن 60 قطعة على بنود الأمر — والتسعير بالقطعة زي ما هو.
        // وحدة مش معرّفة للصنف = رفض الأمر كله، مش افتراض قطعة.
        foreach ($request->input('unit', []) as $productId => $unit) {
            if (! $unit || $unit === 'piece' || empty($data['qty'][$productId])) {
                continue;
            }

            $factor = Product::find($productId)?->unitFactor($unit);

            if ($factor === null) {
                return back()->withErrors([
                    'qty' => __('stock.unit_not_for_product', ['name' => Product::find($productId)?->displayName() ?? $productId]),
                ])->withInput();
            }

            $data['qty'][$productId] = (int) $data['qty'][$productId] * $factor;
        }

        $needsApproval = $request->boolean('approval');

        try {
        DB::transaction(function () use ($data, $request, $needsApproval) {
            // العميل محتاجينه عشان نحسب تسعيرته لو الوضع channel
            $client = Client::findOrFail($data['client_id']);

            $po = PurchaseOrder::create([
                'number' => PurchaseOrder::nextNumber(),
                'client_id' => $client->id,
                'source' => $data['source'] ?? null,
                'address' => $data['address'] ?? null,
                'assigned_to' => $data['assigned_to'] ?? null,
                // ⚠️ channel معناها إن السطور اتسعّرت بتسعيرة العميل (بخصمه)،
                // فالـ PO نفسه بيتسجل بالقائمة اللي العميل عليها عشان
                // مايعيدش الحساب بسعر قائمة عند التسليم.
                'price_mode' => $data['price_mode'] === 'channel'
                    ? $client->priceList()
                    : $data['price_mode'],
                'due_date' => $data['due_date'] ?? null,
                // ═══ فلو الكي أكاونت: مستني الحسابات ═══
                'approval_status' => $needsApproval ? 'pending' : null,
                'due_at' => $data['due_at'] ?? null,
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'created_by' => $request->user()->id,
                'status' => 'pending',
                'total' => 0,
            ]);

            $this->fillPoItems($po, $client, $data['qty'], $data['price_mode']);

            // ⚠️ **في فلو الموافقة المندوب مايتبلغش هنا** — بيتبلغ لما
            // المخزن يجهّز بعد موافقة الحسابات. إشعار بدري = مندوب
            // بيستنى بضاعة الحسابات ممكن ترفضها أصلاً.
            if ($po->assigned_to && ! $needsApproval) {
                AppNotification::send(
                    User::find($po->assigned_to),
                    fn () => __('field.notif_po_new_title', ['number' => $po->number]),
                    fn () => __('field.notif_po_new_body', [
                        'client' => $po->client->displayName(),
                        // ⚠️ المبلغ اللي السواق هيحصّله، مش الصافي
                        'amount' => number_format($po->fresh()->payable()),
                    ]),
                );
            }
        });
        } catch (\App\Exceptions\Rejected $e) {
            // صنف مش متسعّر — الأمر كله بيترفض بدل ما يدخل بسطر بصفر
            return back()->withErrors(['qty' => $e->getMessage()])->withInput();
        }

        return back()->with('ok', $needsApproval ? __('flash.po_sent_accounting') : __('flash.po_created'));
    }

    /**
     * بناء بنود الأمر وتسعيرها وإجمالياته — مشترك بين الإنشاء والتعديل.
     *
     * ⚠️ channel = سعر العميل بخصمه (زي الفاتورة). old/new = سعر قائمة
     * بدون خصم — مقصود لسلاسل بتتحاسب بسعر صافي متفق عليه.
     *
     * ⚠️ **سعر صفر = رفض الأمر كله** (نفس دوكترين الفاتورة «الصنف مش
     * متسعّر») — أمر PO-2001 دخل فعلاً بصنف بسعر 0.00 وكان هيتقيّد
     * على الفرع ناقص (اتشاف 2026-08-04).
     */
    private function fillPoItems(PurchaseOrder $po, Client $client, array $qtyByProduct, string $priceMode): void
    {
        $rows = [];

        foreach ($qtyByProduct as $productId => $qty) {
            $qty = (int) $qty;
            if ($qty <= 0) {
                continue;
            }
            $product = Product::find($productId);
            if (! $product) {
                continue;
            }

            $price = $priceMode === 'channel'
                ? $client->priceFor($product)
                : $product->priceFor($priceMode);

            if ((float) $price <= 0) {
                throw new \App\Exceptions\Rejected(
                    __('stock.po_not_priced', ['name' => $product->displayName()])
                );
            }

            $lineTotal = round($qty * $price, 2);

            // الضريبة سطر بسطر من `Tax` — نفس قاعدة الفاتورة بالظبط
            $taxRate = \App\Services\Tax::rate($client, $product);
            $lineTax = \App\Services\Tax::on($lineTotal, $client, $product);

            PurchaseOrderItem::create([
                'purchase_order_id' => $po->id,
                'product_id' => $product->id,
                'qty' => $qty,
                'price' => $price,
                'total' => $lineTotal,
                'tax_rate' => $taxRate,
                'tax' => $lineTax,
            ]);

            $rows[] = ['total' => $lineTotal, 'tax' => $lineTax];
        }

        // `total` صافي المبيعات، و`grand_total` اللي بيتقيّد عند التسليم
        $sums = \App\Services\Tax::totals($rows);

        $po->update([
            'total' => $sums['net'],
            'tax_total' => $sums['tax'],
            'grand_total' => $sums['grand'],
        ]);
    }

    // ═══════════ أوامر توريد الكي أكاونت — 2026-08-04 ═══════════

    /** فتح أمر pending للتعديل — نفس شاشة الإنشاء متملية بالبيانات */
    public function editPo(PurchaseOrder $purchaseOrder)
    {
        if ($purchaseOrder->approval_status !== 'pending') {
            return redirect()->route('ops.po.approvals')
                ->withErrors(['decision' => __('ops.po_already_decided')]);
        }

        $data = $this->poHandout()->getData();
        $data['editing'] = $purchaseOrder->load(['items', 'client']);

        return view('ops.po_handout', $data);
    }

    /**
     * حفظ تعديل أمر pending — للحسابات ولصاحب الأمر (أدمن/مدير قناة).
     *
     * ⚠️ **البنود بتتبني من الأول بأسعار النهارده** — التعديل مش
     * بيرقّع، بيعيد التسعير كأنه أمر جديد بنفس الرقم. والقرار لسه
     * عند الحسابات: التعديل مابيوافقش، بيرجّع الأمر للطابور.
     */
    public function updatePo(Request $request, PurchaseOrder $purchaseOrder)
    {
        if ($purchaseOrder->approval_status !== 'pending') {
            return back()->withErrors(['decision' => __('ops.po_already_decided')]);
        }

        $data = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'assigned_to' => ['required', 'exists:users,id'],
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'due_at' => ['required', 'date'],
            'source' => ['nullable', 'string', 'max:40'],
            'qty' => ['required', 'array'],
            'qty.*' => ['nullable', 'integer', 'min:0'],
            'unit' => ['nullable', 'array'],
            'unit.*' => ['nullable', 'in:piece,box,case'],
        ]);

        // وحدة الإدخال → قطع، في السيرفر — نفس قاعدة الإنشاء
        foreach ($request->input('unit', []) as $productId => $unit) {
            if (! $unit || $unit === 'piece' || empty($data['qty'][$productId])) {
                continue;
            }

            $factor = Product::find($productId)?->unitFactor($unit);

            if ($factor === null) {
                return back()->withErrors([
                    'qty' => __('stock.unit_not_for_product', ['name' => Product::find($productId)?->displayName() ?? $productId]),
                ])->withInput();
            }

            $data['qty'][$productId] = (int) $data['qty'][$productId] * $factor;
        }

        try {
            DB::transaction(function () use ($purchaseOrder, $data) {
                $client = Client::findOrFail($data['client_id']);

                $purchaseOrder->update([
                    'client_id' => $client->id,
                    'assigned_to' => $data['assigned_to'],
                    'warehouse_id' => $data['warehouse_id'],
                    'due_at' => $data['due_at'],
                    'source' => $data['source'] ?? null,
                    'price_mode' => $client->priceList(),
                ]);

                // بنود جديدة بالكامل — التعديل إعادة بناء مش ترقيع
                $purchaseOrder->items()->delete();
                $this->fillPoItems($purchaseOrder, $client, $data['qty'], 'channel');
            });
        } catch (\App\Exceptions\Rejected $e) {
            return back()->withErrors(['qty' => $e->getMessage()])->withInput();
        }

        return redirect()->route('ops.po.approvals')->with('ok', __('flash.po_updated'));
    }

    /** شاشة «تسليم PO للمندوب»: سلسلة ← فرع ← مندوب ← معاد ← أصناف بالوحدات */
    public function poHandout()
    {
        return view('ops.po_handout', [
            'groups' => \App\Models\ClientGroup::orderBy('name')->get(),
            // الفروع بتتفلتر بالسلسلة في الجافاسكريبت — فبنبعت الكل مع group_id
            // ⚠️ العميل حالته عمود `status` نصي ('active') مش بوليان `active`
            'clients' => Client::with('group')->where('status', 'active')->orderBy('name')
                ->get(['id', 'name', 'group_id', 'balance']),
            'reps' => User::whereIn('role', ['sales_agent', 'driver'])
                ->where('active', true)->orderBy('name')->get(['id', 'name']),
            'warehouses' => \App\Models\Warehouse::where('active', true)->orderBy('name')->get(['id', 'name', 'name_en']),
            'products' => Product::where('active', true)->orderBy('code')->get(),
        ]);
    }

    /** طابور الحسابات: مستني القرار + آخر اللي اتقرر فيهم */
    public function poApprovals()
    {
        $base = PurchaseOrder::with(['client.group', 'courier', 'items.product', 'creator', 'warehouse', 'approvedBy']);

        return view('ops.po_approvals', [
            'pending' => (clone $base)->where('approval_status', 'pending')
                ->orderBy('due_at')->get(),
            'decided' => (clone $base)->whereIn('approval_status', ['approved', 'rejected'])
                ->latest('approved_at')->limit(30)->get(),
        ]);
    }

    /**
     * قرار الحسابات: موافقة / تعديل كميات + موافقة / رفض.
     *
     * ⚠️ **الموافقة هي اللي بتعمل أمر التجهيز** — قبلها البضاعة
     * ماتتحجزش. والرفض/التعديل بيتبلغ بيه صاحب الأمر (مدير القناة).
     */
    public function decidePoApproval(Request $request, PurchaseOrder $purchaseOrder)
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'note' => ['nullable', 'string', 'max:500'],
            // تعديل الحسابات بالقطع — الشاشة بتوري التجميعة جنب الخانة
            'qty_edit' => ['nullable', 'array'],
            'qty_edit.*' => ['nullable', 'integer', 'min:0', 'max:999999'],
        ]);

        // ⚠️ قرار واحد بس — ضغطتين متتاليتين مايعملوش أمري تجهيز
        if ($purchaseOrder->approval_status !== 'pending') {
            return back()->withErrors(['decision' => __('ops.po_already_decided')]);
        }

        // ⚠️ المندوب أو المخزن اتشالوا بعد الإنشاء (nullOnDelete)؟
        // الموافقة بتعمل أمر تجهيز منهم — من غيرهم مفيش قرار.
        if ($data['decision'] === 'approved'
            && ($purchaseOrder->warehouse === null || $purchaseOrder->courier === null)) {
            return back()->withErrors(['decision' => __('ops.po_needs_rep_wh')]);
        }

        if ($data['decision'] === 'rejected') {
            $purchaseOrder->update([
                'approval_status' => 'rejected',
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
                'approval_note' => $data['note'] ?? null,
                'status' => 'cancelled',
            ]);

            // مدير القناة يعرف إن أمره اترفض وليه
            if ($purchaseOrder->created_by) {
                AppNotification::send(
                    User::find($purchaseOrder->created_by),
                    fn () => __('field.notif_po_rejected_title', ['number' => $purchaseOrder->number]),
                    fn () => ($data['note'] ?? null) ?: $purchaseOrder->client->displayName(),
                    false,
                );
            }

            return back()->with('ok', __('flash.po_rejected'));
        }

        // ═══ موافقة (مع تعديل اختياري) ═══
        try {
            DB::transaction(function () use ($purchaseOrder, $data, $request) {
                $changes = [];

                // ⚠️ التعديل بيعيد حساب السطر بنفس سعره وضريبته —
                // السعر ثابت من وقت الإنشاء، الكمية بس اللي بتتغير.
                foreach ($data['qty_edit'] ?? [] as $itemId => $newQty) {
                    if ($newQty === null || $newQty === '') {
                        continue;
                    }

                    $item = $purchaseOrder->items->firstWhere('id', (int) $itemId);

                    if (! $item || (int) $newQty === (int) $item->qty) {
                        continue;
                    }

                    $changes[] = $item->product->displayName().': '.$item->qty.' ← '.(int) $newQty;

                    if ((int) $newQty === 0) {
                        $item->delete();

                        continue;
                    }

                    $lineTotal = round((int) $newQty * (float) $item->price, 2);
                    $item->update([
                        'qty' => (int) $newQty,
                        'total' => $lineTotal,
                        'tax' => round($lineTotal * (float) ($item->tax_rate ?? 0), 2),
                    ]);
                }

                $purchaseOrder->load('items');

                if ($purchaseOrder->items->isEmpty()) {
                    throw new \App\Exceptions\Rejected(__('ops.po_no_items_left'));
                }

                if ($changes !== []) {
                    $rows = $purchaseOrder->items
                        ->map(fn ($i) => ['total' => (float) $i->total, 'tax' => (float) $i->tax])
                        ->all();
                    $sums = \App\Services\Tax::totals($rows);

                    $purchaseOrder->update([
                        'total' => $sums['net'],
                        'tax_total' => $sums['tax'],
                        'grand_total' => $sums['grand'],
                        'was_edited' => true,
                    ]);
                }

                // ⚠️ **أمر التجهيز بيتعمل هنا** — طلب (requested) بينزل
                // شاشة «تجهيز الطلبات»، وتأكيد التجهيز هناك هو اللي
                // بيخصم ويبعت إشعار للمندوب (نفس فلو العهدة بالظبط).
                $result = \App\Models\PickOrder::raise(
                    $purchaseOrder->warehouse,
                    $purchaseOrder->courier,
                    $purchaseOrder->items->pluck('qty', 'product_id')->all(),
                    \App\Models\PickOrder::PURPOSE_VAN_LOAD,
                    $request->user(),
                );

                if ($result['error']) {
                    throw new \App\Exceptions\Rejected($result['error']);
                }

                $purchaseOrder->update([
                    'approval_status' => 'approved',
                    'approved_by' => $request->user()->id,
                    'approved_at' => now(),
                    'approval_note' => $data['note'] ?? null,
                    'pick_order_id' => $result['order']->id,
                ]);

                // مدير القناة يعرف إن أمره اتعدل وإيه اللي اتغير
                if ($changes !== [] && $purchaseOrder->created_by) {
                    AppNotification::send(
                        User::find($purchaseOrder->created_by),
                        fn () => __('field.notif_po_edited_title', ['number' => $purchaseOrder->number]),
                        fn () => implode(' · ', $changes),
                        false,
                    );
                }
            });
        } catch (\App\Exceptions\Rejected $e) {
            return back()->withErrors(['decision' => $e->getMessage()]);
        }

        return back()->with('ok', __('flash.po_approved'));
    }

    public function assignPurchaseOrder(Request $request, PurchaseOrder $purchaseOrder)
    {
        $data = $request->validate(['assigned_to' => ['required', 'exists:users,id']]);
        $purchaseOrder->update($data);

        AppNotification::send(
            User::find($data['assigned_to']),
            fn () => __('field.notif_po_assigned_title', ['number' => $purchaseOrder->number]),
            fn () => $purchaseOrder->client->displayName(),
        );

        return back()->with('ok', __('flash.po_assigned'));
    }

    // ================= موافقات العملاء الجدد =================

    public function requests(Request $request)
    {
        $q = ClientRequest::with(['rep', 'zone', 'client']);
        if ($status = $request->string('status')->value()) {
            $q->where('status', $status);
        }

        return view('ops.requests', [
            'requests' => $q->latest()->paginate(30)->withQueryString(),
            'zones' => Zone::orderBy('code')->get(),
            'filters' => $request->only('status'),
        ]);
    }

    public function decideRequest(Request $request, ClientRequest $clientRequest)
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approved,review,rejected'],
            'zone_id' => ['nullable', 'exists:zones,id'],
            'discount' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        DB::transaction(function () use ($data, $clientRequest, $request) {
            $clientRequest->status = $data['decision'];
            $clientRequest->decided_by = $request->user()->id;
            $clientRequest->decided_at = now();
            $clientRequest->decision_note = $data['note'] ?? null;

            if ($data['decision'] === 'approved') {
                $client = Client::create([
                    'code' => Client::nextCode(),
                    'name' => $clientRequest->name,
                    'phone' => $clientRequest->phone,
                    'address' => $clientRequest->address,
                    'zone_id' => $data['zone_id'] ?? $clientRequest->zone_id,
                    'category' => 'grow',
                    'status' => 'active',
                    'discount' => ($data['discount'] ?? 0) / 100,
                    'is_new' => true,
                    'has_docs' => $clientRequest->has_docs,
                    'photo_path' => $clientRequest->photo_path,
                    'docs_path' => $clientRequest->docs_path,
                    'docs_type' => $clientRequest->docs_type,
                    'created_by' => $clientRequest->created_by,
                ]);
                $clientRequest->client_id = $client->id;

                AppNotification::send(
                    $clientRequest->rep,
                    fn () => __('field.notif_client_approved_title', ['name' => $clientRequest->name]),
                    fn () => __('field.notif_client_approved_body'),
                );
            } elseif ($data['decision'] === 'review') {
                AppNotification::send(
                    $clientRequest->rep,
                    fn () => __('field.notif_client_review_title', ['name' => $clientRequest->name]),
                    fn () => $data['note'] ?? __('field.notif_client_review_body'),
                );
            } else {
                AppNotification::send(
                    $clientRequest->rep,
                    fn () => __('field.notif_client_rejected_title', ['name' => $clientRequest->name]),
                    fn () => $data['note'] ?? __('field.notif_client_rejected_body'),
                    false,
                );
            }

            $clientRequest->save();
        });

        return back()->with('ok', __('flash.decision_recorded'));
    }

    // ================= التراكينج =================

    public function tracking(Request $request)
    {
        $userId = $request->integer('user');
        $date = $request->date('date') ?? today();

        $q = TrackEvent::with('user')->whereDate('happened_at', $date);
        if ($userId) {
            $q->where('user_id', $userId);
        }

        return view('ops.tracking', [
            'events' => $q->orderByDesc('happened_at')->get(),
            'field' => User::whereIn('role', User::FIELD_ROLES)->get(),
            'userId' => $userId,
            'date' => $date->toDateString(),
        ]);
    }

    // ================= الفواتير =================

    public function invoices(Request $request)
    {
        $q = Invoice::with(['client', 'user']);
        if ($userId = $request->integer('user')) {
            $q->where('user_id', $userId);
        }
        if ($from = $request->string('from')->value()) {
            $q->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->string('to')->value()) {
            $q->whereDate('created_at', '<=', $to);
        }

        return view('ops.invoices', [
            'invoices' => $q->latest()->paginate(40)->withQueryString(),
            'field' => User::whereIn('role', User::FIELD_ROLES)->get(),
            'filters' => $request->only(['user', 'from', 'to']),
            'sum' => (clone $q)->sum('total'),
        ]);
    }

    public function invoice(Invoice $invoice)
    {
        abort_unless(
            request()->user()->canSeeBranch($invoice->client->branch_id), 403,
        );

        $invoice->load(['items.product', 'client', 'user', 'visit']);

        // ⚠️ الفاتورة ممكن تجمع نسب مختلفة (صنف بـ 14% وصنف معفى).
        // لو النسب اتعددت مانكتبش نسبة واحدة جنب سطر الضريبة — كتابة
        // نسبة واحدة على فاتورة مختلطة رقم غلط على مستند رسمي.
        $rates = $invoice->items
            ->filter(fn ($i) => (float) $i->tax > 0)
            ->pluck('tax_rate')->map(fn ($r) => round((float) $r, 4))->unique();

        return view('ops.invoice', [
            'inv' => $invoice,
            'taxRateLabel' => $rates->count() === 1 ? \App\Services\Tax::label($rates->first()) : '',
            'companyTaxId' => \App\Models\Setting::read('company_tax_id'),
        ]);
    }

    /** تسجيل تحصيل نقدي من عميل */
    public function collect(Request $request, Client $client)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'memo' => ['nullable', 'string', 'max:190'],
            'date' => ['nullable', 'date'],
        ]);

        Transaction::create([
            'client_id' => $client->id,
            'date' => $data['date'] ?? today(),
            'memo' => $data['memo'] ?? __('flash.memo_cash_collection'),
            'debit' => 0,
            'credit' => $data['amount'],
            'kind' => 'collection',
        ]);

        $client->recalculate();

        return back()->with('ok', __('flash.collection_recorded'));
    }
}
