<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * حركة صرف خامة لأمر إنتاج — صف لكل باتش خامة.
 *
 * `issue` صرف معياري · `extra` صرف فوق المعياري · `return` رجوع للمخزن.
 * ⚠️ الكمية موجبة دايماً، والمرتجع بيتميّز بنوعه مش بإشارة سالبة.
 */
class ProductionIssue extends Model
{
    public const KINDS = ['issue', 'extra', 'return'];

    protected $fillable = [
        'production_order_id', 'product_id', 'batch_id', 'kind', 'qty',
        'unit_cost', 'value', 'user_id', 'issued_at', 'note',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
            'unit_cost' => 'decimal:4',
            'value' => 'decimal:2',
            'issued_at' => 'datetime',
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

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function kindLabel(): string
    {
        return __('mfg.issue_kind_'.$this->kind);
    }
}
