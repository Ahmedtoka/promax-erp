<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\StockShortage;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientRequest;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PurchaseOrder;
use App\Models\TrackEvent;
use App\Models\Transaction;
use App\Models\Visit;
use App\Models\Zone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * الـ API بتاع الموبايل أبلكيشن — كاش فان وكورير
 */
class FieldApiController extends Controller
{
    // ================= بوت ستراب: كل اللي الأبلكيشن محتاجه في ريكوست واحد =================

    public function bootstrap(Request $request): JsonResponse
    {
        $user = $request->user();
        $custody = $user->todayCustody();
        $custody?->load('items.product');

        return response()->json([
            'user' => [
                'id' => $user->id, 'name' => $user->displayName(), 'code' => $user->code,
                'role' => $user->role, 'role_label' => $user->roleLabel(),
                'zone' => $user->zone?->displayName(),
                // الأبلكيشن بيظبط لغته من هنا — نفس لغة الإشعارات
                // ⚠️ نفس السبب: الافتراضي بييجي من إعدادات السيستم.
                'locale' => $user->locale ?: config('app.locale'),
            ],
            // السواق بيشوف عهدته بسعر القائمة القديم والسيلز بالجديد
            'custody' => $this->custodyPayload($custody, $user->isDriver() ? 'old' : 'new'),
            'zones' => $user->isSalesAgent() ? $this->zonesPayload($user) : [],
            // ⚠️ السيلز بقى بيشوف أوامر التوريد برضو — فلو الكي أكاونت
            // (2026-08-04): أمر معتمد من الحسابات واتجهز بينزله يسلمه.
            'purchase_orders' => ($user->isDriver() || $user->isSalesAgent())
                ? $this->posPayload($user) : [],
            'today' => $this->todayPayload($user),
            'notifications' => $user->appNotifications()->take(20)->get()->map(fn ($n) => [
                'id' => $n->id, 'title' => $n->title, 'body' => $n->body,
                'is_good' => $n->is_good, 'time' => $n->created_at->toIso8601String(),
            ]),
            // ⚠️ خطة اليوم بتيجي مع البوت ستراب مش في ريكوست منفصل —
            // المندوب بيفتح الأبلكيشن على شبكة موبايل ضعيفة، وكل
            // ريكوست زيادة معناه ثواني استنى في أول الشغل.
            'journey' => $this->journeyPayload($user),
            'events' => $this->eventsPayload($user),
            'client_requests' => ClientRequest::where('created_by', $user->id)
                ->latest()->take(20)->get()->map(fn ($r) => [
                    'id' => $r->id, 'number' => $r->number, 'name' => $r->name,
                    'status' => $r->status, 'status_label' => $r->statusLabel(),
                    'time' => $r->created_at->toIso8601String(),
                ]),
        ]);
    }

    private function custodyPayload($custody, string $mode): array
    {
        if (! $custody) {
            return ['exists' => false, 'items' => []];
        }

        return [
            'exists' => true,
            'id' => $custody->id,
            'date' => $custody->date->toDateString(),
            'status' => $custody->status,
            'remaining_units' => $custody->remainingUnits(),
            'remaining_value' => round($custody->remainingValue($mode), 2),
            'assigned_value' => round($custody->assignedValue($mode), 2),
            // إجمالي مرتجع العملاء في العربية — معروض مفصول عن المتاح
            'returned_in_units' => (int) $custody->items->sum(fn ($i) => (int) ($i->returned_in ?? 0)),
            'items' => $custody->items->map(fn ($i) => [
                'product_id' => $i->product_id,
                'code' => $i->product->code,
                'name' => $i->product->displayName(),
                // ⚠️ الاسمين الاتنين — البحث في شاشة البيع بيلاقي
                // «برو» عربي أو إنجليزي مهما كانت لغة الواجهة
                'name_ar' => $i->product->name,
                'name_en' => $i->product->name_en,
                'image' => $i->product->imageSrc(),
                'unit' => $i->product->unitLabel(),
                // تدريج الوحدات — الأبلكيشن بيعرض ويبعت اسم الوحدة،
                // والضرب للقطع بيحصل هنا في السيرفر وقت البيع/المرتجع
                'box_units' => (int) $i->product->box_units,
                'case_units' => (int) $i->product->units_per_case,
                'price' => (float) $i->product->priceFor($mode),
                'assigned' => $i->assigned,
                'sold' => $i->sold,
                'remaining' => $i->remaining(),
                // مرتجع العملاء — بضاعة راجعة في العربية، **مش للبيع**
                'returned_in' => (int) ($i->returned_in ?? 0),
                // ⚠️ الأبلكيشن محتاج يعرض الضريبة **قبل** ما يحفظ —
                // المندوب بيقول للعميل الرقم وبيحصّله. عرض الصافي
                // معناه إنه بيحصّل ناقص قيمة الضريبة في كل بيعة.
                'taxable' => (bool) ($i->product->taxable ?? true),
                'tax_rate' => round((float) ($i->product->tax_rate ?? 0), 4),
            ])->values(),
        ];
    }

