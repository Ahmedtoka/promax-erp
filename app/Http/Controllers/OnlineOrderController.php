<?php

namespace App\Http\Controllers;

use App\Models\OnlineCourier;
use App\Models\OnlineOrder;
use App\Models\OnlineOrderItem;
use App\Models\OnlinePickup;
use App\Models\PickOrder;
use App\Models\Product;
use App\Models\Setting;
use App\Models\ShopifyProductLink;
use App\Models\Warehouse;
use App\Services\Pricing;
use App\Services\ShopifyOnline;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ═══ موديول الأونلاين — أوردرات شوبيفاي (٣/٩/٢٠٢٦) ═══
 *
 * الفلو بالترتيب (صفحة لكل خطوة، زي ما المالك رسمه):
 *   sync        السينك: الجديد والمؤجل — التيم بيكلم العملاء
 *               (تأكيد → أمر تجهيز · تأجيل بتاريخ · إلغاء)
 *   prep        تجهيز أوردرات الأونلاين — مكان لوحده لأمين المخزن
 *   invoice     فاتورة الطباعة بباركود pro{رقم الأوردر}
 *   ready       جاهزة للشحن: سيلكت/مسدس → بيك اب
 *   pickups     شيتات البيك اب اليومية بأرصدتها
 *   collections تحصيل المشحون — كل أوردر برقم بيك ابه
 *   orders      كل الأوردرات بكل الحالات
 *   accounts    سامريهات الحسابات
 *   products    ربط منتجات شوبيفاي بمنتجات السيستم
 *
 * ⚠️ البضاعة بتتحرك بأمر تجهيز FEFO من مخزن الأونلاين (المعادي) —
 *   مش زيادة/نقصان يدوي. المرتجع والإلغاء بعد الخروج بيرجّعوا لنفس
 *   الرف ونفس الباتش عبر restock().
 */
class OnlineOrderController extends Controller
{
    // ==================== ١. السينك ====================

    public function sync(Request $request)
    {
        $orders = OnlineOrder::with('items.product')
            ->whereIn('status', ['new', 'postponed'])
            // المؤجل اللي جه يومه بيطفو فوق، وبعده الأجدد
            // ⚠️ التاريخ من PHP مش CURDATE() — عقيدة التايم زون:
            // MySQL ممكن يبقى على توقيت مختلف حوالين نص الليل
            ->orderByRaw(
                "CASE WHEN status = 'postponed' AND postponed_to <= ? THEN 0 ELSE 1 END",
                [today()->toDateString()],
            )
            ->orderByDesc('ordered_at')
            ->paginate(50)->withQueryString();

        return view('online.sync', [
            'orders' => $orders,
            'ready' => ShopifyOnline::ready(),
            // للربط اليدوي للبند — أوردر قديم SKUه فاضي وفاريانته
            // مش في جدول الربط مالوش غير الطريق ده
            'products' => Product::where('active', true)
                ->orderBy('code')->get(['id', 'code', 'name', 'name_en']),
            'warehouses' => Warehouse::orderBy('name')->get(['id', 'name', 'name_en']),
            'settings' => Setting::all_(),
            'counts' => [
                'new' => OnlineOrder::status('new')->count(),
                'postponed' => OnlineOrder::status('postponed')->count(),
                'due_today' => OnlineOrder::status('postponed')
                    ->whereDate('postponed_to', '<=', today())->count(),
            ],
        ]);
    }

    /** حفظ إعدادات شوبيفاي — أدمن بس (الراوت متحرس) */
    public function saveSettings(Request $request)
    {
        $data = $request->validate([
            'shopify_domain' => ['nullable', 'string', 'max:190'],
            'shopify_admin_token' => ['nullable', 'string', 'max:250'],
            'shopify_api_version' => ['nullable', 'string', 'max:20'],
            'online_warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
        ]);

        $pairs = [
            'shopify_domain' => trim($data['shopify_domain'] ?? ''),
            'shopify_api_version' => trim($data['shopify_api_version'] ?? '') ?: '2025-01',
            'online_warehouse_id' => (string) ($data['online_warehouse_id'] ?? ''),
        ];

        // ⚠️ التوكن بيتحدّث بس لو اتكتب — الخانة بتتعرض فاضية دايماً
        // عشان مايتطبعش في الـHTML، وإرسالها فاضية مش مسح
        if (trim($data['shopify_admin_token'] ?? '') !== '') {
            $pairs['shopify_admin_token'] = trim($data['shopify_admin_token']);
        }

        Setting::writeMany($pairs);

        return back()->with('ok', __('online.settings_saved'));
    }

    /** زرار السينك — يجيب كل اللي متعملوش سينك */
    public function runSync()
    {
        $result = ShopifyOnline::syncOrders();

        if ($result['error'] !== null) {
            return back()->withErrors(['sync' => $result['error']]);
        }

        // ⚠️ السينك بيعيد مطابقة البنود الفاضية كمان (٣/٩ مساءً):
        // أوردر اتسينك **قبل** ما الربط يتعمل كان بيفضل «مش مربوط»
        // للأبد إلا لو المالك افتكر يدوس «احفظ الربط» تاني — دلوقتي
        // أي سينك بيلمّ اللي اتربط بعد وصوله.
        ShopifyOnline::rematchUnlinked();

        // أوردرات اتعدّت لبايلود غريب — نبلّغ بدل ما نبلع
        if (! empty($result['failed'])) {
            return back()->with('ok', __('online.synced', ['n' => $result['created']]))
                ->withErrors(['sync' => __('online.sync_skipped', [
                    'list' => implode(' · ', array_slice($result['failed'], 0, 5)),
                ])]);
        }

        return back()->with('ok', __('online.synced', ['n' => $result['created']]));
    }

