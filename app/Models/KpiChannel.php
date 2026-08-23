<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * قناة عمولة (٢٣ أغسطس ٢٠٢٦) — صف من جدول «توزيع العمولة الأساسية»
 * في نموذج الإكسيل: Specialty / Convenience & Contracted.
 *
 * ⚠️ **قناة العمولة ≠ قناة البيع.** القناة هنا فريق مدير قناة كامل —
 * مناديب القناة بيتحددوا بـ`users.manager_id == manager_id`.
 */
class KpiChannel extends Model
{
    protected $fillable = [
        'name', 'name_ar', 'manager_id',
        'rep_gate', 'rep_max_rate',
        'manager_gate', 'manager_rate',
        'director_gate', 'director_rate',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'rep_gate' => 'float', 'rep_max_rate' => 'float',
            'manager_gate' => 'float', 'manager_rate' => 'float',
            'director_gate' => 'float', 'director_rate' => 'float',
            'active' => 'boolean',
        ];
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function rateBands(): HasMany
    {
        return $this->hasMany(KpiBand::class)->where('kind', 'rate')->orderBy('from_value');
    }

    public function displayName(): string
    {
        return app()->getLocale() === 'ar' ? ($this->name_ar ?: $this->name) : $this->name;
    }

    /** أقصى تكلفة أساسية = مجموع النسب التلاتة — فحص «≤ 3%» في الشاشة */
    public function maxBaseCost(): float
    {
        return round($this->rep_max_rate + $this->manager_rate + $this->director_rate, 5);
    }

    /** مناديب القناة = فريق مديرها الميداني النشط */
    public function reps()
    {
        return User::whereIn('role', User::FIELD_ROLES)
            ->where('active', true)
            ->when($this->manager_id, fn ($q) => $q->where('manager_id', $this->manager_id))
            ->orderBy('name')
            ->get();
    }
}
