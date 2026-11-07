<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * مكنة بصمة ZKTeco مسجّلة بالسيريال (ADMS / iclock — ٧/١١/٢٠٢٦).
 * انظر `App\Services\Fingerprint\Adms`.
 */
class FpDevice extends Model
{
    public const MODES = ['keys', 'toggle'];

    /** المكنة بتعمل getrequest كل ~١٠ ثواني — ٣ دقايق من غير ظهور = أوفلاين */
    public const ONLINE_SECONDS = 180;

    protected $fillable = [
        'serial', 'name', 'location', 'mode', 'active', 'apply_from',
        'attlog_stamp', 'operlog_stamp', 'last_seen_at', 'last_punch_at',
        'ip', 'firmware', 'users_count', 'fp_count', 'logs_count',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'apply_from' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_punch_at' => 'datetime',
        ];
    }

    public function logs(): HasMany
    {
        return $this->hasMany(FpLog::class);
    }

    public function deviceUsers(): HasMany
    {
        return $this->hasMany(FpDeviceUser::class);
    }

    public function commands(): HasMany
    {
        return $this->hasMany(FpCommand::class);
    }

    public function isOnline(): bool
    {
        return $this->last_seen_at !== null
            && $this->last_seen_at->gt(now()->subSeconds(self::ONLINE_SECONDS));
    }
}