    // ==================== ٢. أكشنات المكالمة ====================

    /**
     * «أوردر مؤكد» — بينزّل أمر تجهيز في مخزن الأونلاين برقم الأوردر.
     * البضاعة **مابتخرجش هنا** — بتخرج لما أمين المخزن يدوس «تم
     * التجهيز» (markReady بالـFEFO).
     */
    public function confirm(Request $request, OnlineOrder $order)
    {
        if (! in_array($order->status, ['new', 'postponed'], true)) {
            return back()->withErrors(['order' => __('online.wrong_status')]);
        }

        $warehouse = Warehouse::find((int) Setting::read('online_warehouse_id'));

        if ($warehouse === null) {
            return back()->withErrors(['order' => __('online.no_warehouse')]);
        }

        $order->load('items');

        // ⚠️ بند مش مربوط بمنتج = مفيش تأكيد. أمر تجهيز ناقص صنف
        // معناه شحنة ناقصة وعميل زعلان — الربط الأول من شاشة المنتجات.
        if ($order->hasUnmatchedItems()) {
            return back()->withErrors(['order' => __('online.unmatched_items', [
                'number' => $order->number,
            ])]);
        }

        // ⚠️ بالقطع مش بالبنود: فاريانت «12 قطعة» كمية 2 = 24 قطعة
        // من منتج السيستم تتجهز وتتخصم (units_per من شاشة الربط)
        $qtyByProduct = [];

        foreach ($order->items as $item) {
            $qtyByProduct[$item->product_id] = ($qtyByProduct[$item->product_id] ?? 0) + $item->pieces();
        }

        // ⚠️ **الادعاء الذري الأول** (مراجعة ٣/٩): ضغطتين تأكيد ورا
        // بعض كانوا بيعدوا فحص الحالة الاتنين ويرفعوا أمرين تجهيز —
        // والأمر اليتيم كان ممكن يتجهز ويخرّج البضاعة مرتين. الـUPDATE
        // المشروط بيكسبه واحد بس، والخسران بياخد «الحالة مش بتسمح».
        $prev = $order->status;

        $claimed = OnlineOrder::whereKey($order->id)
            ->whereIn('status', ['new', 'postponed'])
            ->update(['status' => 'preparing']);

        if ($claimed === 0) {
            return back()->withErrors(['order' => __('online.wrong_status')]);
        }

        // رقم أمر التجهيز بنفس رقم أوردر شوبيفاي (قرار المالك) —
        // ولو الأوردر اتلغى واتأكد تاني بنلحق برقم تكرار
        $number = 'ON-'.$order->number;

        while (PickOrder::where('number', $number)->exists()) {
            $number .= 'R';
        }

        $result = PickOrder::raise(
            warehouse: $warehouse,
            rep: $request->user(),
            qtyByProduct: $qtyByProduct,
            purpose: PickOrder::PURPOSE_ONLINE,
            requestedBy: $request->user(),
            extra: ['number' => $number],
        );

        if ($result['error'] !== null) {
            // فشل الرفع (نقص مخزون مثلاً) — نرجّع الادعاء لحالته
            OnlineOrder::whereKey($order->id)->update(['status' => $prev]);

            return back()->withErrors(['order' => $result['error']]);
        }

        $order->update([
            'status' => 'preparing',
            'pick_order_id' => $result['order']->id,
            'confirmed_by' => $request->user()->id,
            'confirmed_at' => now(),
            'postponed_to' => null,
        ]);

        // «الابديت يسمع في شوبيفاي» — تاج pmx-preparing على الأوردر
        $warn = ShopifyOnline::pushStatus($order->fresh());

        return $this->okWithPushWarn(__('online.confirmed', ['number' => $order->number]), $warn);
    }

    /** «كلمت العميل — أجّل» */
    public function postpone(Request $request, OnlineOrder $order)
    {
        if (! in_array($order->status, ['new', 'postponed'], true)) {
            return back()->withErrors(['order' => __('online.wrong_status')]);
        }

        $data = $request->validate([
            'postponed_to' => ['required', 'date', 'after_or_equal:today'],
        ]);

        $order->update([
            'status' => 'postponed',
            'postponed_to' => $data['postponed_to'],
        ]);

        $warn = ShopifyOnline::pushStatus($order->fresh());

        return $this->okWithPushWarn(__('online.postponed_ok', ['number' => $order->number]), $warn);
    }

    /**
     * إلغاء — من أي مرحلة قبل التمام. لو البضاعة كانت خرجت
     * (ready/shipped) بترجع لنفس الرف والباتش، ولو أمر التجهيز لسه
     * شغال بيتلغي.
     */
    public function cancel(Request $request, OnlineOrder $order)
    {
        if (in_array($order->status, ['completed', 'cancelled', 'returned'], true)) {
            return back()->withErrors(['order' => __('online.wrong_status')]);
        }

        // ⚠️ أوردر اتقبض منه فلوس مايتلغيش (مراجعة ٣/٩) — الفلوس
        // المسجلة كانت هتختفي من حساب البيك اب في صمت.
        if ((float) $order->collected_total > 0) {
            return back()->withErrors(['order' => __('online.has_money')]);
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:250'],
        ]);

