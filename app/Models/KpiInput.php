<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * المدخلات اليدوية الشهرية للـKPI (٢٣ أغسطس ٢٠٢٦) — اللي مالهاش
 * مصدر أوتوماتيك في السيستم: توقع المبيعات، مستهدف العملاء الجدد،
 * ودرجة التقارير. صف لكل (شهر × دور × قناة).
 */
class KpiInput extends Model
{
    protected $fillable = ['period', 'role', 'kpi_channel_id', 'forecast', 'new_target', 'reporting'];

    protected function casts(): array
    {
        return ['forecast' => 'float', 'new_target' => 'integer', 'reporting' => 'float'];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(KpiChannel::class, 'kpi_channel_id');
    }
}
