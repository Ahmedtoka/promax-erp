<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * أمر تحويل بين مخزنين — المصنع بيبعت للفرع.
 * Stock transfer between warehouses — the factory ships to a branch.
 *
 * الفلو: المصنع بيبعت (sent) → الفرع يستلم ويأكد الكميات (received)
 * والاستلام بيولّد GRN وباتشات في مخزن الفرع.
 * الفرق بين المبعوت والمستلم بيفضل مسجّل على البند.
 */
class StockTransfer extends Model
{
    use HasFactory;

    public const STATUSES = [
        'sent' => 'b-orange',
        'received' => 'b-green',
        'cancelled' => 'b-red',
    ];

    protected $fillable = [
        'number', 'from_warehouse_id', 'to_warehouse_id', 'status',
        'sent_on', 'received_on', 'sent_by', 'received_by', 'carrier_name',
        'goods_receipt_id', 'notes',
    ];

    protected function casts(): array
    {
        return ['sent_on' => 'date', 'received_on' => 'date'];
    }

    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class);
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function statusLabel(): string
    {
        return __('stock.transfer_status_'.$this->status);
    }

    public function statusClass(): string
    {
        return self::STATUSES[$this->status] ?? 'b-gray';
    }

    /**
     * البضاعة اللي طلعت من مخزن ولسه مستلمتش — «في الطريق».
     *
     * ⚠️ **محسوبة مش متخزنة.** عمود `in_transit_qty` على `stocks` كان
     * هيبقى رقم رابع لازم يتزامن مع التلاتة اللي موجودين، وأول تحويل
     * يتلغى أو يتعدّل من غير ما حد يفتكر العمود بيخلّيه يكدب للأبد.
     * الحساب من التحويلات المفتوحة نفسها مايقدرش يختلف معاها.
     *
     * @return array<int, int> [product_id => qty]
     */
    public static function inTransitFrom(int $warehouseId): array
    {
        return static::query()
            ->where('from_warehouse_id', $warehouseId)
            ->where('status', 'sent')
            ->join('stock_transfer_items as i', 'i.stock_transfer_id', '=', 'stock_transfers.id')
            ->groupBy('i.product_id')
            ->pluck(DB::raw('SUM(i.qty_sent)'), 'i.product_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    public function qtySent(): int
    {
        return (int) $this->items->sum('qty_sent');
    }

    public function qtyShort(): int
    {
        return (int) $this->items->sum('qty_short');
    }

    public function qtyReceived(): int
    {
        return (int) $this->items->sum('qty_received');
    }

    public function hasVariance(): bool
    {
        return $this->status === 'received' && $this->qtySent() !== $this->qtyReceived();
    }

    public function isOpen(): bool
    {
        return $this->status === 'sent';
    }

    /**
     * إرسال شحنة — بيخصم من باتشات المخزن المرسل فعلاً.
     *
     * ⚠️ **ده اللي ماكانش موجود خالص.** التحويل كان بيسجّل صفوف وبس؛
     * الخصم مااتعملش لا هنا ولا عند الاستلام، والاستلام بيزوّد باتشات
     * في المخزن التاني. يعني كل تحويل كان بيزوّد إجمالي بضاعة الشركة
     * بالكمية المحوّلة، والعاشر بيفضل بايع نفس البضاعة اللي هو باعتها.
     *
     * ⚠️ **الباتش بيتقفل قبل الخصم.** تحويلين على نفس الباتش في نفس
     * اللحظة كانوا هيقروا نفس `qty_remaining` ويخصموا الاتنين، فالباتش
     * يطلع بالسالب.
     *
     * @param  array<int, array{product_id:int, source_batch_id:int, qty:int}>  $lines
     * @return array{transfer: ?self, error: ?string}
     */
    public static function send(
        User $user,
        int $fromWarehouseId,
        int $toWarehouseId,
        string $sentOn,
        array $lines,
        ?string $carrier = null,
        ?string $notes = null,
    ): array {
        $error = null;

        // ⚠️ **الاستثناء بيترمي جوه عشان الترانزاكشن ترجع، وبيتمسك
        // هنا عشان المستخدم يشوف رسالة مش صفحة 500.** الرسالة نفسها
        // متخزنة في `$error` قبل الرمي — ومابنعتمدش على نص الاستثناء.
        $transfer = rescue(fn () => DB::transaction(function () use (
            $user, $fromWarehouseId, $toWarehouseId, $sentOn, $lines, $carrier, $notes, &$error
        ) {
            $transfer = static::create([
                'number' => static::nextNumber(),
                'from_warehouse_id' => $fromWarehouseId,
                'to_warehouse_id' => $toWarehouseId,
                'status' => 'sent',
                'sent_on' => $sentOn,
                'sent_by' => $user->id,
                'carrier_name' => $carrier,
                'notes' => $notes,
            ]);

            $touched = [];

            foreach ($lines as $line) {
                $qty = (int) $line['qty'];

                $batch = Batch::whereKey($line['source_batch_id'])->lockForUpdate()->first();

                // ⚠️ **الباتش لازم يكون في المخزن المرسل نفسه.** من غير
                // الفحص ده، حد يقدر يبعت `source_batch_id` بتاع باتش في
                // مخزن تاني ويخصم منه — والبضاعة تظهر في مخزن ثالث.
                if (! $batch || (int) $batch->warehouse_id !== $fromWarehouseId) {
                    $error = __('stock.batch_not_in_warehouse');

                    throw new \RuntimeException('rollback');
                }

                if ((int) $batch->product_id !== (int) $line['product_id']) {
                    $error = __('stock.batch_product_mismatch');

                    throw new \RuntimeException('rollback');
                }

                if ($message = $batch->issue($qty)) {
                    $error = $message;

                    throw new \RuntimeException('rollback');
                }

                StockTransferItem::create([
                    'stock_transfer_id' => $transfer->id,
                    'product_id' => $batch->product_id,
                    'source_batch_id' => $batch->id,
                    'batch_no' => $batch->batch_no,
                    'produced_on' => $batch->produced_on,
                    'expires_on' => $batch->expires_on,
                    'qty_sent' => $qty,
                    // التكلفة بتتنقل من الباتش زي ما هي — البضاعة هي
                    // هي، والتكلفة صفة فيها مش رقم بيتكتب كل شحنة.
                    'cost' => $batch->cost,
                ]);

                $touched[(int) $batch->product_id] = true;
            }

            foreach (array_keys($touched) as $productId) {
                \App\Services\StockCounting::resync((int) $productId, $fromWarehouseId);
            }

            return $transfer;
        }), null, report: false);

        // ⚠️ لو الترانزاكشن رجعت من غير ما نسجّل سبب، الرسالة العامة
        // أحسن من صفحة بيضا — بس ده مايحصلش إلا في خطأ داتابيز حقيقي.
        if ($transfer === null && $error === null) {
            $error = __('stock.transfer_failed');
        }

        $transfer?->notifyDestination();

        return ['transfer' => $transfer, 'error' => $error];
    }

    /**
     * إخطار المخزن المستقبِل إن فيه شحنة في الطريق.
     *
     * ⚠️ **بره الترانزاكشن عن قصد.** الإشعار مش جزء من صحة الحركة —
     * لو جدول الإشعارات وقع، مايصحّش الشحنة كلها ترجع والبضاعة تفضل
     * في العاشر بعد ما العربية مشيت.
     *
     * ⚠️ وبيروح لأمين المخزن **وللمسؤول عنه** — أمين المخزن ممكن
     * يكون في أجازة، والشحنة اللي محدش يعرف إنها جاية بتقف على
     * الرصيف.
     */
    public function notifyDestination(): void
    {
        $this->loadMissing(['toWarehouse', 'fromWarehouse']);

        $targets = User::query()
            ->where('active', true)
            ->where(fn ($q) => $q
                ->where('warehouse_id', $this->to_warehouse_id)
                ->orWhereKey($this->toWarehouse?->manager_id))
            ->get();

        foreach ($targets as $user) {
            AppNotification::send(
                $user,
                fn () => __('stock.notif_transfer_in_title', ['number' => $this->number]),
                fn () => __('stock.notif_transfer_in_body', [
                    'from' => $this->fromWarehouse?->displayName() ?? '—',
                    'qty' => $this->qtySent(),
                ]),
            );
        }
    }

    public static function nextNumber(): string
    {
        $last = static::query()->orderByDesc('id')->value('number');
        $n = $last ? ((int) preg_replace('/\D+/', '', $last)) + 1 : 1001;

        return 'TRF-'.$n;
    }

    /**
     * استلام التحويل في المخزن المستقبِل.
     * بيولّد إذن استلام وباتشات، والبضاعة تبقى «مستلمة ولسه مترصّفتش».
     *
     * ⚠️ **العجز بيتسجّل على المخزن المرسل.** البضاعة خرجت من باتشه
     * وقت الإرسال (`issue`)، فالناقص مش «مااتصرفش» — ده صرف ضاع.
     * بننقله من `qty_issued` لـ`qty_damaged` على نفس الباتش، فيفضل
     * `qty_received = qty_remaining + qty_issued + qty_damaged` صحيح
     * والفرق مفسَّر. لو سبناه في `qty_issued` كان هيبان كأنه وصل
     * لحد ما، ولو رجّعناه لـ`qty_remaining` كان المخزن هيقول إنه
     * موجود على الرف وهو مش موجود.
     *
     * @param  array<int, int>|null  $receivedByItem  [stock_transfer_item_id => qty]
     * @param  array<int, string|null>|null  $producedByItem  [stock_transfer_item_id => date]
     */
    public function receive(
        User $user,
        ?array $receivedByItem = null,
        ?string $notes = null,
        ?array $producedByItem = null,
    ): array {
        if ($this->status !== 'sent') {
            return ['receipt' => null, 'error' => __('stock.transfer_not_open')];
        }

        $this->load(['items.product', 'items.sourceBatch', 'toWarehouse', 'fromWarehouse']);

        // ⚠️ **الاستلام مايزيدش عن المبعوت.** المخزن المستقبِل
        // مايقدرش يخلق بضاعة — لو وصله أكتر، ده جرد مش استلام.
        foreach ($this->items as $item) {
            $q = $receivedByItem === null ? null : ($receivedByItem[$item->id] ?? null);

            if ($q !== null && (int) $q > (int) $item->qty_sent) {
                return ['receipt' => null, 'error' => __('stock.received_over_sent', [
                    'product' => $item->product?->displayName() ?? '—',
                    'sent' => (int) $item->qty_sent,
                ])];
            }
        }

        $receipt = DB::transaction(function () use ($user, $receivedByItem, $producedByItem, $notes) {
            $receipt = GoodsReceipt::create([
                'number' => GoodsReceipt::nextNumber(),
                'warehouse_id' => $this->to_warehouse_id,
                'source_warehouse_id' => $this->from_warehouse_id,
                'received_on' => today(),
                'status' => 'posted',
                'supplier' => $this->fromWarehouse?->displayName(),
                'reference' => $this->number,
                'created_by' => $user->id,
                'notes' => $notes,
            ]);

            $touched = [];

            foreach ($this->items as $item) {
                $qty = $receivedByItem === null
                    ? (int) $item->qty_sent
                    : max((int) ($receivedByItem[$item->id] ?? $item->qty_sent), 0);

                $short = max((int) $item->qty_sent - $qty, 0);

                // ⚠️ **تاريخ الإنتاج بيتعدّل هنا لو المستلم صحّحه.**
                // الورقة اللي على الكرتونة هي الحقيقة، واللي بعت ممكن
                // يكون كتبه غلط — والتاريخ الغلط معناه صلاحية غلط
                // وترتيب FEFO غلط لكل ما الباتش ده يخرج بعد كده.
                $produced = $producedByItem === null
                    ? $item->produced_on
                    : (($producedByItem[$item->id] ?? null) ?: $item->produced_on);

                $product = $item->product;
                $expires = $produced && $product
                    ? $product->expiryFrom($produced)->toDateString()
                    : $item->expires_on;

                $item->update([
                    'qty_received' => $qty,
                    'qty_short' => $short,
                    'produced_on' => $produced,
                    'expires_on' => $expires,
                ]);

                // ⚠️ العجز بيترد على باتش المخزن المرسل: بينتقل من
                // «اتصرف» لـ«توالف» عشان الفرق يفضل مفسَّر ومايتحسبش
                // مرتين. الباتش بيتقفل عشان تحويلين على نفس الباتش
                // في نفس اللحظة مايدوسوش على بعض.
                if ($short > 0 && $item->source_batch_id) {
                    $source = Batch::whereKey($item->source_batch_id)->lockForUpdate()->first();

                    if ($source) {
                        $source->decrement('qty_issued', min($short, (int) $source->qty_issued));
                        $source->increment('qty_damaged', $short);
                        $touched[$this->from_warehouse_id][$item->product_id] = true;
                    }
                }

                if ($qty <= 0) {
                    continue;
                }

                // نفس رقم الباتش في مخزن مختلف = صف مستقل
                $batch = Batch::updateOrCreate(
                    [
                        'product_id' => $item->product_id,
                        'batch_no' => $item->batch_no,
                        'warehouse_id' => $this->to_warehouse_id,
                    ],
                    [
                        'goods_receipt_id' => $receipt->id,
                        'produced_on' => $produced,
                        'expires_on' => $expires,
                        // ⚠️ **التكلفة من كارت الصنف مش من الشحنة.**
                        // كانت خانة في فورم التحويل، يعني نفس الصنف
                        // كان بياخد تكلفة مختلفة كل شحنة على مزاج اللي
                        // بيكتب — وربحية الفاتورة بتطلع من تكلفة الباتش.
                        'cost' => $item->cost ?? $product?->cost ?? 0,
                    ],
                );

                // الكمية بتتزوّد — ممكن نفس الباتش يوصل على شحنتين
                $batch->increment('qty_received', $qty);
                $batch->increment('qty_remaining', $qty);
                $touched[$this->to_warehouse_id][$item->product_id] = true;
            }

            $this->update([
                'status' => 'received',
                'received_on' => today(),
                'received_by' => $user->id,
                'goods_receipt_id' => $receipt->id,
            ]);

            // ⚠️ `stocks` تجميعة من الباتشات — لازم تتعاد للمخزنين،
            // وإلا شاشة المخزون بترقّم غير الباتشات ومحدش يعرف مين الصح.
            foreach ($touched as $warehouseId => $products) {
                foreach (array_keys($products) as $productId) {
                    \App\Services\StockCounting::resync((int) $productId, (int) $warehouseId);
                }
            }

            return $receipt;
        });

        if ($this->fresh()->hasVariance()) {
            AppNotification::send(
                $this->sender,
                fn () => __('stock.notif_transfer_variance_title', ['number' => $this->number]),
                fn () => __('stock.notif_transfer_variance_body', [
                    'sent' => $this->qtySent(),
                    'received' => $this->fresh()->qtyReceived(),
                ]),
                good: false,
            );
        }

        return ['receipt' => $receipt, 'error' => null];
    }
}
