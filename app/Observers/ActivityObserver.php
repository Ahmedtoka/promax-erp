<?php

namespace App\Observers;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;

/**
 * ═══════════════════════════════════════════════════════════════
 * المراقب العام — بيسجّل أي إنشاء/تعديل/مسح على الموديلز المسجّلة
 * ═══════════════════════════════════════════════════════════════
 *
 * بيتسكّن على الموديلز في `AppServiceProvider::boot`. مافيش سطر
 * تسجيل واحد متكتوب بالإيد في أي كنترولر — أي حفظة في أي مكان
 * (شاشة، أمر، استيراد، API) بتتسجل أوتوماتيك.
 *
 * ⚠️ **الحقول الحساسة والضوضاء مستبعدة**: الباسورد والتوكن عمر ما
 * يتسجلوا، والأعمدة المجمّعة (purchases/balance/…) بتتغير مع كل
 * فاتورة — لو اتسجلت، السجل هيبقى ألف صف «الرصيد اتغير» ومحدش
 * هيلاقي التعديل الحقيقي وسطهم.
 *
 * ⚠️ `updateQuietly` بيتخطى المراقب — مقصود: إعادة الحساب الداخلية
 * (`recalculate`) مش حركة يوزر.
 */
class ActivityObserver
{
    /** ممنوع تتسجل نهائياً */
    private const SECRET = ['password', 'remember_token', 'token', 'api_token'];

    /** أعمدة بتتغير لوحدها مع كل عملية — ضوضاء في السجل */
    private const NOISE = [
        'updated_at', 'created_at', 'last_seen_at', 'last_activity_at',
        'last_payment_at', 'first_activity_at', 'purchases', 'collections',
        'balance', 'returns', 'invoices_count', 'points',
    ];

    public function created(Model $model): void
    {
        $this->write('created', $model, $this->clean($model->getAttributes()));
    }

    public function updated(Model $model): void
    {
        $before = $model->getOriginal();
        $changes = [];

        foreach ($this->clean($model->getChanges()) as $field => $after) {
            $changes[$field] = [$this->short($before[$field] ?? null), $this->short($after)];
        }

        // كل اللي اتغير كان ضوضاء — مفيش حاجة تتسجل
        if ($changes === []) {
            return;
        }

        $this->write('updated', $model, $changes);
    }

    public function deleted(Model $model): void
    {
        $this->write('deleted', $model, []);
    }

    private function write(string $event, Model $model, array $changes): void
    {
        ActivityLog::record($event, [
            'subject_type' => class_basename($model),
            'subject_id' => $model->getKey(),
            'title' => $this->title($model),
            'changes' => $changes ?: null,
        ]);
    }

    /**
     * عنوان مقروء للصف — الاسم أو الرقم أو الكود، وإلا المفتاح.
     * الترتيب مهم: «فاتورة INV-1001» أوضح من «فاتورة #37».
     */
    private function title(Model $model): string
    {
        foreach (['number', 'name', 'code', 'title'] as $field) {
            $v = $model->getAttribute($field);

            if (is_string($v) && $v !== '') {
                return mb_substr($v, 0, 180);
            }
        }

        return '#'.$model->getKey();
    }

    /** @param array<string, mixed> $attrs */
    private function clean(array $attrs): array
    {
        return collect($attrs)
            ->except(array_merge(self::SECRET, self::NOISE))
            ->map(fn ($v) => $this->short($v))
            ->all();
    }

    /** تقصير القيم — JSON طويل بيفجّر حجم الصف */
    private function short(mixed $v): mixed
    {
        if (is_array($v)) {
            return mb_substr(json_encode($v, JSON_UNESCAPED_UNICODE) ?: '', 0, 200);
        }

        if (is_string($v)) {
            return mb_substr($v, 0, 200);
        }

        return $v;
    }
}
