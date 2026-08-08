<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_order_id', 'product_id', 'qty', 'delivered_qty', 'price', 'total',
        'list_price', 'discount_pct', 'tax_rate', 'tax',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'total' => 'decimal:2',
            'list_price' => 'decimal:2',
            'discount_pct' => 'decimal:4',
        ];
    }

    /**
     * سعر القايمة قبل الخصم — والسعر نفسه لو السطر قديم.
     *
     * ⚠️ الصفوف اللي اتعملت قبل 2026-08-09 مالهاش `list_price`
     * محفوظ. الرجوع لـ`price` بيخلي الورقة تقول «مفيش خصم» بدل ما
     * تطبع «سعر 0.00 وخصم 100%».
     */
    public function listPrice(): float
    {
        $v = (float) $this->list_price;

        return $v > 0 ? $v : (float) $this->price;
    }

    /** نسبة الخصم كنسبة مئوية للعرض — 0 يعني مفيش */
    public function discountPercent(): float
    {
        return round((float) $this->discount_pct * 100, 2);
    }

    /** قيمة الخصم على السطر كله */
    public function discountValue(): float
    {
        return round(($this->listPrice() - (float) $this->price) * (int) $this->qty, 2);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
