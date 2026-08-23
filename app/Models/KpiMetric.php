<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * مؤشر KPI (٢٣ أغسطس ٢٠٢٦) — صف من جدولي المؤشرات في شيت Setup.
 *
 * `scope`: rep (١٣ مؤشر المندوب) · leader (١٢ مؤشر المدير/المدير العام).
 * `direction`: higher = الأعلى أحسن · lower = الأقل أحسن (عيوب/مرتجعات).
 * `targets` JSON بيسمح بمستهدف مختلف لكل قناة عمولة، و`target` الديفولت.
 *
 * ⚠️ **مجموع الأوزان لكل scope لازم = 100** — نفس فحص شيت Checks،
 * والشاشة بتصرّخ لو اتكسر.
 */
class KpiMetric extends Model
{
    protected $fillable = [
        'scope', 'key', 'name_ar', 'name_en', 'weight', 'direction',
        'target', 'targets', 'sort', 'active',
    ];

    protected function casts(): array
    {
        return [
            'weight' => 'float',
            'target' => 'float',
            'targets' => 'array',
            'active' => 'boolean',
        ];
    }

    public function displayName(): string
    {
        return app()->getLocale() === 'ar' ? $this->name_ar : $this->name_en;
    }

    /** المستهدف لقناة معينة — من الـJSON وإلا الديفولت */
    public function targetFor(?int $channelId): float
    {
        if ($channelId !== null && is_array($this->targets)
            && array_key_exists((string) $channelId, $this->targets)) {
            return (float) $this->targets[(string) $channelId];
        }

        return (float) $this->target;
    }

    /**
     * نقاط المؤشر — ترجمة حرفية لمعادلة الإكسيل:
     *
     *   Lower : القيمة ≤ 0 ← الوزن كامل، وإلا min(1, target/value)×الوزن
     *   Higher: max(0, min(1, value/target))×الوزن
     *   قيمة غايبة (null) ← 0
     */
    public function points(?float $value, ?int $channelId): float
    {
        if ($value === null) {
            return 0.0;
        }

        $target = $this->targetFor($channelId);

        if ($this->direction === 'lower') {
            if ($value <= 0) {
                return (float) $this->weight;
            }

            return max(0.0, min(1.0, $target / $value)) * (float) $this->weight;
        }

        if ($target <= 0) {
            return 0.0;
        }

        return max(0.0, min(1.0, $value / $target)) * (float) $this->weight;
    }
}
