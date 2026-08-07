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
     * حالة الموظف دلوقتي — **من آخر بانش مش من عمود**.
     *
     * بترجع: `working` · `break` · `off`
     */
    public function state(): string
    {
        $last = $this->punches()->latest('at')->latest('id')->first();

        return match ($last?->type) {
            AttendancePunch::IN, AttendancePunch::BACK => 'working',
            AttendancePunch::BREAK => 'break',
            default => 'off',
        };
    }

    /** الدقايق اللي هيتحاسب عليها — الاعتماد بيغلب المحسوب */
    public function payableMinutes(): int
    {
        return $this->approved_minutes ?? $this->worked_minutes;
    }

    /** «7:45» — الشاشات كلها بتعرض كده */
    public static function hhmm(int $minutes): string
    {
        return sprintf('%d:%02d', intdiv(max($minutes, 0), 60), max($minutes, 0) % 60);
    }

    public function workedLabel(): string
    {
        return self::hhmm($this->worked_minutes);
    }

    public function needsReview(): bool
    {
        return $this->status === self::STATUS_AUTO && $this->approved_at === null;
    }

    /** الأيام اللي السيستم قفلها والمدير لسه ماراجعهاش */
    public function scopeNeedsReview(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_AUTO)->whereNull('approved_at');
    }
}
