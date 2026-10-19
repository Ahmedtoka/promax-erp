<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * حركة صرف خامة/تعبئة من باتش — بيكتبها App\Services\RawStock::issue بس.
 * Raw/packaging issue log row; written only by RawStock::issue.
 */
class RawStockMove extends Model
{
    protected $fillable = [
        'warehouse_id', 'product_id', 'batch_id', 'qty', 'unit_cost',
        'for_type', 'for_id', 'created_by', 'moved_at',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'unit_cost' => 'decimal:4',
            'moved_at' => 'datetime',
        ];
    }

    /** المستند اللي الصرف اتعمل لأجله */
    public function for(): MorphTo
    {
        return $this->morphTo('for', 'for_type', 'for_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
