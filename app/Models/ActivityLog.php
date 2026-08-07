<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * سجل حركة اليوزرات — مين عمل إيه وإمتى.
 *
 * ⚠️ **الكتابة هنا ممنوع تفشّل العملية الأصلية.** لو التسجيل رمى
 * استثناء (الجدول لسه مااتعملش، عمود ناقص، الديسك اتملى) العميل
 * اللي المستخدم بيحفظه كان هيضيع. كل النداءات ملفوفة `rescue`.
 */
class ActivityLog extends Model
{
    protected $fillable = [
        'user_id', 'user_name', 'role', 'event', 'subject_type', 'subject_id',
        'title', 'changes', 'url', 'method', 'ip', 'agent',
    ];

    protected function casts(): array
    {
        return ['changes' => 'array'];
    }

    /** الأحداث اللي بتتسجل — للفلاتر والترجمة */
    public const EVENTS = ['created', 'updated', 'deleted', 'login', 'logout', 'viewed', 'action'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * تسجيل حدث — الطريقة الوحيدة للكتابة.
     *
     * @param  array<string, mixed>  $extra
     */
    public static function record(string $event, array $extra = []): void
    {
        rescue(function () use ($event, $extra) {
            $user = Auth::user();
            $request = request();

            static::create([
                'user_id' => $user?->id,
                'user_name' => $user?->name,
                'role' => $user?->role,
                'event' => $event,
                'url' => $request?->path() ? mb_substr((string) $request->path(), 0, 300) : null,
                'method' => $request?->method(),
                'ip' => $request?->ip(),
                'agent' => mb_substr((string) $request?->userAgent(), 0, 200),
            ] + $extra);
        }, null, false);
    }

    /** وصف مقروء للحدث — بيستخدم في الشاشة والتصدير */
    public function label(): string
    {
        $subject = $this->subject_type
            ? __('audit.model_'.$this->subject_type, [], null) : null;

        return trim(($subject ?: $this->subject_type ?: '').' '.($this->title ?: ''));
    }

    /** كام حقل اتغير فعلاً */
    public function changedCount(): int
    {
        return is_array($this->changes) ? count($this->changes) : 0;
    }
}