    private function zonesPayload($user): array
    {
        // ⚠️ **مناطقه هو وبس.** كانت بترجّع كل مناطق الشركة بكل
        // عملائها — مندوب المعادي كان بيشوف عملاء الإسكندرية بأرصدتهم
        // وخصومهم. المناطق من شاشة التوزيع (`zone_user`)، ولو لسه
        // ماتوزّعش بياخد منطقته الأساسية (`zone_id`) لحد ما يتسكّن.
        $zoneIds = $user->zones()->pluck('zones.id');

        if ($zoneIds->isEmpty() && $user->zone_id) {
            $zoneIds = collect([$user->zone_id]);
        }

        // ⚠️ **مناطق عملائه كمان، مش التسكين بس.** المدير ساعات بيسكّن
        // العملاء (rep_id) من غير ما يعلّم على تشيك بوكس المناطق —
        // فالمندوب كان بيفتح «المناطق» يلاقيها فاضية وعملاؤه موجودين
        // فعلاً. أي منطقة فيها عميل بتاعه هي منطقته بحكم الواقع.
        $clientZoneIds = Client::where('rep_id', $user->id)
            ->where('status', 'active')
            ->whereNotNull('zone_id')
            ->distinct()->pluck('zone_id');

        $zoneIds = $zoneIds->merge($clientZoneIds)->unique()->values();

        $zones = Zone::with([
            'clients' => function ($q) use ($user) {
                // ⚠️ contract و group.contract ضروريين: effectiveDiscount()
                // بتنادي liveContract() لكل عميل. من غيرهم ~300 كويري زيادة
                // على /api/home وهو أكتر إندبوينت بيتنادى في الأبلكيشن.
                $q->where('status', 'active')
                    ->with(['channel', 'contract', 'group.contract'])
                    ->orderBy('name');
                // ⚠️ **عملاءه هو دايماً** (مهما كانت قناتهم)، واللي لسه
                // من غير مندوب — دول بس بيتفلتروا بقناته لو ليه قناة.
                $q->where(function ($w) use ($user) {
                    $w->where('rep_id', $user->id);
                    $w->orWhere(function ($w2) use ($user) {
                        $w2->whereNull('rep_id');
                        if ($user->channel_id) {
                            $w2->where('channel_id', $user->channel_id);
                        }
                    });
                });
            },
        ])->whereIn('id', $zoneIds)->where('active', true)->orderBy('code')->get();

        $todayVisits = Visit::where('user_id', $user->id)
            ->whereDate('created_at', today())->get()->keyBy('client_id');

        // ⚠️ **المناطق اللي فيها شغل ليه بس** (قرار المالك 2026-08-03).
        // التسكين ممكن يكون على ٢٠ منطقة والعملاء في ٤ — عرض الفاضي
        // زحمة بلا فايدة. أول ما يتسكن عليه عميل في منطقة هتظهر لوحدها.
        $zones = $zones->filter(fn ($z) => $z->clients->isNotEmpty())->values();

        return $zones->map(fn ($z) => [
            'id' => $z->id,
            'code' => $z->code,
            'name' => $z->displayName(),
            'day' => $z->day_label,
            'is_today' => $user->zone_id === $z->id,
            'clients' => $z->clients->map(function ($c) use ($todayVisits) {
                $v = $todayVisits->get($c->id);

                // ⚠️ الاسم الكامل «السلسلة — الفرع» زي الـERP بالظبط —
                // «Katameya Heights» لوحدها ماتقولش إنه فرع جورميه.
                // والسلسلة والفرع مفصولين كمان للشاشات اللي بتعرضهم
                // سطرين (زي اختيار مستلم الهدية).
                $chain = $c->group?->displayName();
                $chain = ($chain && $chain !== $c->displayName()) ? $chain : null;

                return [
                    'id' => $c->id,
                    'name' => $c->fullName(),
                    'chain' => $chain,
                    'branch' => $c->displayName(),
                    'address' => $c->address,
                    'phone' => $c->phone,
                    'category' => $c->category,
                    'category_label' => $c->categoryLabel(),
                    'balance' => (float) $c->balance,
                    'purchases' => (float) $c->purchases,
                    'discount' => $c->effectiveDiscount(),
                    'discount_source' => $c->discountSource(),
                    'channel' => $c->channel?->displayName(),
                    'cash_only' => $c->cashOnly(),
                    // كاش/آجل — قرار الأدمن؛ الأبلكيشن بيعرضها ومابيسألش
                    'payment_terms' => $c->paymentTerms(),
                    'is_new' => $c->is_new,
                    'taxable' => (bool) $c->taxable,
                    'tax_rate' => \App\Services\Tax::rate($c),
                    'tax_on' => \App\Services\Tax::enabled(),
                    'visit_status' => $v === null ? 'pending' : ($v->isOpen() ? 'in_visit' : 'done'),
                    'visit_id' => $v?->id,
                    'checked_in_at' => $v?->checked_in_at?->toIso8601String(),
                    'checked_out_at' => $v?->checked_out_at?->toIso8601String(),
                ];
            })->values(),
        ])->values()->all();
    }

    private function posPayload($user): array
    {
        return PurchaseOrder::with(['client', 'items.product'])
            ->where('assigned_to', $user->id)
            ->whereIn('status', ['pending', 'arrived', 'delivered'])
            // ⚠️ **أمر الموافقة مايظهرش غير معتمد.** pending موافقة =
            // الحسابات ممكن ترفضه — المندوب مايشوفوش أصلاً.
            // null = الفلو القديم (سواق/ريفيل) زي ما هو.
            ->where(fn ($q) => $q->whereNull('approval_status')->orWhere('approval_status', 'approved'))
            // أوامر الكي أكاونت ليها معاد مستقبلي — مانقصرش عليها الـ3 أيام
            ->where(fn ($q) => $q->whereDate('created_at', '>=', today()->subDays(3))
                ->orWhere(fn ($qq) => $qq->whereNotNull('due_at')->where('status', '!=', 'delivered')))
            ->latest()->get()->map(fn ($po) => [
                'id' => $po->id,
                'number' => $po->number,
                'client' => $po->client->fullName(),
                'source' => $po->sourceLabel(),
                'address' => $po->address,
                'status' => $po->status,
                'status_label' => $po->statusLabel(),
                // ⚠️ الرقم اللي السواق بيوريه للعميل ويحصّله — شامل الضريبة
                'total' => $po->payable(),
                'net_total' => (float) $po->total,
                'tax_total' => (float) $po->tax_total,
                'qty_total' => $po->qtyTotal(),
                'arrived_at' => $po->arrived_at?->toIso8601String(),
                'delivered_at' => $po->delivered_at?->toIso8601String(),
                // معاد التوريد بالساعة + متأخر — لأوامر الكي أكاونت
                'due_at' => $po->due_at?->toIso8601String(),
                'late' => $po->isLate(),
                'delivered_qty_total' => (int) $po->deliveredQtyTotal(),
                'items' => $po->items->map(fn ($i) => [
                    'item_id' => $i->id,
                    'product_id' => $i->product_id,
                    'name' => $i->product->displayName(),
                    'unit' => $i->product->unitLabel(),
                    'image' => $i->product->imageSrc(),
                    'qty' => $i->qty,
                    'delivered_qty' => (int) $i->delivered_qty,
                    // تدريج الوحدات — عشان المندوب يعدل «9 كراتين» وقت التسليم
                    'box_units' => (int) $i->product->box_units,
                    'case_units' => (int) $i->product->units_per_case,
                    'price' => (float) $i->price,
                    'total' => (float) $i->total,
                ])->values(),
            ])->values()->all();
    }

