<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * يوم حضور واحد لموظف واحد.
 *
 * ⚠️ **الأرقام هنا محسوبة مش مدخلة.** `worked_minutes` و
 * `break_minutes` بيتحسبوا من `attendance_punches` في
 * `App\Services\Attendance::recalculate()`. أي كود بيعدّلهم بإيده
 * بيكسر التطابق بين السجل والمحصلة — وده أول حاجة الموظف بيشكّك
 * فيها لما يشوف ساعاته.
 */
class AttendanceDay extends Model
{
    use HasFactory;

    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_AUTO = 'auto';

    protected $fillable = [
        'user_id', 'date', 'first_in_at', 'last_out_at',
        'worked_minutes', 'break_minutes', 'sessions', 'status',
        'approved_minutes', 'approved_by', 'approved_at', 'note',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'first_in_at' => 'datetime',
            'last_out_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function punches(): HasMany
    {
        return $this->hasMany(AttendancePunch::class)->orderBy('at');
    }

    /**
     * آخر بانش في اليوم — **المصدر الوحيد لحالة الموظف**.
     *
     * ⚠️ **`reorder()` مش اختيارية — دي كانت أخطر باج في الموديول**
     * (إصلاح 2026-08-08).
     *
     * العلاقة `punches()` عليها `orderBy('at')` تصاعدي. إضافة
     * `->latest('at')` عليها **مابتلغيش** الترتيب الأول — بتضيف عليه:
     *
     *     ORDER BY at ASC, at DESC   ← الأول هو اللي بيحكم
     *
     * يعني `first()` كانت بترجّع **أقدم** بانش مش أحدث واحد. والنتيجة
     * إن الحالة كانت بتتقري من أول بانش في اليوم (`in` دايماً):
     *
     *   • الشاشة بتقول «شغال دلوقتي» بعد الانصراف
     *   • الأزرار مابتتقلبش — «خد بريك» بتفضل مكانها بعد البريك
     *   • آلة الحالات بتقبل بريك بعد انصراف (الحالة «working» غلط)
     *   • العدّاد بيفضل ماشي للأبد لأن `openSince()` بترجّع أول `in`
     *
     * `reorder()` بتمسح الترتيب القديم قبل ما تحط الجديد.
     */
    public function lastPunch(): ?AttendancePunch
    {
        return $this->punches()
            ->reorder()
            ->orderByDesc('at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * حالة الموظف دلوقتي — **من آخر بانش مش من عمود**.
     *
     * بترجع: `working` · `break` · `off`
     */
    public function state(): string
    {
        $last = $this->lastPunch();

        return match ($last?->type) {
            AttendancePunch::IN, AttendancePunch::BACK => 'working',
            AttendancePunch::BREAK => 'break',
            default => 'off',
        };
    }

    /**
     * الدقايق اللي هيتحاسب عليها — الاعتماد بيغلب المحسوب.
     *
     * ⚠️ اللحظي مش المخزّن — يوم لسه مفتوح لازم يعدّ لحد دلوقتي في
     * إجمالي السجل، وإلا مجموع الشهر بيبقى ناقص شيفت النهارده.
     */
    public function payableMinutes(): int
    {
        return $this->approved_minutes ?? $this->liveMinutes();
    }

    /** «7:45» — الشاشات كلها بتعرض كده */
    public static function hhmm(int $minutes): string
    {
        return sprintf('%d:%02d', intdiv(max($minutes, 0), 60), max($minutes, 0) % 60);
    }

    /** ⚠️ لحظي — كل الشاشات بتعرض منه */
    public function workedLabel(): string
    {
        return self::hhmm($this->liveMinutes());
    }

    /**
     * ⚠️ **الاسم مش `needsReview`** (إصلاح 2026-08-08). كان فيه ميثود
     * إنستانس بنفس اسم السكوب — و`AttendanceDay::needsReview()` في
     * PHP بتحاول تنادي الإنستانس ستاتيك وترمي «Non-static method
     * cannot be called statically»، ومابتوصلش لـ`__callStatic` اللي
     * لارافيل بتحوّل بيه السكوبات أصلاً. القاعدة: **ممنوع اسم ميثود
     * يطابق اسم سكوب في نفس الموديل.**
     */
    public function isPendingReview(): bool
    {
        return $this->status === self::STATUS_AUTO && $this->approved_at === null;
    }

    /** الأيام اللي السيستم قفلها والمدير لسه ماراجعهاش */
    public function scopeNeedsReview(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_AUTO)->whereNull('approved_at');
    }

    /**
     * الدقايق المشتغلة **دلوقتي** — المخزّن + الفترة المفتوحة.
     *
     * ⚠️ **`worked_minutes` بيخزّن الفترات المقفولة بس** (قرار
     * 2026-08-08). كان بيخزّن الفترة المفتوحة كمان لحد لحظة الحفظ،
     * فالعدّاد كان بيقف عند آخر بانش ومايتحركش — الموظف بيشتغل
     * ساعتين والشاشة بتقول 0:00 لأن مفيش بانش تاني حصل.
     *
     * الفصل ده بيخلّي المخزّن **حقيقة ثابتة** والمحسوب **لحظي**،
     * وكل الشاشات بتنادي هنا بدل ما تقرا العمود مباشرة.
     */
    public function liveMinutes(): int
    {
        $extra = 0;

        if ($this->status === self::STATUS_OPEN) {
            $last = $this->lastPunch();

            if ($last !== null
                && in_array($last->type, [AttendancePunch::IN, AttendancePunch::BACK], true)) {
                $extra = max((int) $last->at->diffInMinutes(now(), absolute: false), 0);
            }
        }

        return $this->worked_minutes + $extra;
    }

    /** آخر بانش شغل مفتوح — الأبلكيشن بيعدّ منه محلياً */
    public function openSince(): ?\Carbon\CarbonInterface
    {
        if ($this->status !== self::STATUS_OPEN) {
            return null;
        }

        $last = $this->lastPunch();

        return $last !== null
            && in_array($last->type, [AttendancePunch::IN, AttendancePunch::BACK], true)
                ? $last->at
                : null;
    }
}
