<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * موديول في أداة تسليم النظام (spec §3.8) — متولّد أول مرة من مجموعة في
 * `Access::NAV` (`nav_group_key`)، وبعدها المالك بيعدّل اسمه وحالته.
 *
 * ⚠️ حالة التيست واللايف والديدلاين **بيكتبهم المالك بإيده** — مفيش حاجة
 * بتحسبهم. اللي بيتحسب هو نسبة الاستلام بس (`App\Services\Handover`).
 */
class HandoverModule extends Model
{
    public const TEST_STATUSES = ['pending', 'in_progress', 'done'];

    public const LIVE_STATUSES = ['no', 'partial', 'yes'];

    protected $fillable = [
        'key', 'name_ar', 'name_en', 'sort', 'nav_group_key',
        'test_status', 'live_status', 'deadline', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'sort' => 'integer',
            'deadline' => 'date',
        ];
    }

    public function screens(): HasMany
    {
        return $this->hasMany(HandoverScreen::class, 'module_id')->orderBy('sort')->orderBy('id');
    }

    public function displayName(): string
    {
        return app()->getLocale() === 'ar' ? $this->name_ar : ($this->name_en ?: $this->name_ar);
    }
}
