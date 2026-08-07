<?php

namespace App\Models;

use App\Exceptions\StockShortage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * أمر تجهيز — الوصلة بين المخزن والعربية.
 * Picking order — the bridge from warehouse shelves to a rep's van.
 *
 * ═══ الفلو ═══
 *   requested  المدير طلب أصناف وكميات لمندوب معيّن
 *              (الـ FEFO بيقترح الباتش والرف لكل صنف)
 *   picking    أمين المخزن بدأ يجمع من الأرفف
 *   ready      خلص وقال جاهز → المندوب بيشوفه في الأبلكيشن
 *   handed     المندوب عدّ واستلم → البضاعة نزلت عهدته
 *
 * ⚠️ البضاعة بتخرج من الأرفف عند "ready" مش عند "handed"،
 * عشان ما تتباعش لحد تاني وهي محجوزة. ولو المندوب استلم أقل،
 * الفرق بيرجع للرف تاني ويتسجل في has_variance.
 */
class PickOrder extends Model
{
    use HasFactory;

    public const PURPOSE_VAN_LOAD = 'van_load';
    public const PURPOSE_CUSTOMER_PO = 'customer_po';
    public const PURPOSE_REPLENISHMENT = 'replenishment';

    public const STATUSES = [
        'requested' => 'b-gray',
        'picking' => 'b-orange',
        'ready' => 'b-blue',
        'handed' => 'b-green',
        'cancelled' => 'b-red',
    ];

