<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_id', 'product_id', 'batch_id', 'qty',
        'list_price', 'price', 'unit_cost', 'total', 'tax_rate', 'tax',
    ];

    protected function casts(): array
    {
        return [
            'list_price' => 'decimal:2',
            'price' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'total' => 'decimal:2',
            'tax_rate' => 'decimal:4',
            'tax' => 'decimal:2',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** الباتش اللي البند ده خرج منه — أساس التتبع لو حصل recall */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function batchLabel(): string
    {
        return $this->batch?->batch_no ?? '—';
    }

    public function expiryLabel(): ?string
    {
        return $this->batch?->expires_on?->format('Y-m-d');
    }

    /** الخصم على السطر = سعر القائمة − السعر المدفوع */
    public function lineDiscount(): float
    {
        return round(((float) $this->list_price - (float) $this->price) * (int) $this->qty, 2);
    }

    public function lineCost(): float
    {
        return round((float) $this->unit_cost * (int) $this->qty, 2);
    }

    public function lineProfit(): float
    {
        return round((float) $this->total - $this->lineCost(), 2);
    }
}
