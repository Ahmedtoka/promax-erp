<?php

namespace App\Models;

use App\Models\Concerns\HasDocumentNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * طلب شراء — صنف واحد وكمية (لين ٥، D11). بيتجمّع عليه عروض الموردين
 * والأدمن بيختار عرض فيتحوّل لأمر شراء (`PurchaseQuote::approve`).
 */
class PurchaseRequest extends Model
{
    use HasDocumentNumber;

    public const STATUSES = ['open', 'quoting', 'ordered', 'cancelled'];

    protected $fillable = [
        'number', 'product_id', 'qty', 'needed_by', 'requested_by', 'cost_center_id',
        'status', 'notes', 'supplier_order_id', 'approved_by', 'approved_at',
    ];

    protected function casts(): array
    {
        return ['needed_by' => 'date', 'qty' => 'integer', 'approved_at' => 'datetime'];
    }

    public static function nextNumber(): string
    {
        return static::nextDocumentNumber('PR-', 1001);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SupplierOrder::class, 'supplier_order_id');
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(PurchaseQuote::class);
    }

    /** لسه بيقبل عروض وإلغاء */
    public function isActive(): bool
    {
        return in_array($this->status, ['open', 'quoting'], true);
    }

    public function statusClass(): string
    {
        return match ($this->status) {
            'open' => 'b-blue',
            'quoting' => 'b-orange',
            'ordered' => 'b-green',
            default => 'b-gray',
        };
    }

    public function statusLabel(): string
    {
        return __('supplier.pr_status_'.$this->status);
    }
}
