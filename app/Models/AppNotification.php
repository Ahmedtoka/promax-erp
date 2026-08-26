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

    protected $fillable = ['user_id', 'title', 'body', 'link', 'is_good', 'read_at'];

    /**
     * ⚠️ **الوجهة نص قصير مش URL.** الأبلكيشن بيترجمه لشاشة:
     * `po:12` · `pick:7` · `request:3` · `replenishment:9` ·
     * `custody` · `attendance` · `home`. مفتاح مش معروف = الرئيسية،
     * فنسخة أبلكيشن قديمة مابتكراشش على وجهة جديدة.
     */
    public static function poLink(int $id): string
    {
        return 'po:'.$id;
    }

    public static function pickLink(int $id): string
    {
        return 'pick:'.$id;
    }

    /** طلب ريفيل — بيفتح تاب الريفيل عند البروموتر والمدير */
    public static function replenishmentLink(int $id): string
    {
        return 'replenishment:'.$id;
    }

    /** طلب عميل جديد — بيفتح تاب الموافقات عند المدير */
    public static function requestLink(int $id): string
    {
        return 'request:'.$id;
    }

    /** مهمة إدارية (٢٦/٨) — كل أطرافها ناس داش بورد فالوجهة ويب بس */
    public static function taskLink(int $id): string
    {
        return 'task:'.$id;
    }

    /**
     * ترجمة نفس الوجهة القصيرة لصفحة ويب — للجرس في الداش بورد
     * (٩ أغسطس ٢٠٢٦). نفس مبدأ الأبلكيشن: وجهة مش معروفة = null
     * والجرس بيعرض الإشعار من غير لينك بدل ما يرمي 404.
     */
    public function webUrl(): ?string
    {
        $link = (string) $this->link;
        [$kind, $id] = array_pad(explode(':', $link, 2), 2, null);

        return match ($kind) {
            'po' => route('ops.pos', ['q' => '']).'#po-'.$id,
            'pick' => route('wh.picks'),
            'request' => route('ops.requests'),
            'replenishment' => route('ops.replenishments'),
            'collections' => route('erp.collections'),
            // ⚠️ **بيفتح على «المتأكدة»** — الإشعار بيقول «المندوب ضبط
            // لوكيشن فلان»، والمدير المفروض يشوف اللي اتضبط فعلاً.
            // الفلتر الافتراضي (`from_visit`) كان هيوديه لطابور
            // المستنية اللي العميل ده **خرج منه** لحظتها.
            'client_locations' => route('erp.client_locations', ['show' => 'from_app']),
            'custody' => route('ops.handout'),
            // مهمة إدارية (٢٦/٨) — صفحة المهمة بشاتها
            'task' => $id ? route('erp.tasks.show', $id) : route('erp.tasks'),
            default => null,
        };
    }

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
        ?string $link = null,
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
            'link' => $link,
            'is_good' => $good,
        ]);

        // ⚠️ **الدفع للتليفون من هنا بالظبط** (2026-08-07) — نقطة
        // واحدة لكل إشعارات السيستم، فأي إشعار جديد بيتبعت أوتوماتيك
        // من غير ما حد يفتكر ينادي الدفع. الخدمة بتتخطى بأمان لو
        // فاير بيز لسه مااتظبطش، والشبكة مابتوقّفش العملية الأصلية.
        // ⚠️ **`link` في الـ`data` كمان** — الضغط على إشعار النظام
        // (والأبلكيشن مقفول) بيوصل لـ`getInitialMessage` في الموبايل،
        // وهو بيقرا الوجهة من هنا. من غيرها الإشعار بيفتح الرئيسية.
        \App\Services\Push::toUser($user, $title, $body, [
            'id' => (string) $note->id,
            'good' => $good ? '1' : '0',
            'link' => (string) ($link ?? ''),
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
