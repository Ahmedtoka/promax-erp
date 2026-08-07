<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrackEvent extends Model
{
    use HasFactory;

    /**
     * أنواع الأحداث — [المسمى الافتراضي، اللون، الأيقونة].
     *
     * ⚠️ **أي نوع بيتسجّل في الكود لازم يبقى هنا.** `return` و`open`
     * كانوا بيتسجّلوا من شهور وهما مش في القايمة دي — فكانوا بيطلعوا
     * في غرفة التحكم بلون رمادي واسم إنجليزي خام (`return`) وشكلهم
     * زي حدث بايظ. `color()` و`icon()` بيرجّعوا الافتراضي بصمت، فالغلط
     * ده مابيعملش أي خطأ — بس بيخفي حركة المندوب.
     *
     * الأيقونة إيموچي عن قصد: بتترسم في HTML وفي الأبلكيشن من غير
     * أي أصول ولا تعريفات ألوان.
     */
    public const TYPES = [
        'start' => ['استلم عهدة', '#7C3AED', '📦'],
        'open' => ['فتح الأبلكيشن', '#6366F1', '📱'],
        'check_in' => ['دخل عند عميل', '#2563EB', '📍'],
        'check_out' => ['خرج من عميل', '#DC2626', '🚪'],
        'sale' => ['باع', '#16A34A', '💰'],
        'return' => ['مرتجع', '#B45309', '↩️'],
        'gift' => ['هدية', '#DB2777', '🎁'],
        'deliver' => ['سلّم أمر توريد', '#0F766E', '🚚'],
        'request' => ['طلب عميل جديد', '#EA8C1C', '🆕'],
        'refill' => ['ريفيل رف', '#7C3AED', '🧃'],
    ];

    protected $fillable = [
        'user_id', 'type', 'title', 'subtitle', 'lat', 'lng', 'happened_at',
    ];

    protected function casts(): array
    {
        return ['happened_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function typeLabel(): string
    {
        // المسمى بييجي من lang/{ar,en}/enums.php — والثابت القديم fallback
        $key = 'enums.track.'.$this->type;

        return \Illuminate\Support\Facades\Lang::has($key)
            ? __($key)
            : (self::TYPES[$this->type][0] ?? $this->type);
    }

    public function color(): string
    {
        return self::TYPES[$this->type][1] ?? '#6B6B66';
    }

    /** أيقونة النوع — بتترسم في غرفة التحكم وشاشة التتبع */
    public function icon(): string
    {
        return self::TYPES[$this->type][2] ?? '•';
    }

    public static function log(
        User $user,
        string $type,
        string $title,
        ?string $subtitle = null,
        ?float $lat = null,
        ?float $lng = null,
    ): self {
        return static::create([
            'user_id' => $user->id,
            'type' => $type,
            'title' => $title,
            'subtitle' => $subtitle,
            'lat' => $lat,
            'lng' => $lng,
            'happened_at' => now(),
        ]);
    }
}
