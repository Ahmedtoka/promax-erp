<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * زيارة بروموتر لفرع كي أكاونت
 */
class MerchVisit extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'client_id', 'checked_in_at', 'checked_out_at',
        'photo_before', 'photo_after', 'no_photos', 'no_photo_reason', 'lat', 'lng', 'note',
    ];

    protected function casts(): array
    {
        return [
            'checked_in_at' => 'datetime',
            'checked_out_at' => 'datetime',
            'no_photos' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function refills(): HasMany
    {
        return $this->hasMany(ShelfRefill::class);
    }

    /**
     * مايجريشن الجرد (`2026_09_21_000100`) اتشغّلت؟
     *
     * ⚠️ السيرفر اللايف بيترفع عليه بالإيد — الكود ممكن يوصل قبل
     * المايجريشن. من غير الحارس ده، قفل أي زيارة منسق وبوت ستراب أي
     * منسق عنده زيارة مفتوحة كانوا هيرموا 500 (عمود/جدول مش موجود)
     * **حتى من الأبلكيشن القديم**. الفحص مرة واحدة للريكوست.
     */
    public static function countsReady(): bool
    {
        static $ready = null;

        return $ready ??= \Illuminate\Support\Facades\Schema::hasTable('shelf_counts')
            && \Illuminate\Support\Facades\Schema::hasColumn('merch_visits', 'no_photos');
    }

    /** جرد الرف اللي المنسق كتبه بإيده في الزيارة دي */
    public function counts(): HasMany
    {
        return $this->hasMany(ShelfCount::class);
    }

    public function replenishment(): HasOne
    {
        return $this->hasOne(ReplenishmentRequest::class);
    }

    public function isOpen(): bool
    {
        return $this->checked_out_at === null;
    }

    public function minutes(): ?int
    {
        if ($this->checked_out_at === null || $this->checked_in_at === null) {
            return null;
        }

        return (int) round(abs($this->checked_in_at->diffInMinutes($this->checked_out_at)));
    }

    /** إجمالي القطع اللي اتنقلت للرف */
    public function movedTotal(): int
    {
        return (int) $this->refills->sum('moved_qty');
    }

    /** عدد الأصناف الناقصة خالص */
    public function outOfStockCount(): int
    {
        return $this->refills->where('out_of_stock', true)->count();
    }

    public function photoBeforeUrl(): ?string
    {
        return $this->photo_before ? asset('storage/'.$this->photo_before) : null;
    }

    public function photoAfterUrl(): ?string
    {
        return $this->photo_after ? asset('storage/'.$this->photo_after) : null;
    }
}
