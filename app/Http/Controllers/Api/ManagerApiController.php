<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\Rejected;
use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\Channel;
use App\Models\Client;
use App\Models\ClientRequest;
use App\Models\Invoice;
use App\Models\PurchaseOrder;
use App\Models\ReplenishmentRequest;
use App\Models\TrackEvent;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * API الأدمن / الـ Channel Manager على الموبايل —
 * متابعة المناديب والموافقة على العملاء الجدد
 */
class ManagerApiController extends Controller
{
    /** GET /api/manager/bootstrap — كل حاجة في ريكوست واحد */
    public function bootstrap(Request $request): JsonResponse
    {
        $user = $request->user();

        $channels = $user->channels()->get();

        // ═══ المدير الميداني (قرار المالك ١١ أغسطس ٢٠٢٦) ═══
        // عهدته الحالية + خط سيره — بنفس بيلدرز bootstrap الميدان
        // بالظبط (شوف المفاتيح الإضافية آخر الرد).
        $custody = $user->currentCustody();
        $custody?->load('items.product');
        $journey = FieldApiController::journeyPayload($user);

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->displayName(),
                'code' => $user->code,
                'role' => $user->role,
                'role_label' => $user->roleLabel(),
                // القنوات اللي بيتحكم فيها
                // ⚠️ **`discount` اتشالت** (بقايا قرار ٣١ يوليو ٢٠٢٦):
                // القناة مابقاش لها خصم، والأبلكيشن مش بيقراها أصلاً —
                // فكانت بترجّع صفر لكل قناة وتوحي إن الحقل لسه حي.
                'channels' => $channels->map(fn ($c) => [
                    'id' => $c->id, 'code' => $c->code, 'name' => $c->displayName(),
                ])->values(),
                'manages_all' => $user->isAdmin() || $channels->count() >= Channel::count(),
                // ⚠️ **`locale` كانت ناقصة** (تدقيق ٨/٨/٢٠٢٦) —
                // الأبلكيشن بيقرا اللغة من هنا، فالرول ده كان بياخد
                // واجهة إنجليزي كل مرة مهما اختار عربي، لأن المفتاح
                // مش موجود في رده أصلاً.
                'locale' => $user->locale ?: config('app.locale'),
                // ⚠️ زي درس locale بالظبط: bootstrap بيعيد بناء اليوزر
                'avatar_url' => $user->avatarUrl(),
            ],
            // ⚠️ **`attendance` كانت ناقصة كمان** — كارت الحضور في
            // شاشة المدير بيقرا منها، ومن غيرها بيفضل على الحالة
            // الافتراضية «مش حاضر» مهما سجّل.
            'attendance' => \App\Services\Attendance::payload($user),
            'notifications' => $user->appNotifications()->take(20)->get()->map(fn ($n) => [
                'id' => $n->id, 'title' => $n->title, 'body' => $n->body,
                'link' => $n->link,
                'is_good' => $n->is_good, 'time' => $n->created_at->toIso8601String(),
                'is_read' => $n->read_at !== null,
            ]),
            // الأرقام المجمّعة مفتوحة للكل (زي ما اتفقنا)، لكن **بيانات
            // المندوب الفردية لأ** — GPS وعهدة وفواتير مندوب مدير تاني
            // مش «رقم مجمّع»، دي متابعة فريق. (تدقيق ٨/٨/٢٠٢٦)
            'today' => $this->todayTotals(),
            'reps' => $this->repsPayload($user),
            'requests' => $this->requestsPayload($user),
            'replenishments' => $this->replenishmentsPayload($user),
            'drivers' => User::fieldVisibleTo(
                User::whereIn('role', ['driver', 'sales_agent', 'manager']), $user)
                ->where('active', true)->orderBy('name')->get()
                ->map(fn (User $u) => [
                    'id' => $u->id,
                    'name' => $u->displayName(),
                    'code' => $u->code,
                    'role_label' => $u->roleLabel(),
                ])->values()->all(),
            'events' => $this->eventsPayload($user),

