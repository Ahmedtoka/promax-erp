<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * جهاز تتبع GPS متركب في عربية (iTrack — ٢٦/٨).
 * آخر موقع وحالة بيتحدثوا بأمر `promax:itrack-poll`.
 */
class GpsDevice extends Model
{
    protected $fillable = [
        'imei', 'name', 'plate', 'sim', 'user_id', 'active',
        'lat', 'lng', 'speed', 'course', 'acc', 'datastatus',
        'battery', 'today_km', 'gps_time', 'heart_time',
        'fetched_at', 'platform_expiry',
    ];

    protected $casts = [
        'active' => 'bool',
        'lat' => 'float',
        'lng' => 'float',
        'today_km' => 'float',
        'gps_time' => 'datetime',
        'heart_time' => 'datetime',
        'fetched_at' => 'datetime',
        'platform_expiry' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** إشارة طازة = آخر إحداثية خلال ربع ساعة */
    public function fresh_(): bool
    {
        return $this->gps_time !== null && $this->gps_time->gt(now()->subMinutes(15));
    }

    /** حالة المنصة كمفتاح لغة (enums.gps_*) */
    public function statusKey(): string
    {
        return match ((int) $this->datastatus) {
            2 => 'online',
            4 => 'offline',
            3 => 'expired',
            5 => 'blocked',
            1 => 'never',
            default => 'unknown',
        };
    }

    /** اسم معروض: اللوحة وإلا اسم المنصة وإلا الـIMEI */
    public function label(): string
    {
        return $this->plate ?: ($this->name ?: $this->imei);
    }
}
