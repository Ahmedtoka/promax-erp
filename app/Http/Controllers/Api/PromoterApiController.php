<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Client;
use App\Models\MerchVisit;
use App\Models\Product;
use App\Models\ReplenishmentItem;
use App\Models\ReplenishmentRequest;
use App\Models\ShelfRefill;
use App\Models\TrackEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * API البروموتر:
 * يروح فرع الكي أكاونت → يصور الرف قبل → ينزل مخزن الفرع ويعمل ريفيل →
 * يسجل الناقص → يطلب توريد لو مفيش استوك → يصور الرف بعد → يخرج
 */
class PromoterApiController extends Controller
{
    /** GET /api/promoter/bootstrap */
    public function bootstrap(Request $request): JsonResponse
    {
        $user = $request->user();

        // فروع الكي أكاونت اللي في زون البروموتر
        $branches = Client::query()
            ->where('status', 'active')
            ->when(
                $user->channel_id,
                fn ($q) => $q->where('channel_id', $user->channel_id),
                fn ($q) => $q->whereHas('channel', fn ($c) => $c->where('code', Channel::KEY_ACCOUNT)),
            )
            ->when($user->zone_id, fn ($q) => $q->where('zone_id', $user->zone_id))
            ->with('channel')
            ->orderBy('name')
            ->get();

        $todayVisits = MerchVisit::where('user_id', $user->id)
            ->whereDate('created_at', today())->get()->keyBy('client_id');

        $openVisit = MerchVisit::where('user_id', $user->id)
            ->whereNull('checked_out_at')->latest()->first();

        return response()->json([
            'user' => [
                'id' => $user->id, 'name' => $user->displayName(), 'code' => $user->code,
                'role' => $user->role, 'role_label' => $user->roleLabel(),
                'zone' => $user->zone?->displayName(),
                'channel' => $user->channel?->displayName(),
                // ⚠️ **`locale` كانت ناقصة** (تدقيق ٨/٨/٢٠٢٦) —
                // الأبلكيشن بيقرا اللغة من هنا، فالرول ده كان بياخد
                // واجهة إنجليزي كل مرة مهما اختار عربي، لأن المفتاح
                // مش موجود في رده أصلاً.
                'locale' => $user->locale ?: config('app.locale'),
            ],
            // ⚠️ **`attendance` كانت ناقصة** — كارت الحضور في شاشة
            // البروموتر بيقرا منها، ومن غيرها بيفضل «مش حاضر» مهما
            // سجّل، والضغط على «ابدأ الشيفت» بياخد ٤٢٢ من سيرفر
            // مسجّله حاضر خلاص.
            'attendance' => \App\Services\Attendance::payload($user),
            // ⚠️ **جرس الإشعارات كان مابيتملاش أبداً** (تدقيق ٨/٨):
            // الشاشة فيها جرس وشارة، والبوت ستراب مابيبعتش إشعارات
            // خالص — فالبروموتر بيدوس على جرس فاضي دايماً حتى لما
            // يكون فيه قرار على طلب ريفيل بتاعه.
            'notifications' => $user->appNotifications()->take(20)->get()->map(fn ($n) => [
                'id' => $n->id, 'title' => $n->title, 'body' => $n->body,
                'link' => $n->link,
                'is_good' => $n->is_good, 'time' => $n->created_at->toIso8601String(),
                'is_read' => $n->read_at !== null,
            ]),
            'branches' => $branches->map(function (Client $c) use ($todayVisits) {
                $v = $todayVisits->get($c->id);

                return [
                    'id' => $c->id,
                    'name' => $c->displayName(),
                    'address' => $c->address,
                    'phone' => $c->phone,
                    'sub_channel' => $c->subChannelLabel(),
                    'visit_status' => $v === null
                        ? 'pending'
                        : ($v->isOpen() ? 'in_visit' : 'done'),
                    'visit_id' => $v?->id,
                    'moved_today' => $v?->refills()->sum('moved_qty') ?? 0,
                ];
            })->values(),
            'open_visit' => $openVisit === null ? null : $this->visitPayload($openVisit),
            'products' => Product::where('active', true)->orderBy('code')->get()
                ->map(fn ($p) => [
                    'id' => $p->id,
                    'code' => $p->code,
                    'name' => $p->displayName(),
                    'unit' => $p->unitLabel(),
                ])->values(),
            'today' => [
                'visits' => MerchVisit::where('user_id', $user->id)
                    ->whereDate('created_at', today())->count(),
                'visits_done' => MerchVisit::where('user_id', $user->id)
                    ->whereDate('created_at', today())->whereNotNull('checked_out_at')->count(),
                'moved' => (int) ShelfRefill::whereIn(
                    'merch_visit_id',
                    MerchVisit::where('user_id', $user->id)->whereDate('created_at', today())->pluck('id')
                )->sum('moved_qty'),
                'requests' => ReplenishmentRequest::where('requested_by', $user->id)
                    ->whereDate('created_at', today())->count(),
                'branches' => $branches->count(),
            ],
            'requests' => ReplenishmentRequest::with(['client', 'items.product'])
                ->where('requested_by', $user->id)
                ->latest()->take(20)->get()
                ->map(fn ($r) => [
                    'id' => $r->id,
                    'number' => $r->number,
                    'client' => $r->client->displayName(),
                    'status' => $r->status,
                    'status_label' => $r->statusLabel(),
                    'qty_total' => $r->qtyTotal(),
                    'time' => $r->created_at->toIso8601String(),
                ])->values(),
            'events' => TrackEvent::where('user_id', $user->id)
                ->whereDate('happened_at', today())
                ->orderBy('happened_at')->get()
                ->map(fn ($e) => [
                    'type' => $e->type, 'title' => $e->title, 'subtitle' => $e->subtitle,
                    'lat' => (float) $e->lat, 'lng' => (float) $e->lng,
                    'time' => $e->happened_at->toIso8601String(),
                ])->values(),
        ]);
    }

