<?php

namespace App\Models;

use App\Models\Concerns\HasDocumentNumber;

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
    use HasDocumentNumber, HasFactory;

    protected $fillable = [
        'number', 'warehouse_id', 'source_warehouse_id', 'received_on', 'status',
        'supplier', 'supplier_id', 'supplier_order_id', 'reference', 'created_by', 'notes',
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

    /** المورد — لو الإذن جاي من أمر شراء */
    public function supplierRow(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function supplierOrder(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(SupplierOrder::class, 'supplier_order_id');
    }

    public static function nextNumber(): string
    {
        // ⚠️ أكبر رقم مش آخر صف — شوف `HasDocumentNumber`
        return static::nextDocumentNumber('GRN-', 1001);
    }
}
