<?php

namespace App\Http\Controllers;

use App\Models\OnlineCourier;
use App\Models\OnlineOrder;
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

        $qtyByProduct = [];

        foreach ($order->items as $item) {
            $qtyByProduct[$item->product_id] = ($qtyByProduct[$item->product_id] ?? 0) + (int) $item->qty;
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

        return back()->with('ok', __('online.confirmed', ['number' => $order->number]));
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

        return back()->with('ok', __('online.postponed_ok', ['number' => $order->number]));
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

        return back()->with('ok', __('online.cancelled_ok', ['number' => $order->number]));
    }

    // ==================== ٣. التجهيز ====================

    public function prep()
    {
        $picks = PickOrder::with(['items.product', 'items.batch', 'items.location', 'warehouse'])
            ->where('purpose', PickOrder::PURPOSE_ONLINE)
            ->whereIn('status', ['requested', 'picking', 'ready'])
            ->orderByRaw("CASE status WHEN 'picking' THEN 0 WHEN 'requested' THEN 1 ELSE 2 END")
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

            return redirect()->route('online.invoice', $order)
                ->with('ok', __('online.prep_done', ['number' => $order->number]));
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

        return redirect()->route('online.pickup', $pickup)
            ->with('ok', __('online.shipped_ok', ['number' => $pickup->number]));
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

    public function pickups()
    {
        $pickups = OnlinePickup::with(['courier', 'orders'])
            ->orderByDesc('date')->orderByDesc('id')
            ->paginate(30)->withQueryString();

        return view('online.pickups', ['pickups' => $pickups]);
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

            $remaining = round((float) $fresh->total - (float) $fresh->collected_total, 2);

            if ((float) $data['amount'] > $remaining + 0.009) {
                return __('online.collect_too_much', ['v' => number_format($remaining, 2)]);
            }

            $collected = round((float) $fresh->collected_total + (float) $data['amount'], 2);
            $done = $collected >= (float) $fresh->total - 0.009;

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

        return back()->with('ok', __('online.collected_ok', ['number' => $order->number]));
    }

    /** مرتجع بعد الشحن — البضاعة بترجع لنفس الرف والباتش */
    public function returnOrder(Request $request, OnlineOrder $order)
    {
        if ($order->status !== 'shipped') {
            return back()->withErrors(['order' => __('online.wrong_status')]);
        }

        // ⚠️ نفس قاعدة الإلغاء: عليه تحصيل مسجل = مايرجعش قبل ما
        // يتشاف موضوع الفلوس دي — وإلا بتختفي من حساب البيك اب
        if ((float) $order->collected_total > 0) {
            return back()->withErrors(['order' => __('online.has_money')]);
        }

        DB::transaction(function () use ($order) {
            $order->restock();
            $order->update(['status' => 'returned']);
        });

        return back()->with('ok', __('online.returned_ok', ['number' => $order->number]));
    }

    public function collections(Request $request)
    {
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
            'outstanding' => round((float) OnlineOrder::status('shipped')
                ->selectRaw('COALESCE(SUM(total - collected_total), 0) as v')->value('v'), 2),
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
        $sum = OnlineOrder::selectRaw("
            COALESCE(SUM(CASE WHEN status = 'shipped' THEN total - collected_total ELSE 0 END), 0) as outstanding,
            COALESCE(SUM(collected_total), 0) as collected,
            COALESCE(SUM(CASE WHEN status = 'returned' THEN total ELSE 0 END), 0) as returned_amount,
            COALESCE(SUM(CASE WHEN status IN ('ready','shipped','completed') THEN shipping ELSE 0 END), 0) as shipping_sum,
            COALESCE(SUM(CASE WHEN status IN ('ready','shipped','completed') THEN cost_total ELSE 0 END), 0) as cost_sum,
            COALESCE(SUM(CASE WHEN status IN ('ready','shipped','completed') THEN total ELSE 0 END), 0) as live_amount,
            COALESCE(SUM(CASE WHEN status = 'completed' THEN total ELSE 0 END), 0) as completed_amount,
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
        ]);

        $changed = 0;
        $pushErrors = [];

        foreach ($data['links'] as $linkId => $productId) {
            $link = ShopifyProductLink::find((int) $linkId);

            if ($link === null) {
                continue;
            }

            $productId = $productId !== null ? (int) $productId : null;
            $currentId = $link->product_id !== null ? (int) $link->product_id : null;

            // ⚠️ مفيش تغيير = تخطّي — شامل «فاضي فضل فاضي» (مراجعة
            // ٣/٩: كل حفظة كانت بتعيد كتابة الصفوف الفاضية وتعدّهم)
            if ($currentId === $productId) {
                continue;
            }

            $link->update(['product_id' => $productId]);
            $changed++;

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
}