    /**
     * خطة سير النهارده.
     *
     * ⚠️ الترتيب من `sort` — الشاشة بتوري العملاء بالترتيب ده والمندوب
     * بيمشي عليه، فأي إعادة ترتيب في الـ ERP لازم توصله زي ما هي.
     */
    private function journeyPayload($user): array
    {
        // ⚠️ مرة واحدة — `summary()` كانت بتعيد حساب نفس الخطة،
        // فكل `/api/bootstrap` كان بيعمل الشغل مرتين على شبكة موبايل.
        $rows = \App\Services\Journeys::forDay($user);

        // ⚠️ الخصم بيتبعت مع المحطة — من غيره العميل اللي مش في زونز
        // المندوب (المدير حاطه في الخطة) كان بيتسعّر في الشاشة بصفر
        // خصم، والمندوب يقول للعميل رقم أعلى من الفاتورة الفعلية.
        // `loadMissing` دفعة واحدة — مش كويريين لكل محطة.
        \Illuminate\Database\Eloquent\Collection::make($rows->pluck('client'))
            ->unique('id')->values()->loadMissing(['contract', 'group.contract']);

        // آخر زيارة لكل عميل — كويري واحد مجمّع مش كويري لكل محطة
        $lastVisits = Visit::whereIn('client_id', $rows->pluck('client')->pluck('id'))
            ->selectRaw('client_id, MAX(checked_in_at) as t')
            ->groupBy('client_id')
            ->pluck('t', 'client_id');

        $done = $rows->where('status', 'done')->count();
        $planned = $rows->count();

        return [
            'summary' => [
                'planned' => $planned,
                'done' => $done,
                'in_visit' => $rows->where('status', 'in_visit')->count(),
                'pending' => $rows->where('status', 'pending')->count(),
                'off_plan' => \App\Services\Journeys::offPlan($user, null, $rows)->count(),
                'pct' => $planned > 0 ? round($done / $planned * 100, 1) : 0.0,
            ],
            'stops' => $rows->map(fn ($r) => [
                'plan_id' => $r['plan']->id,
                'client_id' => $r['client']->id,
                // الاسم الكامل — السلسلة الأول وبعدين الفرع
                'name' => $r['client']->fullName(),
                'address' => $r['client']->address,
                'phone' => $r['client']->phone,
                'lat' => $r['client']->lat !== null ? (float) $r['client']->lat : null,
                'lng' => $r['client']->lng !== null ? (float) $r['client']->lng : null,
                'balance' => (float) $r['client']->balance,
                'cash_only' => $r['client']->cashOnly(),
                'payment_terms' => $r['client']->paymentTerms(),
                'discount' => $r['client']->effectiveDiscount(),
                // كارت المحطة بيوري تاريخ العميل: مبيعاته وآخر مرة اتزار
                'purchases' => (float) $r['client']->purchases,
                'last_visit_at' => $lastVisits->get($r['client']->id),
                'taxable' => (bool) $r['client']->taxable,
                'tax_rate' => \App\Services\Tax::rate($r['client']),
                'category' => $r['client']->category,
                'category_label' => $r['client']->categoryLabel(),
                'status' => $r['status'],
                'visit_id' => $r['visit']?->id,
                'sort' => $r['sort'],
            ])->values()->all(),
        ];
    }

    /** GET /api/journey — خطة السير لوحدها (للريفريش) */
    public function journey(Request $request): JsonResponse
    {
        return response()->json($this->journeyPayload($request->user()));
    }

    private function todayPayload($user): array
    {
        return [
            'sales' => (float) Invoice::where('user_id', $user->id)->whereDate('created_at', today())->sum('total'),
            'invoices' => Invoice::where('user_id', $user->id)->whereDate('created_at', today())->count(),
            'visits' => Visit::where('user_id', $user->id)->whereDate('created_at', today())->count(),
            'visits_done' => Visit::where('user_id', $user->id)->whereDate('created_at', today())
                ->whereNotNull('checked_out_at')->count(),
            'pos_delivered' => PurchaseOrder::where('assigned_to', $user->id)
                ->where('status', 'delivered')->whereDate('delivered_at', today())->count(),
            'pos_value' => (float) PurchaseOrder::where('assigned_to', $user->id)
                ->where('status', 'delivered')->whereDate('delivered_at', today())->sum('grand_total'),
        ];
    }

    private function eventsPayload($user): array
    {
        return TrackEvent::where('user_id', $user->id)
            ->whereDate('happened_at', today())
            ->orderBy('happened_at')->get()->map(fn ($e) => [
                'type' => $e->type,
                'title' => $e->title,
                'subtitle' => $e->subtitle,
                'lat' => (float) $e->lat,
                'lng' => (float) $e->lng,
                'time' => $e->happened_at->toIso8601String(),
            ])->values()->all();
    }

    // ================= الزيارات =================

