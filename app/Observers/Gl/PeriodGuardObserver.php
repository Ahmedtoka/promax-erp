<?php

namespace App\Observers\Gl;

use App\Support\PeriodGuard;
use Illuminate\Database\Eloquent\Model;

/**
 * حارس الشهر المقفول على الموديلات المراقبة (`PeriodGuard::watch`).
 *
 * ⚠️ **منفصل عن أوبزرفرات الدفتر وبيرمي عن قصد** — أوبزرفرات الدفتر
 * بتبلع أي خطأ (المستند مايقعش عشان القيد)، أما هنا الرفض هو المطلوب:
 * `ValidationException` بتطلع للكنترولر وترجع للفورم برسالة بتسمّي
 * الشهر. مابيشتغلش غير في مود `reject` (طلبات الويب).
 */
class PeriodGuardObserver
{
    public function creating(Model $m): void
    {
        if (PeriodGuard::mode() !== PeriodGuard::MODE_REJECT) {
            return;
        }
        // `created_at` لسه مااتحطش وقت `creating` — المستند الجديد = النهاردة
        $date = PeriodGuard::dateFor($m) ?? (PeriodGuard::watched()[$m::class]['date'] === 'created_at' ? now() : null);
        PeriodGuard::assertOpen($date);
    }

    public function updating(Model $m): void
    {
        if (PeriodGuard::mode() !== PeriodGuard::MODE_REJECT || ! PeriodGuard::touchesGuarded($m)) {
            return;
        }
        PeriodGuard::assertOpen(PeriodGuard::dateFor($m, original: true));
        PeriodGuard::assertOpen(PeriodGuard::dateFor($m));
    }

    public function deleting(Model $m): void
    {
        if (PeriodGuard::mode() !== PeriodGuard::MODE_REJECT) {
            return;
        }
        PeriodGuard::assertOpen(PeriodGuard::dateFor($m, original: true));
    }
}
