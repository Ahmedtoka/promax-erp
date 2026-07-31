<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * إذن استلام بضاعة — بيولّد باتش أو أكتر.
 * Goods receipt note (GRN) — produces one or more batches.
 */
class GoodsReceipt extends Model
{
    use HasFactory;

    protected $fillable = [
        'number', 'warehouse_id', 'source_warehouse_id', 'received_on', 'status',
        'supplier', 'reference', 'created_by', 'notes',
    ];

    protected function casts(): array
    {
        return ['received_on' => 'date'];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** لو الاستلام جاي من تحويل من مخزن تاني */
    public function sourceWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'source_warehouse_id');
    }

    public function batches(): HasMany
    {
        return $this->hasMany(Batch::class);
    }

    /** فيه بضاعة مستلمة ولسه مترصّفتش على أرفف؟ */
    public function unshelvedQty(): int
    {
        return (int) $this->batches->sum(fn (Batch $b) => $b->unshelvedQty());
    }

    public function isFullyShelved(): bool
    {
        return $this->unshelvedQty() === 0;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function totalQty(): int
    {
        return (int) $this->batches->sum('qty_received');
    }

    public function totalValue(): float
    {
        return (float) $this->batches->sum(fn ($b) => $b->qty_received * (float) $b->cost);
    }

    public static function nextNumber(): string
    {
        $last = static::query()->orderByDesc('id')->value('number');
        $n = $last ? ((int) preg_replace('/\D+/', '', $last)) + 1 : 1001;

        return 'GRN-'.$n;
    }
}
