<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * سطر جرد رف: المنسق كتب بإيده كمية الصنف بوحدتها وتاريخ إنتاجه
 * وانتهائه. محفوظ على الزيارة **والفرع** — الزيارة الجاية لنفس
 * الفرع بتفتح على آخر جرد وتقارن بيه.
 */
class ShelfCount extends Model
{
    /** مدة الصلاحية بالشهور — الانتهاء = الإنتاج + سنة (قرار المالك ٢١/٩) */
    public const SHELF_LIFE_MONTHS = 12;

    protected $fillable = [
        'merch_visit_id', 'client_id', 'user_id', 'product_id',
        'qty', 'unit', 'pieces', 'production_date', 'expiry_date', 'note',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:2',
            'production_date' => 'date',
            'expiry_date' => 'date',
        ];
    }

    public function merchVisit(): BelongsTo
    {
        return $this->belongsTo(MerchVisit::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** كام يوم فاضل على الانتهاء — سالب = منتهي، null = مفيش تاريخ */
    public function daysToExpiry(): ?int
    {
        if ($this->expiry_date === null) {
            return null;
        }

        return (int) round(today()->diffInDays($this->expiry_date, false));
    }
}
