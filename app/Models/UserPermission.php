<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * استثناء صلاحية ليوزر واحد — الافتراضي جاي من رول اليوزر
 * (Access::SCREENS)، والصف هنا بيغلبه في الاتجاهين.
 *
 * ⚠️ **مفيش صف = وراثة.** ماتكتبش صف allow لحاجة الرول أصلاً
 * بيشوفها — شاشة الصلاحيات بتمسح الصف لما ترجع «وراثة».
 */
class UserPermission extends Model
{
    protected $fillable = ['user_id', 'perm', 'allow'];

    protected function casts(): array
    {
        return ['allow' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