    private function visitPayload(MerchVisit $visit): array
    {
        $visit->load(['client', 'refills.product', 'replenishment']);

        return [
            'id' => $visit->id,
            'client_id' => $visit->client_id,
            'client' => $visit->client->displayName(),
            'address' => $visit->client->address,
            'checked_in_at' => $visit->checked_in_at?->toIso8601String(),
            'checked_out_at' => $visit->checked_out_at?->toIso8601String(),
            'photo_before' => $visit->photoBeforeUrl(),
            'photo_after' => $visit->photoAfterUrl(),
            'has_photo_before' => $visit->photo_before !== null,
            'has_photo_after' => $visit->photo_after !== null,
            'moved_total' => $visit->movedTotal(),
            'out_of_stock' => $visit->outOfStockCount(),
            'has_request' => $visit->replenishment !== null,
            'refills' => $visit->refills->map(fn ($r) => [
                'product_id' => $r->product_id,
                'name' => $r->product->displayName(),
                'unit' => $r->product->unitLabel(),
                'shelf_before' => $r->shelf_before,
                'store_qty' => $r->store_qty,
                'moved_qty' => $r->moved_qty,
                'out_of_stock' => $r->out_of_stock,
            ])->values(),
        ];
    }

    /** POST /api/promoter/visits — بداية زيارة فرع */
    public function startVisit(Request $request): JsonResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'lat' => ['nullable', 'numeric'],
            'lng' => ['nullable', 'numeric'],
        ]);

        $user = $request->user();

        $open = MerchVisit::where('user_id', $user->id)->whereNull('checked_out_at')->first();
        if ($open) {
            return response()->json([
                'message' => __('field.must_close_visit_first', ['client' => $open->client->displayName()]),
            ], 422);
        }

        $client = Client::findOrFail($data['client_id']);

        // ⚠️ **مرساة العلاقة** (تدقيق ٨/٨/٢٠٢٦): `exists:clients,id`
        // كانت بتخلّي أي توكن بروموتر يفتح زيارة رف على أي عميل في
        // الداتابيز. الفلتر ده **نسخة طبق الأصل** من فلتر `bootstrap`
        // فوق — القايمة اللي البروموتر بيشوفها هي اللي مسموح له بيها.
        // (لو الفلتر فوق اتغيّر، غيّر الاتنين مع بعض.)
        $allowed = $user->channel_id === null
            ? $client->channel?->code === Channel::KEY_ACCOUNT
            : (int) $client->channel_id === (int) $user->channel_id;

        if ($user->zone_id !== null && (int) $client->zone_id !== (int) $user->zone_id) {
            $allowed = false;
        }

        if (! $allowed || $client->status !== 'active') {
            return response()->json(['message' => __('api.not_your_client')], 403);
        }

        $visit = MerchVisit::create([
            'user_id' => $user->id,
            'client_id' => $client->id,
            'checked_in_at' => now(),
            'lat' => $data['lat'] ?? null,
            'lng' => $data['lng'] ?? null,
        ]);

        TrackEvent::log($user, 'check_in',
            __('field.event_merch_visit', ['client' => $client->displayName()]),
            $client->address, $data['lat'] ?? null, $data['lng'] ?? null);

        return response()->json(['visit' => $this->visitPayload($visit)], 201);
    }

    /** POST /api/promoter/visits/{merchVisit}/photo — صورة الرف قبل أو بعد */
    public function uploadPhoto(Request $request, MerchVisit $merchVisit): JsonResponse
    {
        if ($merchVisit->user_id !== $request->user()->id) {
            return response()->json(['message' => __('api.not_your_visit')], 403);
        }

        $data = $request->validate([
            'stage' => ['required', 'in:before,after'],
            'photo' => ['required', 'file', 'image', 'max:8192'],
        ], [], ['photo' => __('field.attr_shelf_photo')]);

        $path = $request->file('photo')->store('shelf-photos', 'public');

        $merchVisit->update([
            $data['stage'] === 'before' ? 'photo_before' : 'photo_after' => $path,
        ]);

        return response()->json([
            'url' => asset('storage/'.$path),
            'stage' => $data['stage'],
        ]);
    }

    /**
     * POST /api/promoter/visits/{merchVisit}/refill
     * { lines: [{product_id, shelf_before, store_qty, moved_qty, out_of_stock}] }
     */
    public function saveRefill(Request $request, MerchVisit $merchVisit): JsonResponse
    {
        if ($merchVisit->user_id !== $request->user()->id) {
            return response()->json(['message' => __('api.not_your_visit')], 403);
        }
        if (! $merchVisit->isOpen()) {
            return response()->json(['message' => __('field.visit_already_closed')], 422);
        }

        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'exists:products,id'],
            'lines.*.shelf_before' => ['nullable', 'integer', 'min:0'],
            'lines.*.store_qty' => ['nullable', 'integer', 'min:0'],
            'lines.*.moved_qty' => ['nullable', 'integer', 'min:0'],
            'lines.*.out_of_stock' => ['nullable', 'boolean'],
            // ⚠️ الموقع مع الحدث — الأبلكيشن بقى بيبعته (٨/٨/٢٠٢٦)
            'lat' => ['nullable', 'numeric'],
            'lng' => ['nullable', 'numeric'],
        ]);

        foreach ($data['lines'] as $line) {
            $moved = (int) ($line['moved_qty'] ?? 0);
            $store = (int) ($line['store_qty'] ?? 0);

            if ($moved > $store) {
                $product = Product::find($line['product_id']);

                return response()->json([
                    'message' => __('field.moved_exceeds_store_qty', [
                        'moved' => $moved,
                        'product' => $product?->displayName() ?? __('stock.product_hash', ['id' => $line['product_id']]),
                        'store' => $store,
                    ]),
                ], 422);
            }

            ShelfRefill::updateOrCreate(
                ['merch_visit_id' => $merchVisit->id, 'product_id' => $line['product_id']],
                [
                    'shelf_before' => (int) ($line['shelf_before'] ?? 0),
                    'store_qty' => $store,
                    'moved_qty' => $moved,
                    'out_of_stock' => (bool) ($line['out_of_stock'] ?? false),
                ],
            );
        }

        $merchVisit->refresh();

        return response()->json(['visit' => $this->visitPayload($merchVisit)]);
    }

    /**
     * POST /api/promoter/visits/{merchVisit}/replenishment
     * { items: [{product_id, qty}], note? } — طلب توريد للناقص
     */
    public function requestReplenishment(Request $request, MerchVisit $merchVisit): JsonResponse
    {
        if ($merchVisit->user_id !== $request->user()->id) {
            return response()->json(['message' => __('api.not_your_visit')], 403);
        }
        if ($merchVisit->replenishment !== null) {
            return response()->json(['message' => __('field.visit_has_replenishment')], 422);
        }

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $user = $request->user();

        $req = DB::transaction(function () use ($data, $merchVisit, $user) {
            $req = ReplenishmentRequest::create([
                'number' => ReplenishmentRequest::nextNumber(),
                'client_id' => $merchVisit->client_id,
                'merch_visit_id' => $merchVisit->id,
                'requested_by' => $user->id,
                'status' => 'pending',
                'note' => $data['note'] ?? null,
            ]);

            foreach ($data['items'] as $item) {
                ReplenishmentItem::create([
                    'replenishment_request_id' => $req->id,
                    'product_id' => $item['product_id'],
                    'qty' => $item['qty'],
                ]);
            }

            return $req;
        });

        $qty = $req->items()->sum('qty');

        TrackEvent::log($user, 'request',
            __('field.event_replenishment', [
                'number' => $req->number,
                'client' => $merchVisit->client->displayName(),
            ]),
            __('field.event_qty_requested', ['qty' => $qty]),
            $merchVisit->lat, $merchVisit->lng);

        // الطلب لازم يوصل للـ Channel Manager بتاع قناة العميل فوراً
        foreach ($req->managers() as $manager) {
            // كلوجر عشان الإشعار يتكتب بلغة المستلم مش بلغة اللي بعته
            \App\Models\AppNotification::send(
                $manager,
                fn () => __('field.notif_replenishment_pending_title', ['number' => $req->number]),
                fn () => __('field.notif_replenishment_pending_body', [
                    'client' => $merchVisit->client->displayName(),
                    'qty' => $qty,
                    'user' => $user->displayName(),
                ]),
                link: \App\Models\AppNotification::replenishmentLink($req->id),
            );
        }

        return response()->json([
            'request' => [
                'id' => $req->id,
                'number' => $req->number,
                'status_label' => $req->statusLabel(),
                'qty_total' => (int) $qty,
            ],
        ], 201);
    }

    /** POST /api/promoter/visits/{merchVisit}/close */
    public function closeVisit(Request $request, MerchVisit $merchVisit): JsonResponse
    {
        if ($merchVisit->user_id !== $request->user()->id) {
            return response()->json(['message' => __('api.not_your_visit')], 403);
        }
        if (! $merchVisit->isOpen()) {
            return response()->json(['message' => __('field.visit_already_closed')], 422);
        }
        if ($merchVisit->photo_before === null) {
            return response()->json(['message' => __('field.photo_before_required')], 422);
        }
        if ($merchVisit->photo_after === null) {
            return response()->json(['message' => __('field.photo_after_required')], 422);
        }

        $merchVisit->update([
            'checked_out_at' => now(),
            'note' => $request->input('note', $merchVisit->note),
        ]);

        $merchVisit->load('refills');
        $user = $request->user();

        TrackEvent::log($user, 'refill',
            __('field.event_refill', ['client' => $merchVisit->client->displayName()]),
            __('field.event_refill_sub', [
                'moved' => $merchVisit->movedTotal(),
                'short' => $merchVisit->outOfStockCount(),
            ]),
            $merchVisit->lat, $merchVisit->lng);

        TrackEvent::log($user, 'check_out',
            __('field.event_exit', ['client' => $merchVisit->client->displayName()]),
            __('field.event_visit_minutes', ['minutes' => $merchVisit->minutes()]),
            $merchVisit->lat, $merchVisit->lng);

        return response()->json([
            'minutes' => $merchVisit->minutes(),
            'moved' => $merchVisit->movedTotal(),
        ]);
    }
}
