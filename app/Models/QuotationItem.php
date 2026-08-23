<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * بند عرض سعر — اسم مجمّد وكمية وسعر (٢١ أغسطس ٢٠٢٦).
 *
 * ⚠️ من ٢٣/٨ البند بيجمّد كمان: الكود، ومرساة الصنف (للصورة في
 * الطباعة)، ولقطة أسعار الوحدات (قطعة/علبة/كرتونة) وقت الإصدار —
 * الورقة المطبوعة ماتتغيرش مهما اتعدل الصنف بعدين.
 */
class QuotationItem extends Model
{
    public $timestamps = false;

    protected $fillable = ['quotation_id', 'product_id', 'code', 'name', 'qty', 'price', 'total', 'units'];

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'price' => 'decimal:2',
            'total' => 'decimal:2',
            'units' => 'array',
        ];
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
