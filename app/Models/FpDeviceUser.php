<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** يوزر متسجل على المكنة (من OPERLOG / USERINFO) — للربط برقم الموظف */
class FpDeviceUser extends Model
{
    protected $fillable = ['fp_device_id', 'pin', 'name', 'card', 'privilege'];

    public function device(): BelongsTo
    {
        return $this->belongsTo(FpDevice::class, 'fp_device_id');
    }
}
