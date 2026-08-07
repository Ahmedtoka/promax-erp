<?php

namespace App\Console\Commands;

use App\Models\AppNotification;
use App\Models\AttendanceDay;
use App\Services\Attendance;
use Illuminate\Console\Command;

/**
 * ═══════════════════════════════════════════════════════════════
 * قفل الشيفتات المنسية — بيشتغل بعد منتصف الليل (2026-08-08)
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **الموظف اللي نسي يقفل مش بيتحاسب على 30 ساعة.** من غير الأمر
 * ده، اليوم بيفضل مفتوح والعداد ماشي — وأول ما يفتح الأبلكيشن تاني
 * يلاقي رقم مالوش معنى، والمدير مش عارف الحقيقة.
 *
 * الأمر بيقفل اليوم بانصراف مسجّل على **آخر ثانية في يوم الشيفت**،
 * وبيعلّم الحالة `auto` — يعني «السيستم قفلها مش هو». الشاشة بتفرز
 * الأيام دي للمدير عشان يعتمد الساعات الحقيقية أو يعدّلها.
 *
 * ⚠️ **الإشعار مش رفاهية.** الموظف لازم يعرف إنه نسي، وإن اللي
 * اتسجّل عليه محتاج مراجعة — قبل ما يتفاجأ آخر الشهر.
 *
 * التشغيل: كرون يومي 12:05 ص (شوف `routes/console.php`).
 */
class CloseAttendance extends Command
{
    protected $signature = 'promax:attendance-close
                            {--date= : اليوم اللي هيتقفل (الافتراضي: إمبارح)}
                            {--dry : عرض من غير تنفيذ}';

    protected $description = 'قفل أيام الحضور اللي الموظفين نسيوا يقفلوها';

    public function handle(): int
    {
        // ⚠️ **إمبارح مش النهارده.** الأمر بيشتغل بعد منتصف الليل،
        // فاليوم اللي محتاج قفل هو اللي خلص لسه. لو اشتغل على
        // النهارده كان هيقفل شيفتات ناس لسه بتشتغل.
        $date = $this->option('date') ?: today()->subDay()->toDateString();
        $dry = (bool) $this->option('dry');

        $days = AttendanceDay::with('user')
            ->whereDate('date', $date)
            ->where('status', AttendanceDay::STATUS_OPEN)
            ->get();

        if ($days->isEmpty()) {
            $this->info("مفيش شيفتات مفتوحة في {$date}.");

            return self::SUCCESS;
        }

        $this->line("شيفتات مفتوحة في {$date}: ".$days->count());
        $this->newLine();

        $closed = 0;

        foreach ($days as $day) {
            $state = $day->state();
            $name = $day->user?->displayName() ?? "#{$day->user_id}";

            if ($dry) {
                $this->line(sprintf('   %-28s %s', $name, $state));

                continue;
            }

            if (! Attendance::autoClose($day)) {
                // مافيش حضور أصلاً — اتقفل من غير إشعار
                continue;
            }

            $closed++;
            $fresh = $day->fresh();

            $this->line(sprintf('   ✓ %-26s %s', $name, $fresh->workedLabel()));

            if ($day->user !== null) {
                AppNotification::send(
                    $day->user,
                    fn () => __('hr.notif_forgot_title'),
                    fn () => __('hr.notif_forgot_body', [
                        'date' => $fresh->date->format('d/m'),
                        't' => $fresh->workedLabel(),
                    ]),
                    good: false,
                );
            }
        }

        $this->newLine();
        $this->info($dry ? 'عرض بس — مفيش حاجة اتقفلت.' : "اتقفل {$closed} شيفت.");

        return self::SUCCESS;
    }
}
