<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * مهمة إدارية (Task Management — ٢٦/٨).
 *
 * الدورة: open → submitted («تم التسليم») → approved.
 * الرفض بيرجّعها open وبيعدّ في rejections — التاريخ كله في الشات.
 */
class Task extends Model
{
    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public const STATUSES = ['open', 'submitted', 'approved'];

    protected $fillable = [
        'title', 'description', 'assigned_to', 'created_by',
        'priority', 'deadline', 'status', 'submitted_at',
        'approved_at', 'rejections',
    ];

    protected $casts = [
        'deadline' => 'datetime',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function comments()
    {
        return $this->hasMany(TaskComment::class)->orderBy('id');
    }

    public function files()
    {
        return $this->hasMany(TaskFile::class);
    }

    /** متأخرة = الموعد فات وهي لسه مفتوحة (المُسلَّمة مش متأخرة على الموظف) */
    public function isLate(): bool
    {
        return $this->status === 'open'
            && $this->deadline !== null
            && $this->deadline->lt(now());
    }

    /**
     * سكوب الرؤية: طرفَي المهمة بس — المكلَّف والمكلِّف.
     * الأدمن بيشوف الكل. (scope مش static — درس ٢٢/٨.)
     */
    public function scopeVisibleTo($q, User $user)
    {
        if ($user->role === 'admin') {
            return $q;
        }

        return $q->where(fn ($w) => $w
            ->where('assigned_to', $user->id)
            ->orWhere('created_by', $user->id));
    }

    /** بادج الأولوية — لون جاهز للفيو */
    public function priorityBadge(): string
    {
        return match ($this->priority) {
            'urgent' => 'b-red',
            'high' => 'b-orange',
            'low' => 'b-gray',
            default => 'b-blue',
        };
    }
}
