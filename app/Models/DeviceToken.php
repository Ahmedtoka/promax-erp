<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * توكن جهاز لإشعارات فاير بيز.
 *
 * ⚠️ **المفتاح الفريد على التوكن مش على اليوزر.** التليفون ممكن
 * يتسلّم لموظف تاني — وساعتها نفس التوكن بيتسجل لليوزر الجديد
 * والقديم لازم يتشال، وإلا الاتنين هياخدوا نفس الإشعارات.
 */
class DeviceToken extends Model
{
    protected $fillable = ['user_id', 'token', 'platform', 'app_version', 'last_seen_at'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * تسجيل توكن لجهاز — بينقله لليوزر الحالي لو كان مسجل لحد تاني.
     */
    public static function remember(User $user, string $token, ?string $platform = null, ?string $version = null): self
    {
        return static::updateOrCreate(
            ['token' => $token],
            [
                'user_id' => $user->id,
                'platform' => $platform ?: 'android',
                'app_version' => $version,
                'last_seen_at' => now(),
            ],
        );
    }
}