    /** POST /api/visits/check-in { client_id, lat, lng } */
    public function checkIn(Request $request): JsonResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'lat' => ['nullable', 'numeric'],
            'lng' => ['nullable', 'numeric'],
        ]);

        $user = $request->user();

        if ($open = $user->openVisit()) {
            return response()->json([
                'message' => __('field.must_check_out_first', ['client' => $open->client->displayName()]),
            ], 422);
        }

        $client = Client::find($data['client_id']);

        // ⚠️ الزيارة بتتربط بخطة اليوم لو العميل ده فيها. من غير
        // الربط ده الشاشة اللايف مش هتفرّق بين زيارة من الخطة وزيارة
        // بره الخطة، ونسبة الإنجاز هتبقى رقم بلا معنى.
        $plan = \App\Models\JourneyPlan::where('user_id', $user->id)
            ->where('client_id', $client->id)
            ->where('weekday', today()->dayOfWeek)
            ->where('active', true)
            ->value('id');

        $visit = Visit::create([
            'user_id' => $user->id,
            'client_id' => $client->id,
            'journey_plan_id' => $plan,
            'checked_in_at' => now(),
            'lat' => $data['lat'] ?? null,
            'lng' => $data['lng'] ?? null,
        ]);

        TrackEvent::log($user, 'check_in',
            __('field.event_check_in', ['client' => $client->displayName()]), $client->address,
            $data['lat'] ?? null, $data['lng'] ?? null);

        return response()->json(['visit_id' => $visit->id, 'checked_in_at' => $visit->checked_in_at->toIso8601String()]);
    }

    /** POST /api/visits/{visit}/check-out */
    public function checkOut(Request $request, Visit $visit): JsonResponse
    {
        if ($visit->user_id !== $request->user()->id) {
            return response()->json(['message' => __('api.not_your_visit')], 403);
        }
        if (! $visit->isOpen()) {
            return response()->json(['message' => __('field.visit_already_closed')], 422);
        }

        $visit->update(['checked_out_at' => now()]);

        TrackEvent::log($request->user(), 'check_out',
            __('field.event_check_out', ['client' => $visit->client->displayName()]),
            __('field.event_visit_minutes', ['minutes' => $visit->minutes()]),
            $visit->lat, $visit->lng);

        return response()->json(['minutes' => $visit->minutes()]);
    }

    // ================= الفواتير =================

    /** POST /api/invoices { client_id, visit_id, payment, items: [{product_id, qty}] } */
    public function storeInvoice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'visit_id' => ['nullable', 'exists:visits,id'],
            // ⚠️ بيتقبل من نسخ قديمة بس **بيتطنش** — شوف تحت
            'payment' => ['nullable', 'in:cash,credit'],
            'lat' => ['nullable', 'numeric'],
            'lng' => ['nullable', 'numeric'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.unit' => ['nullable', 'in:piece,box,case'],
        ]);

        $user = $request->user();
        $client = Client::findOrFail($data['client_id']);
        $custody = $user->todayCustody();

        if (! $custody) {
            return response()->json(['message' => __('field.no_custody_today')], 422);
        }

        // ⚠️ **كاش/آجل من تعريف العميل مش من المندوب** (قرار المالك
        // 2026-08-03). اللي الأبلكيشن بيبعته بيتطنش — توكن معدّل كان
        // يقدر يبعت `credit` لعميل كاش ويفتح مديونية محدش قررها.
        $data['payment'] = $client->paymentTerms();

        // ⚠️ **وحدة البيع بتتضرب هنا مش في الأبلكيشن** — التفاصيل في itemsToPieces. «2 كرتونة»
        // بتتحول 24 قطعة قبل الخصم من العهدة والتسعير — والسعر سعر
        // القطعة × العدد (قرار المالك 2026-08-04). وحدة مش معرّفة
        // للصنف = رفض الفاتورة كلها، مش افتراض قطعة.
        if ($err = $this->itemsToPieces($data['items'])) {
            return $err;
        }

        $qtyByProduct = [];
        foreach ($data['items'] as $i) {
            $qtyByProduct[$i['product_id']] = ($qtyByProduct[$i['product_id']] ?? 0) + $i['qty'];
        }

        // ⚠️ الخصم من العهدة لازم يبقى جوه نفس الترانزاكشن بتاعة الفاتورة.
        // لو كان بره وحصل خطأ في إنشاء الفاتورة، العهدة تتخصم من غير فاتورة
        // والمندوب يخسر بضاعة على الورق.
        try {
            $invoice = DB::transaction(function () use ($data, $user, $client, $custody, $qtyByProduct) {
                // الخصم بالـ FEFO — بيرجّع كل بند بالباتش اللي خرج منه
                $deduction = $custody->deductWithBatches($qtyByProduct);
                if ($deduction['error']) {
                    throw new StockShortage($deduction['error']);
                }

                $subtotal = 0;   // قبل الخصم — بسعر القائمة
                $net = 0;        // بعد الخصم — ده اللي العميل بيدفعه
                $costTotal = 0;
                $rows = [];
                $priceList = $client->priceList();

                // سطر فاتورة لكل باتش — لو الكمية اتاخدت من باتشين يبقى سطرين،
                // وده المقصود عشان نقدر نتتبع أي شحنة راحت لأي عميل
                foreach ($deduction['lines'] as $line) {
                    /** @var \App\Models\CustodyItem $item */
                    $item = $line['item'];
                    $qty = (int) $line['qty'];

                    // التسعير كله من Pricing: قائمة العميل، خصمه، وتكلفة الباتش.
                    // بنخزّن اللقطة على السطر عشان الربحية التاريخية ماتتأثرش
                    // بأي تعديل سعر أو تكلفة بعد كده.
                    $quote = \App\Services\Pricing::quote($client, $item->product, $item->batch, $qty);

                    // ⚠️ **سعر القايمة صفر = بيع مرفوض.** الصنف اللي مش
                    // متسعّر في قايمة العميل كان بيعدّي ويطلع سطر فاتورة
                    // بـ0.00 من غير أي رسالة — بضاعة بتخرج ببلاش والرقم
                    // مابيبانش غير في مراجعة آخر الشهر.
                    if ($quote['list_price'] <= 0) {
                        throw new \App\Exceptions\Rejected(__('api.product_not_priced', [
                            'product' => $item->product->displayName(),
                        ]));
                    }

                    // ⚠️ الضريبة على الصافي **بعد الخصم**، وسطر بسطر —
                    // الفاتورة ممكن تجمع صنف خاضع وصنف معفى.
                    $taxRate = \App\Services\Tax::rate($client, $item->product);
                    $lineTax = \App\Services\Tax::on($quote['line_total'], $client, $item->product);

                    $rows[] = [
                        'product_id' => $item->product_id,
                        'batch_id' => $item->batch_id,
                        'qty' => $qty,
                        'list_price' => $quote['list_price'],
                        'price' => $quote['unit_price'],
                        'unit_cost' => $quote['unit_cost'],
                        'total' => $quote['line_total'],
                        'tax_rate' => $taxRate,
                        'tax' => $lineTax,
                    ];

                    // ⚠️ subtotal = قبل الخصم (سعر القائمة)، و net = بعده.
                    // unit_price اللي جوه الـ quote **مخصوم أصلاً**، فممنوع
                    // نخصم تاني على الإجمالي — ده كان بيخصم مرتين.
                    $subtotal += round($quote['list_price'] * $qty, 2);
                    $net += $quote['line_total'];
                    $costTotal += $quote['line_cost'];
                }

                $discPct = $client->effectiveDiscount();
                $discount = round($subtotal - $net, 2);

                // ⚠️ الضريبة بتتجمّع من السطور، مش بضرب الإجمالي في نسبة —
                // السطور ممكن تكون بنسب مختلفة أو فيها صنف معفى.
                $sums = \App\Services\Tax::totals($rows);
                $net = $sums['net'];
                $taxTotal = $sums['tax'];
                $grandTotal = $sums['grand'];

                $invoice = Invoice::create([
                    'number' => Invoice::nextNumber(),
                    'client_id' => $client->id,
                    'user_id' => $user->id,
                    'visit_id' => $data['visit_id'] ?? null,
                    'payment' => $data['payment'],
                    'price_list' => $priceList,
                    'subtotal' => $subtotal,
                    'discount_pct' => $discPct,
                    'discount_source' => $client->discountSourceKey(),
                    'discount' => $discount,
                    // ⚠️ `total` = صافي المبيعات قبل الضريبة. كل تقرير في
                    // السيستم بيجمعه وبيقصد بيه المبيعات. اللي العميل بيدفعه
                    // هو `grand_total`، وهو **الوحيد** اللي بيتقيّد في الليدجر.
                    'total' => $net,
                    'tax_total' => $taxTotal,
                    'grand_total' => $grandTotal,
                    'eta_status' => $taxTotal > 0 ? 'ready' : 'none',
                    'cost_total' => round($costTotal, 2),
                    'lat' => $data['lat'] ?? null,
                    'lng' => $data['lng'] ?? null,
                ]);

                foreach ($rows as $r) {
                    InvoiceItem::create($r + ['invoice_id' => $invoice->id]);
                }

                // ⚠️ عقد الأمانة: البضاعة بتروح الفرع وتفضل ملك بروماكس
                // لحد ما تتباع. فالقيد بيتسجل بصفر مدين ونوع consignment —
                // بيفضل في كشف الحساب كأثر، بس مايزوّدش الرصيد. المديونية
                // بتتولد بعدين من تقرير مبيعات الفرع.
                $consigned = $client->isConsignment();

                Transaction::create([
                    'client_id' => $client->id,
                    'date' => today(),
                    'memo' => $consigned
                        ? __('flash.memo_consignment', [
                            'number' => $invoice->number,
                            'amount' => number_format($grandTotal),
                        ])
                        : __('flash.memo_invoice', [
                            'number' => $invoice->number,
                            'user' => $user->displayName(),
                        ]),
                    // ⚠️ المديونية بالإجمالي **شامل الضريبة** — ده اللي
                    // العميل بيدفعه فعلاً. القيد بالصافي بيسيب فرق الضريبة
                    // مالوش مقابل في كشف الحساب.
                    'debit' => $consigned ? 0 : $grandTotal,
                    'credit' => 0,
                    // ⚠️ نصيب الضريبة من القيد. عمولات العقود بتطرحه
                    // عشان تتحسب على الصافي — من غيره العمولة بتزيد
                    // بنسبة الضريبة وده كاش بيخرج فعلاً.
                    'tax' => $consigned ? 0 : $taxTotal,
                    'kind' => $consigned ? 'consignment' : 'sale',
                    'source_type' => Invoice::class,
                    'source_id' => $invoice->id,
                ]);

                // لو كاش: قيد تحصيل مقابل (دائن) — الرصيد يفضل صفر.
                // في الأمانة مفيش مديونية أصلاً فمفيش تحصيل مقابلها.
                if ($data['payment'] === 'cash' && ! $consigned) {
                    Transaction::create([
                        'client_id' => $client->id,
                        'date' => today(),
                        'memo' => __('flash.memo_cash_with_invoice', ['number' => $invoice->number]),
                        'debit' => 0,
                        // التحصيل بالإجمالي عشان الرصيد يرجع صفر بالظبط
                        'credit' => $grandTotal,
                        'tax' => $taxTotal,
                        'kind' => 'collection',
                        'source_type' => Invoice::class,
                        'source_id' => $invoice->id,
                    ]);
                }

                $client->recalculate();

                TrackEvent::log($user, 'sale',
                    __('field.event_invoice', ['number' => $invoice->number, 'client' => $client->displayName()]),
                    __('common.money', ['amount' => number_format($grandTotal)]),
                    $data['lat'] ?? null, $data['lng'] ?? null);

                return $invoice;
            });
        } catch (\App\Exceptions\Rejected $e) {
            // رفض تجاري (نقص عهدة، صنف مش متسعّر…) — الترانزاكشن
            // اترجعت والعهدة زي ما هي. StockShortage وريثة Rejected
            // فبتتلقف هنا برضه. أي خطأ تاني (SQL مثلاً) بيكمّل لـ500
            // عن قصد.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $invoice->load('items.product');

        return response()->json([
            'invoice' => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'subtotal' => (float) $invoice->subtotal,
                'discount' => (float) $invoice->discount,
                'total' => (float) $invoice->total,
                'tax_total' => (float) $invoice->tax_total,
                // ⚠️ الأبلكيشن بيوري ده للعميل ويحصّله — لازم يبقى الإجمالي
                // شامل الضريبة مش الصافي، وإلا المندوب بيحصّل ناقص.
                'grand_total' => (float) $invoice->grand_total,
                'payment' => $invoice->payment,
                'time' => $invoice->created_at->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * POST /api/returns { client_id, visit_id, items: [{product_id, qty}] }
     *
     * مرتجع من العميل (قرار المالك 2026-08-03):
     *   - قيد `return` **دائن** على العميل بسعره الفعلي (قايمته وخصمه
     *     وضريبته) — بيقلل مديونيته
     *   - البضاعة بتدخل العربية في `custody_items.returned_in` —
     *     **مفصولة تماماً** عن المتاح للبيع وعن مرتجع المخزن
     */
    public function storeReturn(Request $request): JsonResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            // ⚠️ **الزيارة إجبارية** — هي المرساة المادية للمرتجع.
            // من غيرها أي توكن كان يقدر يكتب قيد دائن بلا حدود على
            // أي عميل في الشركة ويمسح مديونيته.
            'visit_id' => ['required', 'exists:visits,id'],
            'lat' => ['nullable', 'numeric'],
            'lng' => ['nullable', 'numeric'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:9999'],
            'items.*.unit' => ['nullable', 'in:piece,box,case'],
        ]);

        // وحدة المرتجع → قطع، في السيرفر — نفس قاعدة البيع،
        // والسقف 9999 **قطعة** بعد التحويل (المرتجع من غير حارس مخزون)
        if ($err = $this->itemsToPieces($data['items'], 9999)) {
            return $err;
        }

        $user = $request->user();
        $client = Client::findOrFail($data['client_id']);
        $custody = $user->todayCustody();

        // ⚠️ من غير عهدة مفيش مكان البضاعة تتحط فيه — المرتجع بيتسجل
        // على عربية، مش في الهوا
        if (! $custody) {
            return response()->json(['message' => __('field.no_custody_today')], 422);
        }

        // زيارته هو، مفتوحة، وعلى نفس العميل — نفس منطق التشيك إن
        $visit = Visit::find($data['visit_id']);

        if ($visit === null || $visit->user_id !== $user->id) {
            return response()->json(['message' => __('api.not_your_visit')], 403);
        }
        if (! $visit->isOpen() || $visit->client_id !== $client->id) {
            return response()->json(['message' => __('api.return_needs_open_visit')], 422);
        }

        // ⚠️ عميل الأمانة بضاعته أصلاً ملك بروماكس ومفيش مديونية
        // بيع تتخصم — مرتجعه بيتسوى من تقرير مبيعات الفرع مش من هنا
        if ($client->isConsignment()) {
            return response()->json(['message' => __('api.return_consignment')], 422);
        }

        $qtyByProduct = [];
        foreach ($data['items'] as $i) {
            $qtyByProduct[$i['product_id']] = ($qtyByProduct[$i['product_id']] ?? 0) + $i['qty'];
        }

        try {
            $result = DB::transaction(function () use ($data, $user, $client, $custody, $qtyByProduct) {
                $net = 0.0;
                $taxTotal = 0.0;
                $lines = [];

                foreach ($qtyByProduct as $productId => $qty) {
                    $product = \App\Models\Product::findOrFail($productId);

                    // نفس تسعير البيع بالظبط — قايمة العميل وخصمه.
                    // ⚠️ الصنف الغير متسعّر مرفوض هنا برضه: مرتجع بصفر
                    // بيدخل بضاعة من غير ما يقلل مديونية، ومرتجع بسعر
                    // مخمّن بيقلل مديونية برقم محدش اعتمده.
                    $quote = \App\Services\Pricing::quote($client, $product, null, $qty);

                    if ($quote['list_price'] <= 0) {
                        throw new \App\Exceptions\Rejected(__('api.product_not_priced', [
                            'product' => $product->displayName(),
                        ]));
                    }

                    $lineTax = \App\Services\Tax::on($quote['line_total'], $client, $product);

                    $net += $quote['line_total'];
                    $taxTotal += $lineTax;

                    // البضاعة تدخل العربية — صف المرتجعات (batch مجهول:
                    // اللي راجع من العميل مش معروف من أنهي تشغيلة)
                    $item = \App\Models\CustodyItem::firstOrCreate(
                        ['custody_id' => $custody->id, 'product_id' => $productId, 'batch_id' => null],
                        ['assigned' => 0, 'sold' => 0, 'returned' => 0, 'returned_in' => 0],
                    );
                    $item->increment('returned_in', $qty);

                    $lines[] = [
                        'product_id' => $productId,
                        'name' => $product->displayName(),
                        'unit' => $product->unitLabel(),
                        'qty' => $qty,
                        'price' => $quote['unit_price'],
                        'total' => $quote['line_total'],
                    ];
                }

                $grand = round($net + $taxTotal, 2);

                // ⚠️ **دائن** — بيقلل مديونية العميل. بالإجمالي شامل
                // الضريبة، زي قيد البيع المقابل بالظبط.
                $entry = Transaction::create([
                    'client_id' => $client->id,
                    'date' => today(),
                    'memo' => __('flash.memo_return', [
                        'count' => array_sum($qtyByProduct),
                        'user' => $user->displayName(),
                    ]),
                    'debit' => 0,
                    'credit' => $grand,
                    'tax' => round($taxTotal, 2),
                    'kind' => 'return',
                    'source_type' => \App\Models\Visit::class,
                    'source_id' => $data['visit_id'],
                ]);

                // ⚠️ **عميل الكاش بياخد فلوسه في إيده.** بيعته اتقفلت
                // (مدين + تحصيل = صفر)، فمن غير قيد الرد ده المرتجع
                // بيسيب رصيد دائن وهمي على كل مرتجع كاش — والحقيقة إن
                // المندوب ردّ القيمة نقداً في اللحظة. الآجل بيفضل
                // القيد الدائن يقلل مديونيته عادي.
                if ($client->paymentTerms() === 'cash') {
                    Transaction::create([
                        'client_id' => $client->id,
                        'date' => today(),
                        'memo' => __('flash.memo_return_refund'),
                        'debit' => $grand,
                        'credit' => 0,
                        'tax' => round($taxTotal, 2),
                        'kind' => 'refund',
                        'source_type' => \App\Models\Visit::class,
                        'source_id' => $data['visit_id'],
                    ]);
                }

                $client->recalculate();

                TrackEvent::log($user, 'return',
                    __('field.event_return', ['client' => $client->displayName()]),
                    __('common.money', ['amount' => number_format($grand)]),
                    $data['lat'] ?? null, $data['lng'] ?? null);

                return [
                    // رقم المرتجع — بيتطبع على السامري وبيظهر في مبيعاتي
                    'number' => 'RET-'.$entry->id,
                    'net' => round($net, 2),
                    'tax_total' => round($taxTotal, 2),
                    'grand_total' => $grand,
                    'lines' => $lines,
                ];
            });
        } catch (\App\Exceptions\Rejected $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['return' => $result + ['time' => now()->toIso8601String()]], 201);
    }

    /**
     * GET /api/invoices — مبيعات ومرتجعات المندوب (آخر ٧ أيام).
     *
     * المندوب لازم يقدر يراجع «بعت إيه ورجّعت إيه» في أي وقت —
     * مش بس توتال اليوم على الرئيسية.
     */
    public function invoices(Request $request): JsonResponse
    {
        $user = $request->user();

        // فلتر التاريخ: from/to (Y-m-d) — الافتراضي آخر ٧ أيام
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $since = isset($data['from']) ? \Illuminate\Support\Carbon::parse($data['from']) : today()->subDays(7);
        $until = isset($data['to']) ? \Illuminate\Support\Carbon::parse($data['to']) : today();

        $invoices = Invoice::with('client')
            ->where('user_id', $user->id)
            ->whereDate('created_at', '>=', $since)
            ->whereDate('created_at', '<=', $until)
            ->latest()->take(200)->get()->map(fn ($i) => [
                'id' => $i->id, 'number' => $i->number, 'client' => $i->client->displayName(),
                'total' => (float) $i->total,
                // ⚠️ الإجمالي شامل الضريبة — ده اللي اتحصّل فعلاً
                'grand_total' => (float) $i->grand_total,
                'payment' => $i->payment,
                'time' => $i->created_at->toIso8601String(),
            ]);

        // مرتجعاته: قيود `return` المربوطة بزياراته هو —
        // Transaction مالهاش user_id، فالملكية من الزيارة المصدر
        $visitIds = Visit::where('user_id', $user->id)
            ->whereDate('created_at', '>=', $since)
            ->pluck('id');

        $returns = Transaction::with('client')
            ->where('kind', 'return')
            ->where('source_type', Visit::class)
            ->whereIn('source_id', $visitIds)
            ->whereDate('created_at', '<=', $until)
            ->latest()->take(200)->get()->map(fn ($t) => [
                'id' => $t->id,
                // رقم المرتجع — من id القيد: ثابت وفريد ومايتكررش
                'number' => 'RET-'.$t->id,
                'client' => $t->client?->displayName() ?? '—',
                'total' => (float) $t->credit,
                'memo' => $t->memo,
                'time' => $t->created_at->toIso8601String(),
            ]);

        return response()->json(['invoices' => $invoices, 'returns' => $returns]);
    }

    // ================= أوامر التوريد (الكورير) =================

    /** POST /api/pos/{purchaseOrder}/arrive */
    public function arrive(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        if ($purchaseOrder->assigned_to !== $request->user()->id) {
            return response()->json(['message' => __('api.order_not_yours')], 403);
        }
        if ($purchaseOrder->status !== 'pending') {
            return response()->json(['message' => __('api.order_not_pending')], 422);
        }

        $purchaseOrder->update(['status' => 'arrived', 'arrived_at' => now()]);

        TrackEvent::log($request->user(), 'check_in',
            __('field.event_arrived', ['client' => $purchaseOrder->client->displayName()]),
            $purchaseOrder->address, $request->input('lat'), $request->input('lng'));

        return response()->json(['status' => 'arrived']);
    }

    /** POST /api/pos/{purchaseOrder}/deliver */
    public function deliver(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $user = $request->user();

        if ($purchaseOrder->assigned_to !== $user->id) {
            return response()->json(['message' => __('api.order_not_yours')], 403);
        }
        if ($purchaseOrder->status !== 'arrived') {
            return response()->json(['message' => __('field.must_arrive_first')], 422);
        }

        $custody = $user->todayCustody();
        if (! $custody) {
            return response()->json(['message' => __('field.no_custody_today')], 422);
        }

        // ═══ تسليم بكميات فعلية (فلو الكي أكاونت 2026-08-04) ═══
        // الأبلكيشن يقدر يبعت items: [{product_id, qty, unit}] بالمسلَّم
        // فعلاً («9 كراتين مش 10») — والقيد بيتكتب **بالمسلَّم** مش
        // بالمطلوب. من غير items = الفلو القديم (تسليم كامل) زي ما هو.
        $data = $request->validate([
            'items' => ['nullable', 'array'],
            'items.*.product_id' => ['required_with:items', 'exists:products,id'],
            'items.*.qty' => ['required_with:items', 'integer', 'min:0'],
            'items.*.unit' => ['nullable', 'in:piece,box,case'],
        ]);

        $purchaseOrder->load('items.product');

        $delivered = null;   // null = تسليم كامل بالمطلوب

        if (! empty($data['items'])) {
            if ($err = $this->itemsToPieces($data['items'])) {
                return $err;
            }

            $delivered = [];
            foreach ($data['items'] as $i) {
                $delivered[$i['product_id']] = ($delivered[$i['product_id']] ?? 0) + (int) $i['qty'];
            }

            // ⚠️ المسلَّم مقفول بالمطلوب: أكتر من المطلوب مش تعديل —
            // ده بيع من غير أمر، وله مساره (فاتورة عادية).
            foreach ($purchaseOrder->items as $item) {
                if (($delivered[$item->product_id] ?? 0) > (int) $item->qty) {
                    return response()->json(['message' => __('api.po_over_delivery')], 422);
                }
            }

            // صنف مش في الأمر أصلاً = رفض
            $orderProducts = $purchaseOrder->items->pluck('product_id')->all();
            foreach (array_keys($delivered) as $pid) {
                if (! in_array((int) $pid, $orderProducts, true)) {
                    return response()->json(['message' => __('api.po_item_not_in_order')], 422);
                }
            }

            if (array_sum($delivered) === 0) {
                return response()->json(['message' => __('api.po_nothing_delivered')], 422);
            }
        }

        $qty = $delivered ?? $purchaseOrder->items->pluck('qty', 'product_id')->all();
        // الخصم بالكميات اللي **اتسلمت فعلاً** — الباقي بيفضل في العهدة
        $qty = array_filter($qty, fn ($q) => (int) $q > 0);

        // ⚠️ نفس قاعدة storeInvoice: الخصم من العهدة جوه الترانزاكشن،
        // مايصحّش تخرج البضاعة من العربية وأمر التوريد يفضل مش متسلّم.
        try {
            DB::transaction(function () use ($purchaseOrder, $user, $request, $custody, $qty, $delivered) {
                if ($err = $custody->deduct($qty)) {
                    throw new StockShortage($err);
                }

                $purchaseOrder->update(['status' => 'delivered', 'delivered_at' => now()]);

                // ═══ قيمة القيد = المسلَّم فعلاً بسعر بنوده وضريبتها ═══
                $net = 0.0;
                $taxTotal = 0.0;

                foreach ($purchaseOrder->items as $item) {
                    $dq = $delivered === null ? (int) $item->qty : (int) ($delivered[$item->product_id] ?? 0);
                    $item->update(['delivered_qty' => $dq]);

                    // ⚠️ **السطر الكامل بياخد أرقامه المخزنة بالمليم** —
                    // إعادة الحساب ممكن تفرق قرش تقريب عن grand_total
                    // اللي الفلو القديم كان بيقيّد بيه. الجزئي بس هو
                    // اللي بيتحسب من جديد.
                    if ($dq === (int) $item->qty) {
                        $net += (float) $item->total;
                        $taxTotal += (float) $item->tax;
                    } else {
                        $lineNet = round($dq * (float) $item->price, 2);
                        $net += $lineNet;
                        $taxTotal += round($lineNet * (float) ($item->tax_rate ?? 0), 2);
                    }
                }

                $net = round($net, 2);
                $taxTotal = round($taxTotal, 2);
                $grand = round($net + $taxTotal, 2);
                $variance = $purchaseOrder->qtyTotal() - $purchaseOrder->items->sum('delivered_qty');

                // نفس قاعدة الفاتورة: عقد الأمانة مايعملش مديونية عند التوريد
                $consigned = $purchaseOrder->client->isConsignment();

                Transaction::create([
                    'client_id' => $purchaseOrder->client_id,
                    'date' => today(),
                    'memo' => $consigned
                        ? __('flash.memo_consignment', [
                            'number' => $purchaseOrder->number,
                            'amount' => number_format($grand),
                        ])
                        : ($variance > 0
                            ? __('flash.memo_po_partial', ['number' => $purchaseOrder->number, 'diff' => $variance])
                            : __('flash.memo_po_delivered', ['number' => $purchaseOrder->number])),
                    // ⚠️ المديونية **بالمسلَّم** شامل ضريبته — الفرع
                    // مايتحاسبش على كرتونة ماوصلتلوش (قرار المالك 2026-08-04)
                    'debit' => $consigned ? 0 : $grand,
                    'tax' => $consigned ? 0 : $taxTotal,
                    'credit' => 0,
                    'kind' => $consigned ? 'consignment' : 'sale',
                    'source_type' => PurchaseOrder::class,
                    'source_id' => $purchaseOrder->id,
                ]);

                $purchaseOrder->client->recalculate();

                TrackEvent::log($user, 'deliver',
                    __('field.event_delivered', [
                        'number' => $purchaseOrder->number,
                        'client' => $purchaseOrder->client->displayName(),
                    ]),
                    __('field.event_delivered_sub', [
                        'qty' => $purchaseOrder->items->sum('delivered_qty'),
                        'amount' => number_format($grand),
                    ]),
                    $request->input('lat'), $request->input('lng'));
            });
        } catch (StockShortage $e) {
            // نقص في عهدة العربية — مفيش حاجة اتغيرت
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $purchaseOrder->refresh()->load('items');

        // السامري: سلم إيه وإيه الفرق — من السيرفر مش من حساب الأبلكيشن
        return response()->json([
            'status' => 'delivered',
            'number' => $purchaseOrder->number,
            'qty_ordered' => $purchaseOrder->qtyTotal(),
            'qty_delivered' => (int) $purchaseOrder->items->sum('delivered_qty'),
            'delivered_value' => $purchaseOrder->deliveredValue(),
        ]);
    }

    // ================= طلبات العملاء الجدد =================

    /**
     * POST /api/client-requests
     * multipart: { name, phone, address, has_docs, photo (صورة المكان), docs (صورة أو PDF للأوراق) }
     */
    public function storeClientRequest(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:190'],
            'has_docs' => ['nullable'],
            'photo' => ['nullable', 'file', 'image', 'max:8192'],
            'docs' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:12288'],
        ], [], [
            'name' => __('field.attr_place_name'),
            'photo' => __('field.attr_place_photo'),
            'docs' => __('field.attr_official_docs'),
        ]);

        $user = $request->user();

        $photoPath = $request->hasFile('photo')
            ? $request->file('photo')->store('client-requests/photos', 'public')
            : null;

        $docsPath = null;
        $docsType = null;
        if ($request->hasFile('docs')) {
            $file = $request->file('docs');
            $docsPath = $file->store('client-requests/docs', 'public');
            $docsType = strtolower($file->getClientOriginalExtension()) === 'pdf' ? 'pdf' : 'image';
        }

        $hasDocs = filter_var($request->input('has_docs', false), FILTER_VALIDATE_BOOLEAN);

        $req = ClientRequest::create([
            'number' => ClientRequest::nextNumber(),
            'name' => $data['name'],
            'phone' => $data['phone'] ?? null,
            'address' => $data['address'] ?? null,
            'zone_id' => $user->zone_id,
            'has_docs' => $hasDocs || $docsPath !== null,
            'photo_path' => $photoPath,
            'docs_path' => $docsPath,
            'docs_type' => $docsType,
            'status' => 'pending',
            'created_by' => $user->id,
        ]);

        TrackEvent::log($user, 'request',
            __('field.event_client_request', ['name' => $req->name]),
            __('field.event_awaiting_manager'));

        return response()->json([
            'request' => [
                'id' => $req->id, 'number' => $req->number, 'name' => $req->name,
                'status' => $req->status, 'status_label' => $req->statusLabel(),
            ],
        ], 201);
    }

    // ================= الإشعارات =================

    public function readNotifications(Request $request): JsonResponse
    {
        $request->user()->appNotifications()->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['message' => __('common.saved')]);
    }

    /**
     * تحويل بنود البيع/المرتجع من وحدتها المكتوبة للقطع — في السيرفر.
     *
     * ⚠️ **الأبلكيشن بيبعت اسم الوحدة بس، عمره ما يضرب.** توكن معدّل
     * يقدر يبعت أي أرقام — فالضرب والفحص هنا. وحدة مش معرّفة للصنف
     * بترجّع 422 بدل افتراض إنها قطعة (الفرق بين 2 و144 في العهدة
     * والفلوس). بترجّع null لو كله سليم، أو JsonResponse بالرفض.
     */
    private function itemsToPieces(array &$items, ?int $maxPieces = null)
    {
        foreach ($items as $idx => $item) {
            $unit = $item['unit'] ?? 'piece';

            if ($unit !== 'piece') {
                $product = \App\Models\Product::find($item['product_id']);
                $factor = $product?->unitFactor($unit);

                if ($factor === null) {
                    return response()->json([
                        'message' => __('stock.unit_not_for_product', ['name' => $product?->displayName() ?? $item['product_id']]),
                    ], 422);
                }

                $items[$idx]['qty'] = (int) $item['qty'] * $factor;
            }

            // ⚠️ **السقف بيتفحص بعد الضرب مش قبله.** «9999 كرتونة» كانت
            // بتعدّي فاليديشن max:9999 وتتحول 719,928 قطعة — والمرتجع
            // قيد دائن من غير حارس مخزون، فالسقف هنا هو الحارس الوحيد.
            if ($maxPieces !== null && (int) $items[$idx]['qty'] > $maxPieces) {
                return response()->json(['message' => __('api.qty_too_large')], 422);
            }
        }

        return null;
    }
}
