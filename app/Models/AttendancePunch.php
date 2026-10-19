<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ضغطة زرار واحدة في سجل الحضور.
 *
 * ⚠️ **السطر ده مابيتعدلش ومابيتمسحش.** ده الدليل الخام على
 * حركة الموظف — لو المدير عايز يغيّر الساعات بيعتمد رقم مختلف على
 * `attendance_days.approved_minutes`، والسجل بيفضل شاهد.
 */
class AttendancePunch extends Model
{
    use HasFactory;

    public const IN = 'in';
    public const BREAK = 'break';
    public const BACK = 'back';
    public const OUT = 'out';

    /** [المسمى الافتراضي، اللون، الأيقونة] */
    public const TYPES = [
        self::IN => ['حضور', '#16A34A', '🟢'],
        self::BREAK => ['بريك', '#B86E00', '⏸️'],
        self::BACK => ['رجع من البريك', '#2563EB', '▶️'],
        self::OUT => ['انصراف', '#B00020', '🔴'],
    ];

    /**
     * مصدر البانش (Lane 7): الأبلكيشن · زرار الهيدر في الداش بورد · المشرف
     * للعامل اللي مالوش لوجين. الصفوف القديمة كلها `app`.
     */
    public const SOURCES = ['app', 'dashboard', 'supervisor'];

    protected $fillable = [
        'attendance_day_id', 'user_id', 'type', 'at', 'lat', 'lng', 'auto',
        // انصراف إداري من الداش بورد (١١/٨) — مين اللي سجّله
        'forced_by',
        // Lane 7: الموظف ومصدر البانش والجهاز ومين سجّله (المشرف)
        'employee_id', 'source', 'device', 'ip', 'recorded_by',
    ];

    protected function casts(): array
    {
        return ['at' => 'datetime', 'auto' => 'boolean'];
    }

    public function day(): BelongsTo
    {
        return $this->belongsTo(AttendanceDay::class, 'attendance_day_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** المشرف اللي سجّل البانش للعامل — null لو الموظف سجّل بنفسه */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function sourceLabel(): string
    {
        $key = 'hrm.source_'.($this->source ?? 'app');

        return \Illuminate\Support\Facades\Lang::has($key) ? __($key) : (string) $this->source;
    }

    /** الأدمن/المدير اللي سجّل الانصراف الإداري — null للبانش العادي */
    public function forcedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'forced_by');
    }

    public function typeLabel(): string
    {
        $key = 'hr.punch_'.$this->type;

        return \Illuminate\Support\Facades\Lang::has($key)
            ? __($key)
            : (self::TYPES[$this->type][0] ?? $this->type);
    }

    public function color(): string
    {
        return self::TYPES[$this->type][1] ?? '#6B6B7B';
    }

    public function icon(): string
    {
        return self::TYPES[$this->type][2] ?? '•';
    }
}
