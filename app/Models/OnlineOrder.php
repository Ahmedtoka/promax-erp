<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * أوردر أونلاين — مرآة أوردر شوبيفاي + حالته جوه السيستم.
 *
 * ═══ الحالات (فلو المالك ٣/٩ بالحرف) ═══
 *   new        اتعمل له سينك ولسه — التيم بيكلم العميل
 *   postponed  العميل قال أجّل — postponed_to فيها اليوم
 *   preparing  اتأكد ونزل أمر تجهيز في المخزن
 *   ready      اتجهز واتطبعت فاتورته — مستني شيت بيك اب
 *   shipped    طلع في بيك اب
 *   returned   اتشحن ورجع — عملنا مرتجع والبضاعة رجعت المخزن
 *   completed  فلوسه دخلت — أوردر كامل
 *   cancelled  اتلغى (قبل أو بعد الشحن — بعد الشحن بيرجّع البضاعة)
 *
 * ⚠️ البضاعة بتخرج فعلياً من مخزن الأونلاين عند «تم التجهيز»
 * (markReady بتاعة أمر التجهيز بالـFEFO) — مش عند التأكيد.
 */
class OnlineOrder extends Model
{
    public const STATUSES = [
        'new' => 'b-blue',
        'postponed' => 'b-orange',
        'preparing' => 'b-purple',
        'ready' => 'b-gold',
        'shipped' => 'b-blue',
        'returned' => 'b-red',
        'completed' => 'b-green',
        'cancelled' => 'b-gray',
    ];

    /** الحالات اللي البضاعة فيها خرجت من المخزن ولسه مرجعتش */
    public const STOCK_OUT = ['ready', 'shipped', 'completed'];

    protected $fillable = [
        'shopify_id', 'number', 'customer_name', 'phone', 'address', 'area',
        'items_count', 'subtotal', 'shipping', 'total', 'cost_total',
        'collected_total', 'status', 'postponed_to', 'cancel_reason',
        'pick_order_id', 'pickup_id', 'confirmed_by',
        'ordered_at', 'confirmed_at', 'ready_at', 'shipped_at', 'collected_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'shipping' => 'decimal:2',
            'total' => 'decimal:2',
            'cost_total' => 'decimal:2',
            'collected_total' => 'decimal:2',
            'postponed_to' => 'date',
            'ordered_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'ready_at' => 'datetime',
            'shipped_at' => 'datetime',
            'collected_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OnlineOrderItem::class);
    }

    public function pickOrder(): BelongsTo
    {
        return $this->belongsTo(PickOrder::class);
    }

    public function pickup(): BelongsTo
    {
        return $this->belongsTo(OnlinePickup::class, 'pickup_id');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    // ==================== عرض ====================

    public function statusLabel(): string
    {
        return __('online.status_'.$this->status);
    }

    public function statusClass(): string
    {
        return self::STATUSES[$this->status] ?? 'b-gray';
    }

    /** نص الباركود — اللي المسدس بيقراه: pro1234 */
    public function barcode(): string
    {
        return 'pro'.$this->number;
    }

    /** الباقي على الأوردر (للتحصيل) */
    public function remaining(): float
    {
        return round((float) $this->total - (float) $this->collected_total, 2);
    }

    /** فيه بند مش مربوط بمنتج؟ التأكيد بيترفض لحد ما الربط يخلص */
    public function hasUnmatchedItems(): bool
    {
        return $this->items->contains(fn ($i) => $i->product_id === null);
    }

    public function scopeStatus(Builder $q, string $status): Builder
    {
        return $q->where('status', $status);
    }

    // ==================== رجوع البضاعة ====================

    /**
     * رجوع بضاعة الأوردر للمخزن — للمرتجع والإلغاء بعد ما البضاعة خرجت.
     *
     * بيرجّع كل بند لنفس الرف ونفس الباتش اللي اتسحب منهم
     * (returnToShelf بتزوّد الرف + qty_remaining وبتنقّص qty_issued)
     * — نفس مسار فرق التسليم في العهدة، مش زيادة يدوية.
     *
     * ⚠️ **جوه ترانزاكشن** — نص رجوع أسوأ من مفيش رجوع.
     * ⚠️ idempotent بالحالة: بننده مرة واحدة وقت تغيير الحالة،
     *    والحارس في الكنترولر بيمنع النداء المكرر.
     */
    public function restock(): void
    {
        $pick = $this->pickOrder?->load('items');

        if ($pick === null) {
            return;
        }

        DB::transaction(function () use ($pick) {
            foreach ($pick->items as $item) {
                $qty = (int) $item->qty_picked;

                if ($qty > 0) {
                    $item->returnToShelf($qty);
                    $item->update(['qty_picked' => 0]);
                }
            }
        });
    }
}