            // ═══ المدير الميداني (١١ أغسطس ٢٠٢٦) — مفاتيح **إضافية** ═══
            //
            // ⚠️ **نفس أشكال bootstrap الميدان بالحرف، من نفس البيلدرز**
            // (`FieldApiController` بقت public static عشان كده) — ممنوع
            // نسخ منطق الحمولة، وإلا الشكلين بيفترقوا مع أول تعديل
            // والأبلكيشن بيبني شاشتين لنفس الداتا.
            // ⚠️ والمفاتيح القديمة فوق زي ما هي — نسخة أبلكيشن قديمة
            // على تليفون مدير ماتقعش.
            //
            // عملاؤه بالزونز — سكوب المدير جوه `zonesPayload` نفسها
            // (`clients.manager_id`)
            'zones' => FieldApiController::zonesPayload($user),
            // خط سيره هو — نفس بيلدر المندوب
            'journey' => $journey,
            'journey_summary' => $journey['summary'],
            // عهدته الحالية — قايمة «جديد» (المدير بيبيع زي السيلز مش
            // زي السواق)
            'custody' => FieldApiController::custodyPayload($custody, 'new'),
            // أوامر التوريد المتسكّنة عليه — نفس شكل كارت السواق
            'purchase_orders' => FieldApiController::posPayload($user),
            // ═══ حزمة المخزن (١١/٨) — من غيرها المدير كان بيشوف
            // قايمة مخازن فاضية ومايعرفش يدخل يستلم عهدته: استلام
            // التجهيز وراه حارس التواجد في المخزن (in.warehouse).
            ...FieldApiController::warehouseBundle(
                $user,
                \App\Services\WarehouseVisits::open($user),
            ),
        ]);
    }

    private function todayTotals(): array
    {
        return [
            // ⚠️ `grand_total` — جنبها `pos_value` بالإجمالي الشامل أصلاً
            'sales' => (float) Invoice::whereDate('created_at', today())->sum('grand_total'),
            'invoices' => Invoice::whereDate('created_at', today())->count(),
            'pos_value' => (float) PurchaseOrder::where('status', 'delivered')
                ->whereDate('delivered_at', today())->sum('grand_total'),
            'pos_done' => PurchaseOrder::where('status', 'delivered')
                ->whereDate('delivered_at', today())->count(),
            'visits_done' => Visit::whereDate('created_at', today())
                ->whereNotNull('checked_out_at')->count(),
            'visits' => Visit::whereDate('created_at', today())->count(),
            'open_requests' => ClientRequest::whereIn('status', ['pending', 'review'])->count(),
            'field_users' => User::whereIn('role', User::FIELD_ROLES)->where('active', true)->count(),
        ];
    }

    /** كل مندوب: عهدته، أداؤه، وهو فين دلوقتي */
    private function repsPayload(User $viewer): array
    {
        return User::fieldVisibleTo(
            User::whereIn('role', User::FIELD_ROLES), $viewer)
            ->where('active', true)
            ->with('zone')
            ->get()
            ->map(function (User $u) {
                $custody = $u->currentCustody();
                $custody?->load('items.product');
                $mode = $u->isDriver() ? 'old' : 'new';
                $openVisit = $u->openVisit();
                $lastEvent = $u->trackEvents()->whereDate('happened_at', today())->first();

                return [
                    'id' => $u->id,
                    'name' => $u->displayName(),
                    'code' => $u->code,
                    'role' => $u->role,
                    'role_label' => $u->roleLabel(),
                    'zone' => $u->zone?->displayName() ?? ($u->isDriver() ? __('ops.delivery_run') : null),
                    'sales' => (float) Invoice::where('user_id', $u->id)
                        ->whereDate('created_at', today())->sum('grand_total'),
                    'invoices' => Invoice::where('user_id', $u->id)
                        ->whereDate('created_at', today())->count(),
                    'visits' => $u->visits()->whereDate('created_at', today())->count(),
                    'visits_done' => $u->visits()->whereDate('created_at', today())
                        ->whereNotNull('checked_out_at')->count(),
                    'pos' => PurchaseOrder::where('assigned_to', $u->id)
                        ->whereDate('created_at', today())->count(),
                    'pos_done' => PurchaseOrder::where('assigned_to', $u->id)
                        ->where('status', 'delivered')->whereDate('delivered_at', today())->count(),
                    'pos_value' => (float) PurchaseOrder::where('assigned_to', $u->id)
                        ->where('status', 'delivered')->whereDate('delivered_at', today())->sum('grand_total'),
                    'has_custody' => $custody !== null,
                    'custody_remaining' => $custody?->remainingUnits() ?? 0,
                    'custody_value' => round($custody?->remainingValue($mode) ?? 0, 2),
                    'active_client' => $openVisit?->client?->displayName(),
                    'active_since' => $openVisit?->checked_in_at?->toIso8601String(),
                    'last_seen' => $lastEvent?->happened_at?->toIso8601String(),
                    'last_action' => $lastEvent?->title,
                ];
            })->values()->all();
    }

    private function requestsPayload(User $manager): array
    {
        $managed = $manager->managedChannelIds();

        return ClientRequest::with(['rep.channel', 'zone'])
            ->latest()->take(50)->get()
            ->map(fn (ClientRequest $r) => [
                // يقدر يقرر بس لو الطلب من قناة بيديرها
                'can_decide' => $manager->isAdmin()
                    || $r->rep?->channel_id === null
                    || in_array($r->rep->channel_id, $managed, true),
                'channel' => $r->rep?->channel?->displayName(),
                'id' => $r->id,
                'number' => $r->number,
                'name' => $r->name,
                'phone' => $r->phone,
                'address' => $r->address,
                'zone' => $r->zone?->displayName(),
                'zone_id' => $r->zone_id,
                'rep' => $r->rep?->displayName(),
                'has_docs' => (bool) $r->has_docs,
                'photo_url' => $r->photoUrl(),
                'docs_url' => $r->docsUrl(),
                'docs_type' => $r->docs_type,
                'status' => $r->status,
                'status_label' => $r->statusLabel(),
                'is_open' => $r->isOpen(),
                'time' => $r->created_at->toIso8601String(),
            ])->values()->all();
    }

    private function eventsPayload(User $viewer): array
    {
        // ⚠️ التايم لاين ده فيه `lat/lng` لكل حدث — من غير فلترة
        // الفريق أي مدير بيرسم مسار مناديب الشركة كلها على خريطته.
        return TrackEvent::with('user')
            ->whereIn('user_id', User::fieldVisibleTo(User::query(), $viewer)->select('id'))
            ->whereDate('happened_at', today())
            ->orderByDesc('happened_at')->take(60)->get()
            ->map(fn (TrackEvent $e) => [
                'type' => $e->type,
                'title' => $e->title,
                'subtitle' => $e->subtitle,
                'user' => $e->user->displayName(),
                'lat' => (float) $e->lat,
                'lng' => (float) $e->lng,
                'time' => $e->happened_at->toIso8601String(),
            ])->values()->all();
    }

    /** GET /api/manager/reps/{user} — تفاصيل مندوب */
    public function rep(Request $request, User $user): JsonResponse
    {
        // ⚠️ **أي توكن مدير كان بيسحب عهدة وفواتير ومسار أي مندوب**
        // بالـid. فلترة القايمة مابتحميش الإندبوينت الفردي.
        \App\Support\Scope::assertRep($request->user(), $user);

        $custody = $user->currentCustody();
        $custody?->load('items.product');
        $mode = $user->isDriver() ? 'old' : 'new';

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->displayName(),
                'code' => $user->code,
                'role_label' => $user->roleLabel(),
                'zone' => $user->zone?->displayName(),
                'phone' => $user->phone,
            ],
            'custody' => $custody === null ? [] : $custody->items->map(fn ($i) => [
                'name' => $i->product->displayName(),
                'unit' => $i->product->unitLabel(),
                'assigned' => $i->assigned,
                'sold' => $i->sold,
                'remaining' => $i->remaining(),
                'value' => round($i->remaining() * $i->product->priceFor($mode), 2),
            ])->values(),
            'invoices' => Invoice::with('client')
                ->where('user_id', $user->id)
                ->whereDate('created_at', today())
                ->latest()->get()
                ->map(fn ($inv) => [
                    'number' => $inv->number,
                    'client' => $inv->client->displayName(),
                    // ⚠️ الإجمالي الشامل — نفس الرقم اللي السواق شايفه
                    'total' => (float) $inv->grand_total,
                    'payment' => $inv->payment,
                    'time' => $inv->created_at->toIso8601String(),
                ])->values(),
            'pos' => PurchaseOrder::with('client')
                ->where('assigned_to', $user->id)
                ->whereDate('created_at', '>=', today()->subDays(3))
                ->latest()->get()
                ->map(fn ($po) => [
                    'number' => $po->number,
                    'client' => $po->client->displayName(),
                    'status_label' => $po->statusLabel(),
                    'total' => (float) $po->payable(),
                ])->values(),
            'events' => $user->trackEvents()
                ->whereDate('happened_at', today())
                ->get()
                ->map(fn ($e) => [
                    'type' => $e->type,
                    'title' => $e->title,
                    'subtitle' => $e->subtitle,
                    'lat' => (float) $e->lat,
                    'lng' => (float) $e->lng,
                    'time' => $e->happened_at->toIso8601String(),
                ])->values(),
        ]);
    }

    /**
     * POST /api/manager/requests/{clientRequest}/decide
     * { decision: approved|review|rejected, discount?, note? }
     */
    public function decide(Request $request, ClientRequest $clientRequest): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approved,review,rejected'],
            'discount' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        if (! $clientRequest->isOpen()) {
            return response()->json(['message' => __('api.request_already_decided')], 422);
        }

        // المدير بيقرر بس في قنواته
        $repChannel = $clientRequest->rep?->channel_id;
        if ($repChannel !== null && ! $request->user()->managesChannel($repChannel)) {
            return response()->json([
                'message' => __('api.not_your_channel'),
            ], 403);
        }

        // ⚠️ وسكوب الفريق فوق سكوب القناة — مديرين كتير ممكن يشاركوا
        // نفس القناة، والطلب بتاع مندوب واحد بس منهم.
        \App\Support\Scope::assertRep($request->user(), $clientRequest->rep);

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
                    // ⚠️ نفس تصليح `OpsController::decideRequest` —
                    // العميل بيرث تسكين المندوب صاحب الطلب، وإلا
                    // بيتولد يتيم ومابيظهرش في `visibleTo` لأي حد.
                    'zone_id' => $clientRequest->zone_id ?? $clientRequest->rep?->zone_id,
                    'rep_id' => $clientRequest->rep?->id,
                    'channel_id' => $clientRequest->rep?->channel_id,
                    'manager_id' => $clientRequest->rep?->manager_id,
                    'branch_id' => $clientRequest->rep?->branch_id,
                    'category' => 'grow',
                    'status' => 'active',
                    'discount' => ($data['discount'] ?? 0) / 100,
                    'uses_channel_discount' => ($data['discount'] ?? 0) <= 0,
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
                    link: AppNotification::requestLink($clientRequest->id),
                );
            } elseif ($data['decision'] === 'review') {
                AppNotification::send(
                    $clientRequest->rep,
                    fn () => __('field.notif_client_review_title', ['name' => $clientRequest->name]),
                    fn () => $data['note'] ?? __('field.notif_client_review_body'),
                    link: AppNotification::requestLink($clientRequest->id),
                );
            } else {
                AppNotification::send(
                    $clientRequest->rep,
                    fn () => __('field.notif_client_rejected_title', ['name' => $clientRequest->name]),
                    fn () => $data['note'] ?? __('field.notif_client_rejected_body'),
                    false,
                    link: AppNotification::requestLink($clientRequest->id),
                );
            }

            $clientRequest->save();
        });

        return response()->json([
            'status' => $clientRequest->status,
            'status_label' => $clientRequest->statusLabel(),
        ]);
    }

    // ==================== طلبات الريفيل ====================

    /** GET /api/manager/replenishments — الطلبات + المناديب المتاحين للتنزيل */
    public function replenishments(Request $request): JsonResponse
    {
        return response()->json([
            'replenishments' => $this->replenishmentsPayload($request->user()),
            'drivers' => User::whereIn('role', ['driver', 'sales_agent'])
                ->where('active', true)->orderBy('name')->get()
                ->map(fn (User $u) => [
                    'id' => $u->id,
                    'name' => $u->displayName(),
                    'code' => $u->code,
                    'role_label' => $u->roleLabel(),
                ])->values()->all(),
        ]);
    }

    /** POST /api/manager/replenishments/{r}/assign — موافقة + تنزيل على مندوب */
    public function assignReplenishment(Request $request, ReplenishmentRequest $replenishmentRequest): JsonResponse
    {
        $data = $request->validate([
            'assigned_to' => ['required', 'exists:users,id'],
            // ⚠️ `channel` لسه مقبولة عشان النسخ القديمة من الأبلكيشن
            // اللي لسه على تليفونات المناديب. الاتنين بيدّوا نفس
            // النتيجة: تسعيرة العميل.
            'price_mode' => ['nullable', 'in:client,channel,old,new'],
        ]);

        if ($err = $this->guardReplenishment($request->user(), $replenishmentRequest)) {
            return $err;
        }

        try {
            $po = $replenishmentRequest->assignTo(
                User::findOrFail($data['assigned_to']),
                $data['price_mode'] ?? 'client',
                $request->user(),
            );
        } catch (Rejected $e) {
            // رفض متوقّع بس — خطأ SQL بيكمّل لـ 500 عن قصد
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'status' => 'assigned',
            'status_label' => $replenishmentRequest->statusLabel(),
            'po_number' => $po->number,
            'po_total' => (float) $po->total,
            'assignee' => $po->courier?->displayName(),
        ]);
    }

    /** POST /api/manager/replenishments/{r}/cancel — رفض الطلب */
    public function cancelReplenishment(Request $request, ReplenishmentRequest $replenishmentRequest): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        if ($err = $this->guardReplenishment($request->user(), $replenishmentRequest)) {
            return $err;
        }

        $replenishmentRequest->update(['status' => 'cancelled']);

        // ⚠️⚠️ **`good: false` مش `false` موضعية** (تدقيق ٩/٨ مساءً):
        // باراميتر موضعي بعد named argument = **خطأ PHP قاتل وقت
        // تحميل الكلاس** — يعني كل `/api/manager/*` كانت بترجع 500
        // ورول المدير على الموبايل واقع بالكامل، والأبلكيشن بيحاول
        // ٣ مرات ويستسلم بصمت فتبان الشاشة أصفار.
        //
        // ⚠️ ولينك الطالب حسب مصدره — طلب المندوب لينكه الرئيسية
        // (مالوش تاب ريفيل)، نفس منطق `assignTo` بالظبط.
        AppNotification::send(
            $replenishmentRequest->promoter,
            fn () => __('field.notif_replenishment_rejected_title', [
                'number' => $replenishmentRequest->number,
            ]),
            fn () => $data['note'] ?? __('field.notif_replenishment_rejected_body'),
            good: false,
            link: $replenishmentRequest->origin() === 'rep'
                ? null
                : AppNotification::replenishmentLink($replenishmentRequest->id),
        );

        return response()->json([
            'status' => 'cancelled',
            'status_label' => $replenishmentRequest->statusLabel(),
        ]);
    }

    /** الطلب لازم يكون مفتوح وتابع لقناة المدير */
    private function guardReplenishment(User $manager, ReplenishmentRequest $r): ?JsonResponse
    {
        if ($r->status !== 'pending') {
            return response()->json(['message' => __('api.request_already_decided')], 422);
        }

        $channelId = $r->client?->channel_id;
        if ($channelId !== null && ! $manager->managesChannel($channelId)) {
            return response()->json(['message' => __('api.not_your_channel')], 403);
        }

        return null;
    }

    /** @return array<int, array<string, mixed>> */
    private function replenishmentsPayload(User $manager): array
    {
        // client.contract و client.group.contract عشان priceFor() مايعملش N+1
        return ReplenishmentRequest::with([
            'client.channel', 'client.contract', 'client.group.contract',
            'promoter', 'assignee', 'items.product', 'merchVisit',
        ])
            ->latest()->take(50)->get()
            ->map(function (ReplenishmentRequest $r) use ($manager) {
                $channelId = $r->client?->channel_id;

                return [
                    'id' => $r->id,
                    'number' => $r->number,
                    'client' => $r->client?->displayName(),
                    'client_id' => $r->client_id,
                    'address' => $r->client?->address,
                    'channel' => $r->client?->channel?->displayName(),
                    'promoter' => $r->promoter?->displayName(),
                    // ═══ مصدر الطلب (2026-08-09): بروموتر من زيارة رف،
                    // ولا مندوب واقف عند العميل. الشاشة بتوري بادج،
                    // وطلب المندوب بيترشّح **هو نفسه** يستلمه —
                    // «يرجع تاني للمندوب» زي ما المالك طلب.
                    'origin' => $r->origin(),
                    'origin_label' => $r->originLabel(),
                    'requested_by_id' => (int) $r->requested_by,
                    'assignee' => $r->assignee?->displayName(),
                    'status' => $r->status,
                    'status_label' => $r->statusLabel(),
                    'is_open' => $r->isOpen(),
                    'can_decide' => $r->status === 'pending'
                        && ($channelId === null || $manager->managesChannel($channelId)),
                    'note' => $r->note,
                    'qty_total' => $r->qtyTotal(),
                    'photo_before' => $r->merchVisit?->photoBeforeUrl(),
                    'photo_after' => $r->merchVisit?->photoAfterUrl(),
                    'time' => $r->created_at->toIso8601String(),
                    'items' => $r->items->map(fn ($i) => [
                        'product' => $i->product?->displayName(),
                        'qty' => (int) $i->qty,
                        // بسعر قناة العميل — نفس اللي هيتحسب لو نزّلها بـ channel
                        'price' => $r->client && $i->product
                            ? (float) $r->client->priceFor($i->product) : 0.0,
                    ])->values()->all(),
                ];
            })->values()->all();
    }
}
