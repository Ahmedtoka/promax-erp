<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * شريحة KPI (٢٣ أغسطس ٢٠٢٦):
 *   • `multiplier` — الدرجة من X ← معامل الأداء (0←0.7 · 30←0.8 · 40←0.9 · 50←1)
 *   • `rate` — نسبة تحقيق التارجت من X ← النسبة الأساسية (بالقناة)
 *
 * الاختيار زي MATCH(..,..,1) في الإكسيل: أكبر شريحة from_value ≤ القيمة.
 */
class KpiBand extends Model
{
    protected $fillable = ['kind', 'kpi_channel_id', 'from_value', 'value'];

    protected function casts(): array
    {
        return ['from_value' => 'float', 'value' => 'float'];
    }

    /** بحث الشريحة — MATCH بنمط أقرب أصغر */
    public static function lookup(string $kind, ?int $channelId, float $x): float
    {
        $row = static::where('kind', $kind)
            ->when($kind === 'rate',
                fn ($q) => $q->where('kpi_channel_id', $channelId),
                fn ($q) => $q->whereNull('kpi_channel_id'))
            ->where('from_value', '<=', $x)
            ->orderByDesc('from_value')
            ->first();

        return $row === null ? 0.0 : (float) $row->value;
    }
}
