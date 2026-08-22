<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** بند عرض سعر — اسم مجمّد وكمية وسعر (٢١ أغسطس ٢٠٢٦) */
class QuotationItem extends Model
{
    public $timestamps = false;

    protected $fillable = ['quotation_id', 'name', 'qty', 'price', 'total'];

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'price' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }
}
