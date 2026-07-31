<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrackEvent extends Model
{
    use HasFactory;

    public const TYPES = [
        'start' => ['بداية اليوم', '#7C3AED'],
        'check_in' => ['تشيك إن', '#2563EB'],
        'check_out' => ['تشيك أوت', '#DC2626'],
        'sale' => ['فاتورة', '#16A34A'],
        'deliver' => ['تسليم', '#0F766E'],
        'request' => ['طلب', '#EA8C1C'],
        'refill' => ['ريفيل رف', '#7C3AED'],
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