        DB::transaction(function () use ($order, $data) {
            $pick = $order->pickOrder;

            if (in_array($order->status, OnlineOrder::STOCK_OUT, true)) {
                // البضاعة برة — ترجع المخزن
                $order->restock();
                $pick?->update(['status' => 'cancelled']);
            } elseif ($pick !== null && in_array($pick->status, ['requested', 'picking'], true)) {
                // لسه بتتجهز — مفيش بضاعة خرجت، الأمر بيتلغي وخلاص
                $pick->update(['status' => 'cancelled']);
            }

            $order->update([
                'status' => 'cancelled',
                'cancel_reason' => $data['reason'],
            ]);
        });

        // إلغاء حقيقي في شوبيفاي + تاج pmx-cancelled — بعد نجاح
        // الإلغاء المحلي، وفشله هناك بيتبلّغ ومايرجّعش حاجة هنا
        $warn = ShopifyOnline::cancelInShopify($order->fresh());

        return $this->okWithPushWarn(__('online.cancelled_ok', ['number' => $order->number]), $warn);
    }

    // ==================== ٣. التجهيز ====================

    public function prep()
    {
        // ⚠️ المطلوب تجهيزه **بس** (قرار المالك ٤/٩) — اللي خلص بيختفي
        // من هنا وبيبان في «جاهزة للشحن»
        $picks = PickOrder::with(['items.product', 'items.batch', 'items.location', 'warehouse'])
            ->where('purpose', PickOrder::PURPOSE_ONLINE)
            ->whereIn('status', ['requested', 'picking'])
            ->orderByRaw("CASE status WHEN 'picking' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->get();

        // أوردرات الأونلاين بتوع الأوامر دي — بمفتاح pick_order_id
        $orders = OnlineOrder::whereIn('pick_order_id', $picks->pluck('id'))
            ->get()->keyBy('pick_order_id');

        return view('online.prep', ['picks' => $picks, 'orders' => $orders]);
    }

    public function prepStart(Request $request, PickOrder $pick)
    {
        if ($pick->purpose !== PickOrder::PURPOSE_ONLINE) {
            abort(404);
        }

        if ($err = $pick->startPicking($request->user())) {
            return back()->withErrors(['prep' => $err]);
        }

        return back()->with('ok', __('online.prep_started'));
    }

    /**
     * «تم التجهيز» — هنا البضاعة بتخرج فعلاً (FEFO) والأوردر بيبقى
     * جاهز للشحن، وبنصوّر تكلفة البضاعة من باتشات الخروج نفسها.
     */
    public function prepDone(Request $request, PickOrder $pick)
    {
        if ($pick->purpose !== PickOrder::PURPOSE_ONLINE) {
            abort(404);
        }

        if ($err = $pick->markReady($request->user())) {
            return back()->withErrors(['prep' => $err]);
        }

        $order = OnlineOrder::where('pick_order_id', $pick->id)->first();

        if ($order !== null) {
            // التكلفة من الباتشات اللي البضاعة خرجت منها فعلاً —
            // Pricing::costFor بياخد تكلفة الباتش لو > 0 وإلا المنتج
            $cost = 0.0;

            foreach ($pick->fresh(['items.product', 'items.batch'])->items as $item) {
                if ($item->product !== null) {
                    $cost += (int) $item->qty_picked * Pricing::costFor($item->product, $item->batch);
                }
            }

            $order->update([
                'status' => 'ready',
                'ready_at' => now(),
                'cost_total' => round($cost, 2),
            ]);

            $warn = ShopifyOnline::pushStatus($order->fresh());

            $redirect = redirect()->route('online.invoice', $order)
                ->with('ok', __('online.prep_done', ['number' => $order->number]));

            return $warn !== null ? $redirect->withErrors(['push' => $warn]) : $redirect;
        }

        return back()->with('ok', __('online.prep_done', ['number' => $pick->number]));
    }

    /** فاتورة الطباعة — بيانات العميل + البنود + باركود pro{number} */
    public function invoice(OnlineOrder $order)
    {
        $order->load('items.product');

        return view('online.invoice', [
            'order' => $order,
            'barcode' => \App\Support\Code128::svg($order->barcode(), 70, 2),
            'header' => Setting::docHeader(),
        ]);
    }

    // ==================== ٤. جاهزة للشحن → بيك اب ====================

    public function readyList()
    {
        return view('online.ready', [
            'orders' => OnlineOrder::status('ready')->orderBy('ready_at')->get(),
            'couriers' => OnlineCourier::where('active', true)->orderBy('name')->get(),
        ]);
    }

    /** «اشحن واعمل البيك اب» — شيت جديد بالمحدد */
    public function ship(Request $request)
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'courier_id' => ['required', 'integer', 'exists:online_couriers,id'],
        ]);

        $pickup = DB::transaction(function () use ($data, $request) {
            $orders = OnlineOrder::whereIn('id', $data['ids'])
                ->where('status', 'ready')
                ->lockForUpdate()->get();

            if ($orders->isEmpty()) {
                return null;
            }

            $pickup = OnlinePickup::create([
                'number' => OnlinePickup::nextNumber(),
                'date' => today(),
                'courier_id' => $data['courier_id'],
                'created_by' => $request->user()->id,
            ]);

            OnlineOrder::whereIn('id', $orders->pluck('id'))->update([
                'pickup_id' => $pickup->id,
                'status' => 'shipped',
                'shipped_at' => now(),
            ]);

            return $pickup;
        });

        if ($pickup === null) {
            return back()->withErrors(['ship' => __('online.nothing_to_ship')]);
        }

        // ═══ «الأوردر بيتقلب Fulfilled أول ما يطلع بيه شيت بيك اب»
        // (قرار المالك ٥/٩) — فلفلمنت حقيقي + التاج، بعد نجاح الشحن
        // محلياً. الفشل بيتبلّغ ومايرجّعش الشحنة.
        $warns = 0;

        foreach ($pickup->orders()->get() as $o) {
            if (ShopifyOnline::fulfillOrder($o) !== null) {
                $warns++;
            }

            ShopifyOnline::pushStatus($o);
        }

        $redirect = redirect()->route('online.pickup', $pickup)
            ->with('ok', __('online.shipped_ok', ['number' => $pickup->number]));

        return $warns > 0
            ? $redirect->withErrors(['push' => __('online.push_failed_n', ['n' => $warns])])
            : $redirect;
    }

    /** إضافة مندوب أونلاين — من مودال صفحة الجاهزة */
    public function courierStore(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);

        OnlineCourier::create($data + ['active' => true]);

        return back()->with('ok', __('online.courier_added'));
    }

    // ==================== ٥. البيك اب والتحصيل ====================

    public function pickups(Request $request)
    {
        $q = OnlinePickup::with(['courier', 'creator', 'orders']);

        // بحث شامل (٤/٩): رقم أوردر / اسم عميل / موبايل → كل
        // البيك ابات اللي فيها أوردر مطابق
        $q->when($request->filled('search'), function ($x) use ($request) {
            $s = '%'.trim($request->input('search')).'%';
            $x->whereHas('orders', function ($w) use ($s) {
                $w->where('number', 'like', $s)
                    ->orWhere('customer_name', 'like', $s)
                    ->orWhere('phone', 'like', $s);
            });
        });

        return view('online.pickups', [
            'pickups' => $q->orderByDesc('date')->orderByDesc('id')
                ->paginate(30)->withQueryString(),
            'search' => trim((string) $request->input('search')),
        ]);
    }

    /**
     * شيت البيك اب إكسيل (٤/٩) — بنفس رايتر الكوتيشن (SheetWriter):
     * رأس بالشيت والمندوب واللي عمله، صف لكل أوردر بفصل مبلغ
     * البضاعة عن الشحن عن الإجمالي، وصف إجماليات تحت.
     */
    public function pickupExcel(OnlinePickup $pickup)
    {
        $pickup->load(['courier', 'creator', 'orders']);
        $t = $pickup->totals();

        $x = new \App\Services\SheetWriter(__('online.pickup_no').' '.$pickup->number);

        foreach ([26, 22, 15, 16, 16, 34, 8, 12, 10, 12, 12, 12] as $i => $w) {
            $x->width($i, $w);
        }

        $x->row([['v' => __('online.pickup_no').' '.$pickup->number, 'style' => 'title']]);
        $x->merge(0, 11);
        $x->row([
            ['v' => __('common.date').': '.$pickup->date->format('Y-m-d'), 'style' => 'label'],
            '', '',
            ['v' => __('online.courier').': '.($pickup->courier?->name ?: '-'), 'style' => 'label'],
            '', '',
            ['v' => __('online.by_user').': '.($pickup->creator?->displayName() ?: '-'), 'style' => 'label'],
        ]);
        $x->blank();

        $x->row([
            ['v' => __('online.shopify_no'), 'style' => 'header'],
            ['v' => __('common.name'), 'style' => 'header'],
            ['v' => __('common.phone'), 'style' => 'header'],
            ['v' => __('online.rcpt_gov'), 'style' => 'header'],
            ['v' => __('online.rcpt_area'), 'style' => 'header'],
            ['v' => __('online.rcpt_addr'), 'style' => 'header'],
            ['v' => __('online.pieces'), 'style' => 'header'],
            ['v' => __('online.goods_amount'), 'style' => 'header'],
            ['v' => __('online.shipping'), 'style' => 'header'],
            ['v' => __('common.total'), 'style' => 'header'],
            ['v' => __('online.collected'), 'style' => 'header'],
            ['v' => __('common.status'), 'style' => 'header'],
        ]);

        foreach ($pickup->orders as $o) {
            $parts = array_map('trim', explode(' - ', (string) $o->area, 2));

            $x->row([
                ['v' => '#'.$o->number, 'style' => 'value'],
                ['v' => $o->customer_name ?: '-'],
                ['v' => $o->phone ?: '-'],
                ['v' => $parts[1] ?? '-'],
                ['v' => $parts[0] ?? '-'],
                ['v' => $o->address ?: '-'],
                ['v' => (int) $o->items_count, 'style' => 'center'],
                ['v' => (float) $o->subtotal, 'style' => 'money'],
                ['v' => (float) $o->shipping, 'style' => 'money'],
                ['v' => (float) $o->total, 'style' => 'money_bold'],
                ['v' => (float) $o->collected_total, 'style' => 'money'],
                ['v' => $o->statusLabel()],
            ]);
        }

        $x->row([
            ['v' => __('common.total'), 'style' => 'total'],
            ['v' => '', 'style' => 'total'], ['v' => '', 'style' => 'total'],
            ['v' => '', 'style' => 'total'], ['v' => '', 'style' => 'total'],
            ['v' => '', 'style' => 'total'],
            ['v' => $t['pieces'], 'style' => 'total'],
            ['v' => $t['goods'], 'style' => 'total'],
            ['v' => $t['ship'], 'style' => 'total'],
            ['v' => $t['amount'], 'style' => 'total'],
            ['v' => $t['collected'], 'style' => 'total'],
            ['v' => '', 'style' => 'total'],
        ]);

        return $x->download($pickup->number.'.xlsx');
    }

    public function pickupShow(OnlinePickup $pickup)
    {
        $pickup->load(['courier', 'creator', 'orders.items.product']);

        return view('online.pickup', ['pickup' => $pickup, 'totals' => $pickup->totals()]);
    }

    /** تحصيل فلوس أوردر — باقي البيك اب بيقل لحد التصفية */
    public function collect(Request $request, OnlineOrder $order)
    {
        if (! in_array($order->status, ['shipped', 'completed'], true)) {
            return back()->withErrors(['collect' => __('online.wrong_status')]);
        }

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        // ⚠️ فحص «الباقي» **جوه القفل** (مراجعة ٣/٩): الفحص قبل القفل
        // كان بيسمح لضغطتين متزامنتين يعدوا الاتنين ويسجلوا التحصيل
        // مرتين — الرقم النهائي بيبقى ضعف الأوردر.
        $err = DB::transaction(function () use ($order, $data) {
            $fresh = OnlineOrder::whereKey($order->id)->lockForUpdate()->first();

            // ⚠️ التحصيل على **البضاعة بس** (− المرتجع) — الشحن بتاع المندوب
            $target = round((float) $fresh->subtotal - (float) $fresh->returned_total, 2);
            $remaining = round($target - (float) $fresh->collected_total, 2);

            if ((float) $data['amount'] > $remaining + 0.009) {
                return __('online.collect_too_much', ['v' => number_format($remaining, 2)]);
            }

            $collected = round((float) $fresh->collected_total + (float) $data['amount'], 2);
            $done = $collected >= $target - 0.009;

            $fresh->update([
                'collected_total' => $collected,
                'status' => $done ? 'completed' : $fresh->status,
                'collected_at' => $done ? now() : $fresh->collected_at,
            ]);

            return null;
        });

        if ($err !== null) {
            return back()->withErrors(['collect' => $err]);
        }

        // «بيتقلب Paid أول ما يتحصّل» (٥/٩) — Mark as paid حقيقي + التاج
        $fresh = $order->fresh();
        $warn = null;

        if ($fresh->status === 'completed') {
            $warn = ShopifyOnline::markPaid($fresh);
            ShopifyOnline::pushStatus($fresh);
        }

        return $this->okWithPushWarn(__('online.collected_ok', ['number' => $order->number]), $warn);
    }

    /**
     * ═══ مرتجع بعد الشحن — كامل أو **جزئي بالكميات** (٥/٩) ═══
     *
     * الفورم بيبعت items[بند] = عدد الباكات الراجعة (نفس وحدة شوبيفاي).
     * البضاعة بترجع لنفس الرف والباتش بالقطع (باكات × قطع الباك)،
     * وقيمة الراجع بتتخصم من المستهدف تحصيله، وفي شوبيفاي بيتعمل
     * Return حقيقي فالأوردر بياخد Returned أو Partially returned.
     */
    public function returnOrder(Request $request, OnlineOrder $order)
    {
        if ($order->status !== 'shipped') {
            return back()->withErrors(['order' => __('online.wrong_status')]);
        }

        $data = $request->validate([
            'items' => ['required', 'array'],
            'items.*' => ['nullable', 'integer', 'min:0'],
        ]);

        $shopifyQty = [];   // [shopify_line_id => باكات] للريتيرن هناك

        $err = DB::transaction(function () use ($order, $data, &$shopifyQty) {
            $fresh = OnlineOrder::whereKey($order->id)->lockForUpdate()->first();
            $fresh->load(['items', 'pickOrder.items']);

            $value = 0.0;
            $moves = [];   // [product_id => قطع ترجع]

            foreach ($fresh->items as $item) {
                $qty = (int) ($data['items'][$item->id] ?? 0);

                if ($qty <= 0) {
                    continue;
                }

                $max = (int) $item->qty - (int) $item->returned_qty;

                if ($qty > $max) {
                    return __('online.return_over', ['n' => $max]);
                }

                // قيمة الباك = إجمالي البند ÷ كميته (بعد خصم شوبيفاي)
                $value += round(((float) $item->total / max((int) $item->qty, 1)) * $qty, 2);

                if ($item->product_id !== null) {
                    $pieces = $qty * max((int) $item->units_per, 1);
                    $moves[$item->product_id] = ($moves[$item->product_id] ?? 0) + $pieces;
                }

                $item->update(['returned_qty' => (int) $item->returned_qty + $qty]);

                if ($item->shopify_line_id !== null) {
                    $shopifyQty[(int) $item->shopify_line_id] = $qty;
                }
            }

            if (empty($moves) && $value <= 0) {
                return __('online.return_none');
            }

            $newReturned = round((float) $fresh->returned_total + $value, 2);

            // ⚠️ المتحصّل مايزيدش عن المستهدف الجديد — وإلا فلوس مسجلة
            // تبقى من غير مقابل بضاعة
            if ((float) $fresh->collected_total > (float) $fresh->subtotal - $newReturned + 0.009) {
                return __('online.return_money_clash');
            }

            // رجوع القطع لنفس الرف والباتش — بنمشي على بنود أمر التجهيز
            // بتاعة نفس المنتج وبننقص qty_picked بقد ما رجع، فالإلغاء
            // الكامل بعدين بيرجّع الباقي بس
            foreach ($moves as $productId => $need) {
                foreach ($fresh->pickOrder?->items ?? [] as $pi) {
                    if ($need <= 0 || (int) $pi->product_id !== (int) $productId) {
                        continue;
                    }

                    $take = min($need, (int) $pi->qty_picked);

                    if ($take > 0) {
                        $pi->returnToShelf($take);
                        $pi->update(['qty_picked' => (int) $pi->qty_picked - $take]);
                        $need -= $take;
                    }
                }
            }

            // كله رجع؟ → الأوردر «رجع». جزء؟ → لسه «اتشحن» والباقي بيتحصّل
            $allBack = $fresh->items->every(
                fn ($i) => (int) $i->returned_qty >= (int) $i->qty,
            );

            $fresh->update([
                'returned_total' => $newReturned,
                'status' => $allBack ? 'returned' : 'shipped',
            ]);

            return null;
        });

        if ($err !== null) {
            return back()->withErrors(['order' => $err]);
        }

        // ريتيرن حقيقي في شوبيفاي (كامل/جزئي بالكميات) + التاج
        $fresh = $order->fresh();
        $warn = ShopifyOnline::createReturn($fresh, $shopifyQty);
        ShopifyOnline::pushStatus($fresh,
            $fresh->status === 'returned' ? null : 'pmx-partial-return');

        return $this->okWithPushWarn(__('online.returned_ok', ['number' => $order->number]), $warn);
    }

    public function collections(Request $request)
    {
        // ⚠️ تطبيع (٥/٩): أوردر متحصّل منه تمن البضاعة كامل تحت
        // القاعدة القديمة (اللي كانت بتستنى الشحن كمان) بيتقفل
        // «كامل» هنا — من غيره كان هيفضل معلق بباقي صفر للأبد
        // وزرار التحصيل مايقدرش يضيف صفر.
        OnlineOrder::where('status', 'shipped')
            ->where('subtotal', '>', 0)
            ->whereRaw('collected_total >= subtotal - returned_total')
            ->update(['status' => 'completed', 'collected_at' => now()]);

        $q = OnlineOrder::with('pickup.courier')
            ->where('status', 'shipped');

        $q->when($request->filled('search'), function ($x) use ($request) {
            $s = '%'.$request->input('search').'%';
            $x->where(function ($w) use ($s) {
                $w->where('number', 'like', $s)
                    ->orWhere('customer_name', 'like', $s)
                    ->orWhere('phone', 'like', $s);
            });
        });

        return view('online.collections', [
            'orders' => $q->orderByDesc('shipped_at')->paginate(50)->withQueryString(),
            // بره = تمن البضاعة (− المرتجع) الغير محصّل — الشحن للمندوب
            'outstanding' => round((float) OnlineOrder::status('shipped')
                ->selectRaw('COALESCE(SUM(subtotal - returned_total - collected_total), 0) as v')->value('v'), 2),
        ]);
    }

    // ==================== ٦. كل الأوردرات + الحسابات ====================

    public function orders(Request $request)
    {
        $q = OnlineOrder::with(['pickup', 'confirmer']);

        $q->when($request->filled('search'), function ($x) use ($request) {
            $s = '%'.$request->input('search').'%';
            $x->where(function ($w) use ($s) {
                $w->where('number', 'like', $s)
                    ->orWhere('customer_name', 'like', $s)
                    ->orWhere('phone', 'like', $s)
                    ->orWhere('area', 'like', $s);
            });
        });

        // ⚠️ العدادات قبل فلتر الحالة — شيبس الحالات بتعدّ جوه البحث
        // بس، وإلا اختيار حالة كان بيصفّر عدادات الباقي
        $counts = (clone $q)->reorder()
            ->selectRaw('status, COUNT(*) as n')->groupBy('status')
            ->pluck('n', 'status');

        $q->when($request->filled('status'),
            fn ($x) => $x->where('status', $request->input('status')));

        return view('online.orders', [
            'orders' => $q->orderByDesc('ordered_at')->paginate(50)->withQueryString(),
            'counts' => $counts,
            'filters' => $request->only(['status', 'search']),
        ]);
    }

    /**
     * حسابات الأونلاين — سامريهات الإي كومرس:
     * بره كام (مشحون لسه ماتحصلش) · اتحصل كام · رجع كام · شحن كام ·
     * تكلفة البضاعة كام · هامش المحصّل.
     */
    public function accounts()
    {
        // كل الأرقام من كويري تجميع واحدة لكل نطاق — مش لوب صفوف
        // ⚠️ «فلوس بره» = تمن البضاعة بس — الشحن للمندوب (٥/٩)
        $sum = OnlineOrder::selectRaw("
            COALESCE(SUM(CASE WHEN status = 'shipped' THEN subtotal - returned_total - collected_total ELSE 0 END), 0) as outstanding,
            COALESCE(SUM(collected_total), 0) as collected,
            COALESCE(SUM(returned_total), 0) as returned_amount,
            COALESCE(SUM(CASE WHEN status IN ('ready','shipped','completed') THEN shipping ELSE 0 END), 0) as shipping_sum,
            COALESCE(SUM(CASE WHEN status IN ('ready','shipped','completed') THEN cost_total ELSE 0 END), 0) as cost_sum,
            COALESCE(SUM(CASE WHEN status IN ('ready','shipped','completed') THEN total ELSE 0 END), 0) as live_amount,
            COALESCE(SUM(CASE WHEN status = 'completed' THEN subtotal - returned_total ELSE 0 END), 0) as completed_amount,
            COALESCE(SUM(CASE WHEN status = 'completed' THEN cost_total ELSE 0 END), 0) as completed_cost
        ")->first();

        $counts = OnlineOrder::selectRaw('status, COUNT(*) as n, COALESCE(SUM(total), 0) as v')
            ->groupBy('status')->get()->keyBy('status');

        $openPickups = OnlinePickup::with('orders')->orderByDesc('date')->get()
            ->filter(fn ($p) => ! $p->isSettled())->values();

        return view('online.accounts', [
            'sum' => $sum,
            'counts' => $counts,
            'openPickups' => $openPickups,
            'statuses' => array_keys(OnlineOrder::STATUSES),
        ]);
    }

    // ==================== ٧. ربط المنتجات ====================

    public function products(Request $request)
    {
        $q = ShopifyProductLink::with('product');

        $q->when($request->filled('search'), function ($x) use ($request) {
            $s = '%'.$request->input('search').'%';
            $x->where(fn ($w) => $w->where('title', 'like', $s)->orWhere('sku', 'like', $s));
        })->when($request->boolean('unlinked'), fn ($x) => $x->whereNull('product_id'));

        return view('online.products', [
            'links' => $q->orderBy('title')->orderBy('id')->paginate(100)->withQueryString(),
            'products' => Product::where('active', true)
                ->orderBy('code')->get(['id', 'code', 'name', 'name_en']),
            'unlinkedCount' => ShopifyProductLink::whereNull('product_id')->count(),
            'filters' => $request->only(['search', 'unlinked']),
        ]);
    }

    /** زرار «هات المنتجات من شوبيفاي» */
    public function productsFetch()
    {
        $result = ShopifyOnline::fetchProducts();

        if ($result['error'] !== null) {
            return back()->withErrors(['products' => $result['error']]);
        }

        return back()->with('ok', __('online.products_fetched', ['n' => $result['fetched']]));
    }

    /**
     * حفظ الربط — وكتابة كود المنتج SKU في شوبيفاي كمان (قرار
     * المالك ٣/٩). فشل الكتابة على فاريانت مايوقفش الباقي — بيتجمع
     * ويتعرض في alert واحد.
     */
    public function productsSave(Request $request)
    {
        $data = $request->validate([
            'links' => ['required', 'array'],
            'links.*' => ['nullable', 'integer', 'exists:products,id'],
            // قطع الباك: فاريانت الـ«pcs 12» = 12 قطعة من منتج السيستم
            'units' => ['nullable', 'array'],
            'units.*' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $changed = 0;
        $pushErrors = [];
        $changedVariants = [];   // shopify_variant_id => [product_id, units]

        foreach ($data['links'] as $linkId => $productId) {
            $link = ShopifyProductLink::find((int) $linkId);

            if ($link === null) {
                continue;
            }

            $productId = $productId !== null ? (int) $productId : null;
            $currentId = $link->product_id !== null ? (int) $link->product_id : null;
            $units = max((int) ($data['units'][$linkId] ?? $link->units ?? 1), 1);

            // ⚠️ مفيش تغيير = تخطّي — شامل «فاضي فضل فاضي» (مراجعة
            // ٣/٩: كل حفظة كانت بتعيد كتابة الصفوف الفاضية وتعدّهم)
            if ($currentId === $productId && (int) $link->units === $units) {
                continue;
            }

            $link->update(['product_id' => $productId, 'units' => $units]);
            $changed++;

            if ($productId !== null) {
                $changedVariants[(int) $link->shopify_variant_id] = [$productId, $units];
            }

            // كتابة الكود كـSKU في شوبيفاي — بس لو فيه منتج مربوط
            if ($productId !== null) {
                $code = Product::find($productId)?->code;

                if ($code !== null && $code !== '' && $code !== $link->sku) {
                    if ($err = ShopifyOnline::pushSku($link, $code)) {
                        $pushErrors[] = $link->title.': '.$err;
                    }
                }
            }
        }

        // ⚠️ بنود الأوردرات **المفتوحة** بتاخد الربط والباك الجديدين —
        // المؤكد وما بعده سنابشوت مقفول (البضاعة اتحسبت واتخصمت)
        if (! empty($changedVariants)) {
            $touched = [];

            OnlineOrderItem::whereIn('shopify_variant_id', array_keys($changedVariants))
                ->whereHas('order', fn ($q) => $q->whereIn('status', ['new', 'postponed']))
                ->get()
                ->each(function ($item) use ($changedVariants, &$touched) {
                    [$pid, $units] = $changedVariants[(int) $item->shopify_variant_id];
                    $item->update(['product_id' => $pid, 'units_per' => $units]);
                    $touched[$item->online_order_id] = true;
                });

            foreach (array_keys($touched) as $orderId) {
                $order = OnlineOrder::with('items')->find($orderId);
                $order?->update([
                    'items_count' => $order->items->sum(fn ($i) => $i->pieces()),
                ]);
            }
        }

        // البنود الفاضية في الأوردرات المفتوحة بتتربط بأثر رجعي
        $rematched = ShopifyOnline::rematchUnlinked();

        if (! empty($pushErrors)) {
            return back()->withErrors(['products' => __('online.sku_push_failed', [
                'n' => count($pushErrors),
            ]).' — '.implode(' · ', array_slice($pushErrors, 0, 3))]);
        }

        return back()->with('ok', __('online.links_saved', [
            'n' => $changed, 'm' => $rematched,
        ]));
    }

    /**
     * ═══ ربط بند يدوي من صفحة السينك (٣/٩ مساءً) ═══
     *
     * الأوردرات القديمة بتيجي من شوبيفاي بصورة وقت الطلب: SKU فاضي
     * وvariant_id ممكن يكون لفاريانت اتغيّر — فالمطابقة الأوتوماتيك
     * بطريقيها بتفشل. الزرار ده بيربط البند مباشرة، ولو البند شايل
     * variant_id بيتسجل في جدول الربط كمان فالأوردرات الجاية بنفس
     * الفاريانت تتربط لوحدها.
     */
    public function itemLink(Request $request, OnlineOrderItem $item)
    {
        $order = $item->order;

        if ($order === null || ! in_array($order->status, ['new', 'postponed'], true)) {
            return back()->withErrors(['order' => __('online.wrong_status')]);
        }

        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'units' => ['required', 'integer', 'min:1', 'max:1000'],
        ]);

        $item->update([
            'product_id' => (int) $data['product_id'],
            'units_per' => (int) $data['units'],
        ]);

        // عدد قطع الأوردر بيتحسب تاني بالباك الجديد
        $order->load('items');
        $order->update(['items_count' => $order->items->sum(fn ($i) => $i->pieces())]);

        // تعليم الفاريانت للمستقبل — لو البند أصلاً شايل variant_id
        if ($item->shopify_variant_id !== null) {
            ShopifyProductLink::updateOrCreate(
                ['shopify_variant_id' => $item->shopify_variant_id],
                [
                    // 0 = فاريانت معروف من أوردر مش من جلب المنتجات —
                    // الجلب الجاي بيكمّل بياناته لو لسه موجود في المتجر
                    'shopify_product_id' => ShopifyProductLink::where('shopify_variant_id', $item->shopify_variant_id)
                        ->value('shopify_product_id') ?? 0,
                    'title' => mb_substr($item->title, 0, 250),
                    'sku' => $item->sku,
                    'product_id' => (int) $data['product_id'],
                    'units' => (int) $data['units'],
                ],
            );
        }

        return back()->with('ok', __('online.item_linked', ['number' => $order->number]));
    }

    // ==================== ٨. تصفير التيست ====================

    /**
     * ═══ «زرار يرجع كل حاجة من الأول» (قرار المالك ٣/٩) ═══
     *
     * للتجربة على الأوردرات القديمة: بيمسح **كل** بيانات الموديول —
     * الأوردرات وبنودها، شيتات البيك اب، وأوامر تجهيز الأونلاين —
     * وأي بضاعة كانت خرجت بترجع لنفس الرف والباتش الأول. أول سينك
     * بعده بينزّل كل حاجة من شوبيفاي زي ما هي (since_id بيرجع صفر).
     *
     * بيسيب: ربط المنتجات + مناديب الأونلاين + الإعدادات — دول
     * تعريفات مش ترانزاكشنات.
     *
     * ⚠️ أدمن بس + تأكيد بكتابة الكلمة. وبيحاول يمسح تاجات pmx- من
     * شوبيفاي للأوردرات اللي اتلمست (أفضل جهد — فشله مايوقفش المسح).
     * ⚠️ اللي اتلغى في شوبيفاي نفسها أثناء التيست هيرجع «ملغي» —
     * الإلغاء هناك حقيقي ومفيش un-cancel في الـAPI.
     */
    public function resetTest(Request $request)
    {
        $request->validate(['confirm_word' => ['required', 'in:RESET,reset']]);

        // تنضيف تاجات pmx- في شوبيفاي — قبل المسح وأفضل جهد
        OnlineOrder::whereNotNull('shopify_id')
            ->where('status', '!=', 'new')
            ->limit(200)->get()
            ->each(fn ($o) => ShopifyOnline::pushStatus($o, ''));

        $stats = DB::transaction(function () {
            // ١) أي بضاعة برة ترجع لرفها وباتشها الأول
            $restocked = 0;

            OnlineOrder::whereIn('status', OnlineOrder::STOCK_OUT)
                ->with('pickOrder.items')->get()
                ->each(function ($o) use (&$restocked) {
                    $o->restock();
                    $restocked++;
                });

            // ٢) المسح — الأوردرات (البنود cascade) ← الشيتات ←
            //    أوامر تجهيز الأونلاين بس (بنودها cascade)
            $orders = OnlineOrder::query()->delete();
            OnlinePickup::query()->delete();
            PickOrder::where('purpose', PickOrder::PURPOSE_ONLINE)->delete();

            return ['orders' => $orders, 'restocked' => $restocked];
        });

        return back()->with('ok', __('online.reset_done', [
            'n' => $stats['orders'], 'm' => $stats['restocked'],
        ]));
    }

    /** فلاش نجاح + تحذير دفع شوبيفاي لو فيه — من غير ما يبوّظ الفلو */
    private function okWithPushWarn(string $ok, ?string $warn)
    {
        $redirect = back()->with('ok', $ok);

        return $warn !== null ? $redirect->withErrors(['push' => $warn]) : $redirect;
    }
}