    // ⚠️ `issued_at` و`carrier_note` كانوا ناقصين هنا — الأعمدة موجودة
    // في المايجريشن بس الـmass-assignment كان بيسقطهم **في صمت**،
    // فقايمة «بانتظار الاستلام» (اللي بتشترط issued_at) كانت فاضية.
    protected $fillable = [
        'number', 'warehouse_id', 'assigned_to', 'requested_by', 'picked_by',
        'purpose', 'status', 'purchase_order_id', 'replenishment_request_id',
        'custody_id', 'needed_on', 'ready_at', 'issued_at', 'carrier_note',
        'handed_at', 'has_variance', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'needed_on' => 'date',
            'ready_at' => 'datetime',
            'issued_at' => 'datetime',
            'handed_at' => 'datetime',
            'has_variance' => 'boolean',
        ];
    }

    // ==================== العلاقات ====================

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function rep(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function picker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'picked_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PickOrderItem::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function replenishmentRequest(): BelongsTo
    {
        return $this->belongsTo(ReplenishmentRequest::class);
    }

    public function custody(): BelongsTo
    {
        return $this->belongsTo(Custody::class);
    }

    // ==================== العرض ====================

    public function statusLabel(): string
    {
        return __('stock.pick_status_'.$this->status);
    }

    public function statusClass(): string
    {
        return self::STATUSES[$this->status] ?? 'b-gray';
    }

    public function purposeLabel(): string
    {
        return __('stock.pick_purpose_'.$this->purpose);
    }

    public function qtyRequested(): int
    {
        return (int) $this->items->sum('qty_requested');
    }

    public function qtyPicked(): int
    {
        return (int) $this->items->sum('qty_picked');
    }

    public function qtyReceived(): int
    {
        return (int) $this->items->sum('qty_received');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['requested', 'picking', 'ready'], true);
    }

    public function canPick(): bool
    {
        return in_array($this->status, ['requested', 'picking'], true);
    }

    public static function nextNumber(): string
    {
        $last = static::query()->orderByDesc('id')->value('number');
        $n = $last ? ((int) preg_replace('/\D+/', '', $last)) + 1 : 1001;

        return 'PCK-'.$n;
    }

    public function scopeForRep(Builder $q, int $userId): Builder
    {
        return $q->where('assigned_to', $userId);
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereIn('status', ['requested', 'picking', 'ready']);
    }

    // ==================== الأكشنز ====================

    /**
     * إنشاء أمر تجهيز مع اقتراح الباتش والرف بالـ FEFO لكل صنف.
     *
     * @param  array<int, int>  $qtyByProduct  [product_id => qty]
     * @return array{order: ?PickOrder, error: ?string}
     */
    public static function raise(
        Warehouse $warehouse,
        User $rep,
        array $qtyByProduct,
        string $purpose = self::PURPOSE_VAN_LOAD,
        ?User $requestedBy = null,
        array $extra = [],
    ): array {
        $qtyByProduct = array_filter($qtyByProduct, fn ($q) => (int) $q > 0);

        if (! $qtyByProduct) {
            return ['order' => null, 'error' => __('stock.pick_no_items')];
        }

        // بنخطط الأول للكل — لو صنف واحد ناقص بنرفض الأمر كله
        $plan = [];
        foreach ($qtyByProduct as $productId => $qty) {
            $lines = self::planFefo($warehouse, (int) $productId, (int) $qty);

            if ($lines === null) {
                $name = Product::find($productId)?->displayName() ?? "#$productId";

                return ['order' => null, 'error' => __('stock.pick_not_enough', [
                    'product' => $name,
                    'available' => $warehouse->availableFor((int) $productId),
                ])];
            }

            $plan = array_merge($plan, $lines);
        }

        $order = DB::transaction(function () use ($warehouse, $rep, $purpose, $requestedBy, $extra, $plan) {
            $order = self::create(array_merge([
                'number' => self::nextNumber(),
                'warehouse_id' => $warehouse->id,
                'assigned_to' => $rep->id,
                'requested_by' => $requestedBy?->id,
                'purpose' => $purpose,
                'status' => 'requested',
            ], $extra));

            foreach ($plan as $line) {
                PickOrderItem::create([
                    'pick_order_id' => $order->id,
                    'product_id' => $line['product_id'],
                    'batch_id' => $line['batch_id'],
                    'location_id' => $line['location_id'],
                    'qty_requested' => $line['qty'],
                ]);
            }

            return $order;
        });

        AppNotification::send(
            $warehouse->manager,
            fn () => __('stock.notif_pick_new_title', ['number' => $order->number]),
            fn () => __('stock.notif_pick_new_body', [
                'rep' => $rep->displayName(),
                'qty' => $order->fresh()->qtyRequested(),
            ]),
        );

        return ['order' => $order, 'error' => null];
    }

    /**
     * تسليم عهدة مباشر — خطوة واحدة بدل تلاتة.
     *
     * ⚠️ **ده اللي المالك طلبه بالنص:** «تطلع مني على طول». الفلو
     * القديم `raise → markReady → handOver` بيمرّ على أمين المخزن،
     * وده منطقي في مخزن كبير فيه حد بيشيل فعلاً — بس هنا اللي بيحمّل
     * العربية هو اللي بيعمل الأمر.
     *
     * ⚠️ **بيستخدم نفس `raise` و`markReady` مش كود جديد.** لو كتبنا
     * خصم مستقل هنا، كنا هنعيد نفس الغلطة اللي التحويلات وقعت فيها:
     * مسار تاني بيخصم من الباتش وينسى الرف، فأمر تجهيز تاني بياخد
     * نفس الكراتين اللي مشيت.
     *
     * @param  array<int, int>  $qtyByProduct   [product_id => qty]  للبيع
     * @param  array<int, int>  $giftByProduct  [product_id => qty]  هدايا
     * @return array{order: ?PickOrder, error: ?string}
     */
    /**
     * طلب تحميل عربية — **من غير خروج فوري** (قرار المالك 2026-08-03).
     *
     * الفلو الجديد: المكتب بيطلب → الورقة بتتطبع → المخزن بيجهّز
     * فيزيكال من شاشة «تجهيز الطلبات» → تأكيد التجهيز (بكميات قابلة
     * للتعديل) هو اللي بيخرج البضاعة ويبعت إشعار للمندوب → المندوب
     * يستلم من الأبلكيشن زي ما هو.
     *
     * نفس `issueDirect` بالظبط ما عدا `markReady` — دي بقت خطوة
     * أمين المخزن مش خطوة المكتب.
     *
     * @param  array<int, int>  $qtyByProduct   [product_id => qty]  للبيع
     * @param  array<int, int>  $giftByProduct  [product_id => qty]  هدايا
     * @return array{order: ?PickOrder, error: ?string}
     */
    public static function requestLoad(
        Warehouse $warehouse,
        User $rep,
        array $qtyByProduct,
        array $giftByProduct = [],
        ?User $requestedBy = null,
        ?string $carrierNote = null,
    ): array {
        $qtyByProduct = array_map('intval', array_filter($qtyByProduct, fn ($q) => (int) $q > 0));
        $giftByProduct = array_map('intval', array_filter($giftByProduct, fn ($q) => (int) $q > 0));

        // الهدية من نفس المخزون — الطلب بالمجموع (نفس منطق issueDirect)
        $total = $qtyByProduct;

        foreach ($giftByProduct as $pid => $g) {
            $total[$pid] = ($total[$pid] ?? 0) + $g;
        }

        if ($total === []) {
            return ['order' => null, 'error' => __('stock.pick_no_items')];
        }

        $raised = self::raise($warehouse, $rep, $total, self::PURPOSE_VAN_LOAD, $requestedBy, [
            'carrier_note' => $carrierNote,
        ]);

        if ($raised['error'] !== null) {
            return $raised;
        }

        /** @var self $order */
        $order = $raised['order'];

        // توزيع الهدايا على البنود بترتيب الـ FEFO — زي issueDirect
        $left = $giftByProduct;

        foreach ($order->items()->orderBy('id')->get() as $item) {
            $pid = (int) $item->product_id;

            if (($left[$pid] ?? 0) <= 0) {
                continue;
            }

            $take = min($left[$pid], (int) $item->qty_requested);
            $item->update(['gift_qty' => $take]);
            $left[$pid] -= $take;
        }

        return ['order' => $order->fresh(), 'error' => null];
    }

    public static function issueDirect(
        Warehouse $warehouse,
        User $rep,
        array $qtyByProduct,
        array $giftByProduct = [],
        ?User $issuedBy = null,
        ?string $carrierNote = null,
    ): array {
        $qtyByProduct = array_map('intval', array_filter($qtyByProduct, fn ($q) => (int) $q > 0));
        $giftByProduct = array_map('intval', array_filter($giftByProduct, fn ($q) => (int) $q > 0));

        // ⚠️ **الهدية بتتحجز من نفس المخزون.** فالطلب للمخزن هو
        // المجموع؛ لو خططنا للبيع بس، الهدية كانت هتخرج من غير ما
        // حد يخصمها والمخزن يقول إنها لسه موجودة.
        $total = $qtyByProduct;

        foreach ($giftByProduct as $pid => $g) {
            $total[$pid] = ($total[$pid] ?? 0) + $g;
        }

        if ($total === []) {
            return ['order' => null, 'error' => __('stock.pick_no_items')];
        }

        $raised = self::raise($warehouse, $rep, $total, self::PURPOSE_VAN_LOAD, $issuedBy);

        if ($raised['error'] !== null) {
            return $raised;
        }

        /** @var self $order */
        $order = $raised['order'];

        // ⚠️ **الهدية بتتوزّع على بنود الأمر بنفس ترتيب FEFO.**
        // `raise` بتقسّم الكمية على أكتر من باتش، فبنمشي على البنود
        // بالترتيب وناخد من كل واحد نصيبه من الهدية.
        $left = $giftByProduct;

        foreach ($order->items()->orderBy('id')->get() as $item) {
            $pid = (int) $item->product_id;

            if (($left[$pid] ?? 0) <= 0) {
                continue;
            }

            $take = min($left[$pid], (int) $item->qty_requested);
            $item->update(['gift_qty' => $take]);
            $left[$pid] -= $take;
        }

        // الخروج الفعلي من الأرفف — نفس المسار اللي المخزن بيستخدمه
        if ($error = $order->markReady($issuedBy ?? $rep)) {
            return ['order' => null, 'error' => $error];
        }

        $order->update([
            'issued_at' => now(),
            'carrier_note' => $carrierNote,
        ]);

        return ['order' => $order->fresh(), 'error' => null];
    }

    /**
     * بيوزّع الكمية المطلوبة على الباتشات والأرفف بترتيب الـ FEFO.
     * بيرجع null لو الكمية مش متاحة.
     *
     * @return array<int, array{product_id:int, batch_id:int, location_id:int, qty:int}>|null
     */
    private static function planFefo(Warehouse $warehouse, int $productId, int $qty): ?array
    {
        $rows = BatchLocation::query()
            ->where('batch_locations.product_id', $productId)
            ->inWarehouse($warehouse->id)
            ->sellable()
            ->fefo()
            ->get();

        $lines = [];
        $left = $qty;

        foreach ($rows as $row) {
            if ($left <= 0) {
                break;
            }
            $take = min($left, (int) $row->qty);
            if ($take <= 0) {
                continue;
            }

            $lines[] = [
                'product_id' => $productId,
                'batch_id' => $row->batch_id,
                'location_id' => $row->location_id,
                'qty' => $take,
            ];
            $left -= $take;
        }

        return $left > 0 ? null : $lines;
    }

    /** أمين المخزن بدأ يجمع */
    public function startPicking(User $picker): ?string
    {
        if (! $this->canPick()) {
            return __('stock.pick_wrong_status');
        }

        $this->update(['status' => 'picking', 'picked_by' => $picker->id]);

        return null;
    }

    /**
     * "جاهز" — البضاعة بتخرج من الأرفف هنا فعلاً وتتحجز للمندوب.
     *
     * @param  array<int, int>|null  $pickedByItem  [pick_order_item_id => qty] لو المخزن عدّل
     */
    public function markReady(User $picker, ?array $pickedByItem = null): ?string
    {
        if (! in_array($this->status, ['requested', 'picking'], true)) {
            return __('stock.pick_wrong_status');
        }

        $this->load(['items.batch', 'items.product']);
        $error = null;

        // الاستثناء هو الطريقة الوحيدة نرجّع الترانزاكشن، لكن DB::transaction
        // بيرميه تاني — فلازم نلقفه هنا عشان نرجّع رسالة بدل 500.
        try {
            DB::transaction(function () use ($picker, $pickedByItem, &$error) {
                foreach ($this->items as $item) {
                    $qty = $pickedByItem[$item->id] ?? $item->qty_requested;
                    $qty = max((int) $qty, 0);

                    if ($qty === 0) {
                        $item->update(['qty_picked' => 0]);

                        continue;
                    }

                    if ($err = $item->pull($qty)) {
                        $error = $err;
                        throw new StockShortage($err);
                    }
                }

                $this->update([
                    'status' => 'ready',
                    'picked_by' => $picker->id,
                    'ready_at' => now(),
                ]);
            });
        } catch (StockShortage $e) {
            // نقص على الرف بس — أي خطأ SQL بيكمّل لـ 500 عن قصد
            return $error ?? $e->getMessage();
        }

        // ⚠️ **الإشعار ده بيتقرا من مسافة ذراع وفي الشمس** (طلب
        // المالك 2026-08-07). المندوب لازم يعرف من غير ما يفتح
        // الأبلكيشن: بضاعة إيه، **فين**، قد إيه، وبمعاد إمتى.
        // النسخة القديمة كانت «الأمر PCK-1010 جاهز» — رقم مالوش
        // معنى لواحد واقف في الشارع.
        $fresh = $this->fresh(['items']);
        $items = $fresh->items->where('qty_picked', '>', 0)->count();

        // جزء المعاد بيتبني هنا مش في ملف اللغة — الشرط (النهارده /
        // يوم كذا / مفيش معاد) مايتعبّرش عنه بـplaceholder واحد
        $due = match (true) {
            $this->needed_on === null => '',
            $this->needed_on->isToday() => __('stock.notif_pick_due_today'),
            default => __('stock.notif_pick_due_on', [
                'date' => $this->needed_on->format('d/m'),
            ]),
        };

        AppNotification::send(
            $this->rep,
            fn () => __('stock.notif_pick_ready_title', [
                'warehouse' => $this->warehouse->displayName(),
            ]),
            fn () => __('stock.notif_pick_ready_body', [
                'qty' => $fresh->qtyPicked(),
                'items' => $items,
                'number' => $this->number,
                'due' => $due,
            ]),
            good: true,
        );

        return null;
    }

    /**
     * المندوب استلم. بيقدر يعدّل الكميات — والفرق بيرجع للرف
     * وبيتسجل عليه ملاحظة، والمدير بيوصله إشعار.
     *
     * @param  array<int, int>|null  $receivedByItem  [pick_order_item_id => qty]
     */
    public function handOver(User $rep, ?array $receivedByItem = null, ?string $note = null): ?string
    {
        if ($this->status !== 'ready') {
            return __('stock.pick_not_ready');
        }
        if ($this->assigned_to !== $rep->id) {
            return __('stock.pick_not_yours');
        }

        $this->load(['items.batch', 'items.product', 'warehouse']);

        // العهدة الموجودة بتتقرا هنا للفحص بس. لو مش موجودة بتتعمل **جوه**
        // الترانزاكشن تحت — لأن firstOrCreate بره الترانزاكشن كان بيسيب عهدة
        // مفتوحة يتيمة لو الترانزاكشن رجعت.
        $existing = Custody::where('user_id', $rep->id)->whereDate('date', today())->first();
        if ($existing && $existing->status === 'closed') {
            return __('field.custody_closed');
        }

        $variance = false;
        $custody = null;

        try {
            DB::transaction(function () use ($receivedByItem, $rep, &$custody, &$variance, $note) {
                $custody = Custody::firstOrCreate(
                    ['user_id' => $rep->id, 'date' => today()],
                    ['warehouse_id' => $this->warehouse_id, 'status' => 'open'],
                );

                // فحص تاني جوه الترانزاكشن — العهدة ممكن تكون اتقفلت في اللحظة دي
                if ($custody->status === 'closed') {
                    throw new StockShortage(__('field.custody_closed'));
                }

                foreach ($this->items as $item) {
                    $picked = (int) ($item->qty_picked ?? 0);
                    $got = $receivedByItem === null
                        ? $picked
                        : max(min((int) ($receivedByItem[$item->id] ?? $picked), $picked), 0);

                    $item->qty_received = $got;

                    // الفرق يرجع للرف اللي طلع منه
                    if ($got < $picked) {
                        $item->returnToShelf($picked - $got);
                        $item->variance_note = $note;
                        $variance = true;
                    }

                    $item->save();

                    if ($got > 0) {
                        // بند عهدة لكل (منتج + باتش) — عشان التتبع يفضل كامل
                        $line = $custody->items()->firstOrNew([
                            'product_id' => $item->product_id,
                            'batch_id' => $item->batch_id,
                        ]);

                        // ⚠️ **الهدية في خانتها.** لو دخلت في `assigned`
                        // كان المندوب هيقفل عهدته و«يبيع» عينات مجانية،
                        // والفرق بين المبيع والموزّع كان يضيع للأبد —
                        // وده أهم رقم في ميزانية التسويق.
                        //
                        // ⚠️ **الهدية بتتقص لو المستلم أخد أقل.** الفرق
                        // بيرجع للرف، فتوزيع الهدية على الناقص كان
                        // هيخلّي عهدة الهدايا أكبر من اللي خرج فعلاً.
                        $gift = min((int) ($item->gift_qty ?? 0), $got);

                        $line->assigned = (int) $line->assigned + ($got - $gift);
                        $line->gift_assigned = (int) $line->gift_assigned + $gift;
                        $line->save();
                    }
                }

                $this->update([
                    'status' => 'handed',
                    'handed_at' => now(),
                    'custody_id' => $custody->id,
                    'has_variance' => $variance,
                ]);
            });
        } catch (StockShortage $e) {
            // العهدة اتقفلت — مفيش بضاعة خرجت ومفيش عهدة يتيمة
            return $e->getMessage();
        }

        TrackEvent::log(
            $rep,
            'start',
            __('stock.event_pick_received', ['number' => $this->number]),
            __('stock.event_pick_received_sub', ['qty' => $this->fresh()->qtyReceived()]),
        );

        if ($variance) {
            AppNotification::send(
                $this->requester ?? $this->warehouse->manager,
                fn () => __('stock.notif_pick_variance_title', ['number' => $this->number]),
                fn () => __('stock.notif_pick_variance_body', [
                    'rep' => $rep->displayName(),
                    'picked' => $this->qtyPicked(),
                    'received' => $this->fresh()->qtyReceived(),
                ]),
                good: false,
            );
        }

        return null;
    }

    /** إلغاء — البضاعة اللي طلعت من الأرفف ترجع */
    public function cancel(): ?string
    {
        if ($this->status === 'handed') {
            return __('stock.pick_already_handed');
        }

        DB::transaction(function () {
            foreach ($this->items()->with('batch')->get() as $item) {
                if ($item->qty_picked > 0) {
                    $item->returnToShelf((int) $item->qty_picked);
                    $item->update(['qty_picked' => 0]);
                }
            }

            $this->update(['status' => 'cancelled']);
        });

        return null;
    }
}
