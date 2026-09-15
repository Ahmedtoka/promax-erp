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

    protected static function booted(): void
    {
        // أي حفظ/مسح بالموديل بيفضّي الكاش — المسح بالكويري (`where()->delete()`)
        // مابيمرّش هنا، فالكنترولر والسيدر بينادوا `flush()` صراحة
        static::saved(fn () => static::flush());
        static::deleted(fn () => static::flush());
    }

    /** كل الشرائح مرة واحدة في الريكوست — الجدول صغير (عشرات الصفوف) */
    private static ?\Illuminate\Support\Collection $all = null;

    /** بعد أي حفظ من شاشة الإعدادات — عشان الحاسبة تقرا الجديد في نفس الريكوست */
    public static function flush(): void
    {
        static::$all = null;
    }

    /**
     * بحث الشريحة — MATCH بنمط أقرب أصغر.
     *
     * ⚠️ في الذاكرة مش كويري (تدقيق الأداء ١٥/٩): كانت كويري لكل مؤشر
     * لكل مندوب — 13 × 14 = 189 كويري على شاشة العمولات و`/api/manager/kpi`.
     */
    public static function lookup(string $kind, ?int $channelId, float $x): float
    {
        static::$all ??= static::query()->orderByDesc('from_value')->get();

        $row = static::$all->first(fn (self $b) => $b->kind === $kind
            && ($kind === 'rate'
                ? (int) $b->kpi_channel_id === (int) $channelId
                : $b->kpi_channel_id === null)
            && (float) $b->from_value <= $x);

        return $row === null ? 0.0 : (float) $row->value;
    }
}
