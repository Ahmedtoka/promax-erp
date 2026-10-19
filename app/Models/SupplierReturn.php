<?php

namespace App\Models;

use App\Models\Concerns\HasDocumentNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * مرتجع مورد — البضاعة اللي رسبت في فحص الاستلام. مستند بس: مفيش قيد
 * محاسبي لأن مفيش فلوس اتحركت (الفاتورة بالمستلَم المقبول أصلاً).
 */
class SupplierReturn extends Model
{
    use HasDocumentNumber;

    protected $fillable = [
        'number', 'supplier_id', 'supplier_order_id', 'goods_receipt_id',
        'returned_on', 'total', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return ['returned_on' => 'date', 'total' => 'decimal:2'];
    }

    public static function nextNumber(): string
    {
        return static::nextDocumentNumber('SRET-', 1001);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SupplierOrder::class, 'supplier_order_id');
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SupplierReturnItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
