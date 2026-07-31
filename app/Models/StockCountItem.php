<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockCountItem extends Model
{
    protected $fillable = [
        'stock_count_id', 'product_id', 'batch_id',
        'expected_qty', 'system_qty', 'counted_qty',
        'difference', 'cost', 'value_diff', 'reason', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'counted_qty' => 'integer',
            'cost' => 'decimal:2',
            'value_diff' => 'decimal:2',
        ];
    }

    public function stockCount(): BelongsTo
    {
        return $this->belongsTo(StockCount::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    /** لسه مااتعدّش — مختلف عن اتعدّ ولقيناه صفر */
    public function notCounted(): bool
    {
        return $this->counted_qty === null;
    }

    public function batchLabel(): string
    {
        return $this->batch?->batch_no ?: __('count.no_batch');
    }

    public function expiryLabel(): ?string
    {
        return $this->batch?->expires_on?->format('Y-m-d');
    }

    public function reasonLabel(): ?string
    {
        return $this->reason ? __('count.reason_'.$this->reason) : null;
    }

    /**
     * ⚠️ البضاعة اتحركت والعد شغال؟
     *
     * لو رصيد السيستم ساعة الاعتماد بيخالف رصيده ساعة فتح الجرد،
     * يبقى فيه بيع أو تحويل حصل في النص. الفرق ده **مش عجز** —
     * لازم يبان لليوزر مستقل عشان مايحاسبش أمين المخزن عليه.
     */
    public function moved(): bool
    {
        return $this->system_qty !== $this->expected_qty;
    }
}
