<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * بند مرتجع — قطعة راجعة، بسعرها **من الفاتورة الأصلية**.
 *
 * ⚠️ **`invoice_item_id` هو أهم عمود في الجدول ده.** منه بييجي
 * السعر (سعر يوم البيع مش سعر النهارده) والسقف (مايرجّعش أكتر من
 * اللي اشتراه من السطر ده). من غيره المرتجع بيبقى تخمين.
 */
class ReturnItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'return_id', 'product_id', 'invoice_id', 'invoice_item_id',
        'qty', 'condition', 'list_price', 'price', 'total', 'tax_rate', 'tax',
    ];

    protected function casts(): array
    {
        return [
            'list_price' => 'decimal:2',
            'price' => 'decimal:2',
            'total' => 'decimal:2',
            'tax' => 'decimal:2',
        ];
    }

    public function return(): BelongsTo
    {
        return $this->belongsTo(ClientReturn::class, 'return_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function invoiceItem(): BelongsTo
    {
        return $this->belongsTo(InvoiceItem::class, 'invoice_item_id');
    }

    public function isDamaged(): bool
    {
        return $this->condition === ClientReturn::CONDITION_DAMAGED;
    }

    public function conditionLabel(): string
    {
        return __('field.return_cond_'.$this->condition);
    }
}
