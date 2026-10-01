<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * مرتجع أوردر أونلاين — صف لكل عملية إرجاع (١/١٠/٢٠٢٦).
 *
 * `kind`: full (الأوردر رجع كله) · partial (جزء). لو اتعمله «إعادة شحن»
 * بيتملى `reshipped_at` وأمر التجهيز الجديد — فالصفحة بتقول البضاعة دي
 * لسه في المخزن ولا خرجت تاني.
 */
class OnlineReturn extends Model
{
    protected $fillable = [
        'online_order_id', 'pickup_id', 'kind', 'pieces', 'value', 'lines',
        'created_by', 'reshipped_at', 'reshipped_by', 'reship_pick_order_id', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'lines' => 'array',
            'value' => 'decimal:2',
            'reshipped_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(OnlineOrder::class, 'online_order_id');
    }

    public function pickup(): BelongsTo
    {
        return $this->belongsTo(OnlinePickup::class, 'pickup_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reshipper(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reshipped_by');
    }

    public function reshipPick(): BelongsTo
    {
        return $this->belongsTo(PickOrder::class, 'reship_pick_order_id');
    }

    /** المرتجع ده ينفع يتعاد شحنه؟ — الأوردر رجع كله، ولسه مااتشحنش تاني */
    public function canReship(): bool
    {
        return $this->reshipped_at === null && $this->order?->status === 'returned';
    }
}
