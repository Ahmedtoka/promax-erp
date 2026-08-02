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
            'purchase_orders' => $user->isDriver() ? $this->posPayload($user) : [],
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
            'items' => $custody->items->map(fn ($i) => [
                'product_id' => $i->product_id,
                'code' => $i->product->code,
                'name' => $i->product->displayName(),
                'unit' => $i->product->unitLabel(),
                'price' => (float) $i->product->priceFor($mode),
                'assigned' => $i->assigned,
                'sold' => $i->sold,
                'remaining' => $i->remaining(),
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
        $zones = Zone::with([
            'clients' => function ($q) use ($user) {
                // ⚠️ contract و group.contract ضروريين: effectiveDiscount()
                // بتنادي liveContract() لكل عميل. من غيرهم ~300 كويري زيادة
                // على /api/home وهو أكتر إندبوينت بيتنادى في الأبلكيشن.
                $q->where('status', 'active')
                    ->with(['channel', 'contract', 'group.contract'])
                    ->orderBy('name');
                // السيلز إيجينت بيشوف عملاء قناته بس (لو متحدد له قناة)
                if ($user->channel_id) {
                    $q->where('channel_id', $user->channel_id);
                }
            },
        ])->where('active', true)->orderBy('code')->get();

        $todayVisits = Visit::where('user_id', $user->id)
            ->whereDate('created_at', today())->get()->keyBy('client_id');

        return $zones->map(fn ($z) => [
            'id' => $z->id,
            'code' => $z->code,
            'name' => $z->displayName(),
            'day' => $z->day_label,
            'is_today' => $user->zone_id === $z->id,
            'clients' => $z->clients->map(function ($c) use ($todayVisits) {
                $v = $todayVisits->get($c->id);

                return [
                    'id' => $c->id,
                    'name' => $c->displayName(),
                    'address' => $c->address,
                    'phone' => $c->phone,
                    'category' => $c->category,
                    'category_label' => $c->categoryLabel(),
                    'balance' => (float) $c->balance,
                    'discount' => $c->effectiveDiscount(),
                    'discount_source' => $c->discountSource(),
                    'channel' => $c->channel?->displayName(),
                    'cash_only' => $c->cashOnly(),
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
            ->whereDate('created_at', '>=', today()->subDays(3))
            ->latest()->get()->map(fn ($po) => [
                'id' => $po->id,
                'number' => $po->number,
                'client' => $po->client->displayName(),
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
                'items' => $po->items->map(fn ($i) => [
                    'product_id' => $i->product_id,
                    'name' => $i->product->displayName(),
                    'unit' => $i->product->unitLabel(),
                    'qty' => $i->qty,
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
                'name' => $r['client']->displayName(),
                'address' => $r['client']->address,
                'phone' => $r['client']->phone,
                'lat' => $r['client']->lat !== null ? (float) $r['client']->lat : null,
                'lng' => $r['client']->lng !== null ? (float) $r['client']->lng : null,
                'balance' => (float) $r['client']->balance,
                'cash_only' => $r['client']->cashOnly(),
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
            'payment' => ['required', 'in:cash,credit'],
            'lat' => ['nullable', 'numeric'],
            'lng' => ['nullable', 'numeric'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
        ]);

        $user = $request->user();
        $client = Client::findOrFail($data['client_id']);
        $custody = $user->todayCustody();

        if (! $custody) {
            return response()->json(['message' => __('field.no_custody_today')], 422);
        }
        if ($data['payment'] === 'credit' && $client->cashOnly()) {
            return response()->json(['message' => __('field.cash_only_client')], 422);
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

    /** GET /api/invoices — فواتير المندوب النهارده */
    public function invoices(Request $request): JsonResponse
    {
        $invoices = Invoice::with('client')
            ->where('user_id', $request->user()->id)
            ->whereDate('created_at', today())
            ->latest()->get()->map(fn ($i) => [
                'id' => $i->id, 'number' => $i->number, 'client' => $i->client->displayName(),
                'total' => (float) $i->total, 'payment' => $i->payment,
                'time' => $i->created_at->toIso8601String(),
            ]);

        return response()->json(['invoices' => $invoices]);
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

        $purchaseOrder->load('items');
        $qty = $purchaseOrder->items->pluck('qty', 'product_id')->all();

        // ⚠️ نفس قاعدة storeInvoice: الخصم من العهدة جوه الترانزاكشن،
        // مايصحّش تخرج البضاعة من العربية وأمر التوريد يفضل مش متسلّم.
        try {
            DB::transaction(function () use ($purchaseOrder, $user, $request, $custody, $qty) {
                if ($err = $custody->deduct($qty)) {
                    throw new StockShortage($err);
                }

                $purchaseOrder->update(['status' => 'delivered', 'delivered_at' => now()]);
                $purchaseOrder->items()->update(['delivered_qty' => DB::raw('qty')]);

                // نفس قاعدة الفاتورة: عقد الأمانة مايعملش مديونية عند التوريد
                $consigned = $purchaseOrder->client->isConsignment();

                Transaction::create([
                    'client_id' => $purchaseOrder->client_id,
                    'date' => today(),
                    'memo' => $consigned
                        ? __('flash.memo_consignment', [
                            'number' => $purchaseOrder->number,
                            'amount' => number_format($purchaseOrder->payable()),
                        ])
                        : __('flash.memo_po_delivered', ['number' => $purchaseOrder->number]),
                    // ⚠️ المديونية بالإجمالي شامل الضريبة — زي الفاتورة
                    'debit' => $consigned ? 0 : $purchaseOrder->payable(),
                    'tax' => $consigned ? 0 : (float) $purchaseOrder->tax_total,
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
                        'qty' => $purchaseOrder->qtyTotal(),
                        'amount' => number_format($purchaseOrder->payable()),
                    ]),
                    $request->input('lat'), $request->input('lng'));
            });
        } catch (StockShortage $e) {
            // نقص في عهدة العربية — مفيش حاجة اتغيرت
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['status' => 'delivered']);
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
}
