<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * شريحة عمولة — «من تحقيق :min_pct% من تارجت الفلوس ياخد :rate من
 * صافي مبيعاته» (2026-08-06). الشرايح بتتقرا تنازلياً وأول شريحة
 * محققة هي اللي بتطبق.
 */
class CommissionTier extends Model
{
    protected $fillable = ['min_pct', 'rate'];

    protected function casts(): array
    {
        return ['min_pct' => 'decimal:2', 'rate' => 'decimal:4'];
    }

    /** نسبة العمولة المستحقة لنسبة تحقيق معيّنة — 0 لو تحت كل الشرايح */
    public static function rateFor(float $achievementPct): float
    {
        return (float) (static::where('min_pct', '<=', $achievementPct)
            ->orderByDesc('min_pct')->value('rate') ?? 0);
    }
}
