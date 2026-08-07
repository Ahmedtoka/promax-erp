<?php

namespace App\Models;

use Closure;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\App;

class AppNotification extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'title', 'body', 'is_good', 'read_at'];

    protected function casts(): array
    {
        return ['is_good' => 'boolean', 'read_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * إرسال إشعار لمستخدم.
     * ملاحظة: الاسم "send" مش "push" لأن Eloquent عنده push() أصلاً.
     *
     * النص بيتخزن جاهز في الداتابيز، فلازم يتكتب بلغة اللي هيقراه
     * مش بلغة اللي بعته. عشان كده لو بعتّ Closure فيها __()
     * بتتنفذ وإحنا مضبطين اللغة على users.locale بتاع المستقبِل.
     */
    public static function send(
        ?User $user,
        string|Closure $title,
        string|Closure|null $body = null,
        bool $good = true,
    ): ?self {
        if ($user === null) {
            return null;
        }

        [$title, $body] = static::inUserLocale($user, static fn () => [
            $title instanceof Closure ? $title() : $title,
            $body instanceof Closure ? $body() : $body,
        ]);

        $note = static::create([
            'user_id' => $user->id,
            'title' => $title,
            'body' => $body,
            'is_good' => $good,
        ]);

        // ⚠️ **الدفع للتليفون من هنا بالظبط** (2026-08-07) — نقطة
        // واحدة لكل إشعارات السيستم، فأي إشعار جديد بيتبعت أوتوماتيك
        // من غير ما حد يفتكر ينادي الدفع. الخدمة بتتخطى بأمان لو
        // فاير بيز لسه مااتظبطش، والشبكة مابتوقّفش العملية الأصلية.
        \App\Services\Push::toUser($user, $title, $body, [
            'id' => (string) $note->id,
            'good' => $good ? '1' : '0',
        ]);

        return $note;
    }

    /** تنفيذ الكولباك بلغة اليوزر ورجوع اللغة زي ما كانت بعدها */
    private static function inUserLocale(User $user, Closure $callback): array
    {
        $previous = App::getLocale();
        $locale = $user->locale ?? $previous;

        if ($locale === $previous || ! array_key_exists($locale, User::LOCALES)) {
            return $callback();
        }

        App::setLocale($locale);

        try {
            return $callback();
        } finally {
            App::setLocale($previous);
        }
    }
}
