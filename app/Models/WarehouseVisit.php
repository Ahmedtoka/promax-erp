<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * زيارة مخزن واحدة — دخول وخروج ومدة ولوكيشن.
 *
 * ⚠️ **الزيارة المفتوحة هي إذن الاستلام.** `RequireWarehouseVisit`
 * بتسأل عن `open()` قبل أي استلام عهدة أو تسليم PO. أي كود بيقفل
 * زيارة لازم يعرف إنه بيسحب الإذن ده — والمندوب اللي في نص الاستلام
 * بياخد 423 فجأة.
 */
class WarehouseVisit extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'warehouse_id', 'checked_in_at', 'checked_out_at',
        'lat', 'lng', 'out_lat', 'out_lng', 'minutes', 'auto_closed', 'note',
    ];

    protected function casts(): array
    {
        return [
            'checked_in_at' => 'datetime',
            'checked_out_at' => 'datetime',
            'auto_closed' => 'boolean',
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
            'out_lat' => 'decimal:7',
            'out_lng' => 'decimal:7',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    // ---------- الحالة ----------

    public function isOpen(): bool
    {
        return $this->checked_out_at === null;
    }

    /** الزيارة المفتوحة للموظف — **المصدر الوحيد** لإذن الاستلام */
    public static function openFor(User $user): ?self
    {
        return static::with('warehouse')
            ->where('user_id', $user->id)
            ->whereNull('checked_out_at')
            ->latest('checked_in_at')
            ->first();
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereNull('checked_out_at');
    }

    // ---------- المدة ----------

    /**
     * الدقايق **دلوقتي** — المخزّن للمقفولة، والمحسوب للمفتوحة.
     *
     * ⚠️ نفس فصل الحضور بالظبط: المقفول حقيقة ثابتة، والمفتوح
     * بيتحسب وقت العرض. لو خزّنّا المفتوح، الرقم بيقف عند آخر حفظ
     * والمندوب اللي قاعد ساعتين بيبان قاعد دقيقة.
     */
    public function liveMinutes(): int
    {
        if ($this->checked_out_at !== null) {
            return (int) ($this->minutes ?? 0);
        }

        return max((int) $this->checked_in_at->diffInMinutes(now(), absolute: false), 0);
    }

    /** «1:20» — ساعة و20 دقيقة */
    public function durationLabel(): string
    {
        $m = $this->liveMinutes();

        return sprintf('%d:%02d', intdiv($m, 60), $m % 60);
    }

    // ---------- اللوكيشن ----------

    /**
     * المسافة بالمتر بين مكان الدخول والمخزن — أو `null`.
     *
     * ⚠️ **للعرض والتدقيق بس، مش حارس** (قرار 2026-08-08). الرقم ده
     * محتاج معايرة على أرض الواقع الأول: GPS جوّه مخزن مسقوف بيضرب
     * بمئات الأمتار، وقفل الاستلام على رقم مخمّن كان هيوقف الشغل
     * على ناس واقفة في المخزن فعلاً.
     *
     * ⚠️ **هافرساين مش فرق إحداثيات.** الفرق الخام بيدي أرقام مختلفة
     * تماماً حسب خط العرض — ودرجة طول في أسوان مش زي درجة في
     * الإسكندرية.
     */
    public function metresFromWarehouse(): ?int
    {
        $w = $this->warehouse;

        if ($this->lat === null || $this->lng === null
            || $w === null || $w->lat === null || $w->lng === null) {
            return null;
        }

        $r = 6371000.0;
        $lat1 = deg2rad((float) $this->lat);
        $lat2 = deg2rad((float) $w->lat);
        $dLat = $lat2 - $lat1;
        $dLng = deg2rad((float) $w->lng - (float) $this->lng);

        $a = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLng / 2) ** 2;

        return (int) round($r * 2 * atan2(sqrt($a), sqrt(1 - $a)));
    }

    /** حمولة الأبلكيشن — نفس الشكل في البوت ستراب وفي رد الدخول */
    public function payload(): array
    {
        return [
            'id' => $this->id,
            'warehouse_id' => $this->warehouse_id,
            'warehouse' => $this->warehouse?->displayName(),
            'checked_in_at' => $this->checked_in_at->toIso8601String(),
            'checked_out_at' => $this->checked_out_at?->toIso8601String(),
            // ⚠️ الأبلكيشن بيعدّ من `checked_in_at` محلياً — الرقم ده
            // للعرض الفوري قبل أول تيك بس
            'minutes' => $this->liveMinutes(),
            'auto_closed' => $this->auto_closed,
        ];
    }
}
