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

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->displayName(),
                'code' => $user->code,
                'role' => $user->role,
                'role_label' => $user->roleLabel(),
                // القنوات اللي بيتحكم فيها
                'channels' => $channels->map(fn ($c) => [
                    'id' => $c->id, 'code' => $c->code, 'name' => $c->displayName(),
                    'discount' => (float) $c->discount,
                ])->values(),
                'manages_all' => $user->isAdmin() || $channels->count() >= Channel::count(),
            ],
            // الأرقام مفتوحة للكل (زي ما اتفقنا) — والتحكم بيتقيّد بقنواته
            'today' => $this->todayTotals(),
            'reps' => $this->repsPayload(),
            'requests' => $this->requestsPayload($user),
            'replenishments' => $this->replenishmentsPayload($user),
            'drivers' => User::whereIn('role', ['driver', 'sales_agent'])
                ->where('active', true)->orderBy('name')->get()
                ->map(fn (User $u) => [
                    'id' => $u->id,
                    'name' => $u->displayName(),
                    'code' => $u->code,
                    'role_label' => $u->roleLabel(),
                ])->values()->all(),
            'events' => $this->eventsPayload(),
        ]);
    }

    private function todayTotals(): array
    {
        return [
            'sales' => (float) Invoice::whereDate('created_at', today())->sum('total'),
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
    private function repsPayload(): array
    {
        return User::whereIn('role', User::FIELD_ROLES)
            ->where('active', true)
            ->with('zone')
            ->get()
            ->map(function (User $u) {
                $custody = $u->todayCustody();
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
                        ->whereDate('created_at', today())->sum('total'),
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

    private function eventsPayload(): array
    {
        return TrackEvent::with('user')
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
        $custody = $user->todayCustody();
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
                    'total' => (float) $inv->total,
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
                    'total' => (float) $po->total,
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
                    'zone_id' => $clientRequest->zone_id,
                    'channel_id' => $clientRequest->rep?->channel_id,
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

        AppNotification::send(
            $replenishmentRequest->promoter,
            fn () => __('field.notif_replenishment_rejected_title', [
                'number' => $replenishmentRequest->number,
            ]),
            fn () => $data['note'] ?? __('field.notif_replenishment_rejected_body'),
            false,
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
