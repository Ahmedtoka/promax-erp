<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * مندوب أونلاين — اسم على شيتات البيك اب مش يوزر.
 *
 * ⚠️ **مش من users عن قصد** (قرار المالك ٣/٩): «متظهرش غير في
 * الصفحة دي بس». يوزر برول ميداني كان هيظهر في كل قوايم الميدان
 * والتتبع والتصفيات.
 */
class OnlineCourier extends Model
{
    protected $fillable = ['name', 'phone', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function pickups(): HasMany
    {
        return $this->hasMany(OnlinePickup::class, 'courier_id');
    }
}
