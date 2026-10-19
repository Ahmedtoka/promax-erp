<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * سطر أمر إنتاج — لقطة من سطر الـBOM وقت الإنشاء (المعياري للكمية
 * المخططة بالهالك)، والفعلي والانحراف بيتملوا عند القفل.
 */
class ProductionOrderLine extends Model
{
    protected $fillable = [
        'production_order_id', 'product_id', 'basis', 'bom_qty', 'std_qty',
        'std_qty_actual', 'base_qty_actual', 'actual_qty', 'actual_cost', 'std_cost',
        'variance_qty', 'variance_value', 'waste_pct',
    ];

    protected function casts(): array
    {
        return [
            'bom_qty' => 'decimal:4',
            'std_qty' => 'decimal:3',
            'std_qty_actual' => 'decimal:3',
            'base_qty_actual' => 'decimal:3',
            'actual_qty' => 'decimal:3',
            'actual_cost' => 'decimal:2',
            'std_cost' => 'decimal:2',
            'variance_qty' => 'decimal:3',
            'variance_value' => 'decimal:2',
            'waste_pct' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'production_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
