<?php

namespace App\Models;

use App\Models\Concerns\HasDocumentNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * شيت بيك اب يومي — مجموعة أوردرات جاهزة اتسلّمت لمندوب أونلاين.
 *
 * الإجماليات محسوبة من أوردراته وقت العرض مش مخزنة — كل ما أوردر
 * يتحصّل باقي البيك اب بيقل لحد ما «يتصفّى» (remaining = 0).
 */
class OnlinePickup extends Model
{
    use HasDocumentNumber;

    protected $fillable = ['number', 'date', 'courier_id', 'created_by', 'notes'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(OnlineOrder::class, 'pickup_id');
    }

    /** مرتجعات رجعت من الشيت ده — حتى لو الأوردر اتعاد شحنه وطلع منه (٧/١٠) */
    public function returnLogs(): HasMany
    {
        return $this->hasMany(OnlineReturn::class, 'pickup_id');
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(OnlineCourier::class, 'courier_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function nextNumber(): string
    {
        return static::nextDocumentNumber('PU-', 1001);
    }

    /**
     * إجماليات الشيت من أوردراته.
     *
     * ⚠️ الملغي/المرتجع بعد الشحن بيتشال من «المطلوب» — مش دين
     * على المندوب. الباقي = إجمالي الحي − المتحصّل.
     */
    public function totals(): array
    {
        $orders = $this->relationLoaded('orders') ? $this->orders : $this->orders()->get();

        $live = $orders->whereNotIn('status', ['cancelled', 'returned']);

        $amount = round((float) $live->sum('total'), 2);

        // ⚠️ المتحصّل من **الحي بس** (مراجعة ٣/٩) — أوردر عليه تحصيل
        // ماينفعش يتلغى/يرجع أصلاً (الحارس في الكنترولر)، والقصر هنا
        // بيضمن إن «الباقي» مايتنفخش لو الداتا اتلمست يدوي
        $collected = round((float) $live->sum('collected_total'), 2);

        // المرتجع الجزئي بيتخصم من بضاعة الشيت (٥/٩)
        $goods = round((float) $live->sum(
            fn ($o) => (float) $o->subtotal - (float) $o->returned_total,
        ), 2);

        // ═══ معادلة الشيت (٧/١٠/٢٠٢٦ — طلب المالك) ═══
        // «طلّعت بـ50 ألف، استلمت 40 ورجع 10 يبقى صفر»:
        //   الطالع = المتحصّل + المرتجع + الباقي
        // الطالع = أوردرات الشيت (من غير الملغي) + الأوردرات اللي رجعت منه
        // واتعاد شحنها فطلعت من الشيت (سجل المرتجعات شايل رقم الشيت).
        // المرتجع من السجل — جزئي وكامل، حتى بعد إعادة الشحن.
        $logs = $this->relationLoaded('returnLogs') ? $this->returnLogs : $this->returnLogs()->get();
        $onSheet = $orders->pluck('id')->flip();
        $out = $orders->where('status', '!=', 'cancelled');
        $gone = $logs->reject(fn ($r) => isset($onSheet[$r->online_order_id]));

        return [
            'out_orders' => $out->count() + $gone->pluck('online_order_id')->unique()->count(),
            'out_goods' => round((float) $out->sum('subtotal') + (float) $gone->sum('value'), 2),
            'returned_orders' => $logs->pluck('online_order_id')->unique()->count(),
            'returned_value' => round((float) $logs->sum('value'), 2),
            'orders' => $orders->count(),
            'live' => $live->count(),
            'pieces' => (int) $orders->sum('items_count'),
            // فصل الفلوس (٤/٩): بضاعة + شحن = إجمالي
            'goods' => $goods,
            'ship' => round((float) $live->sum('shipping'), 2),
            'amount' => $amount,
            'collected' => $collected,
            // ⚠️ الباقي من **البضاعة بس** (٥/٩) — الشحن بتاع المندوب
            // مش دين عليه، فالشيت بيتصفى لما تمن البضاعة يكمل
            'remaining' => round($goods - $collected, 2),
        ];
    }

    public function isSettled(): bool
    {
        return $this->totals()['remaining'] <= 0;
    }
}
