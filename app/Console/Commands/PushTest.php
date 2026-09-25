<?php

namespace App\Console\Commands;

use App\Models\DeviceToken;
use App\Models\User;
use App\Services\Push;
use Illuminate\Console\Command;

/**
 * ═══════════════════════════════════════════════════════════════
 * promax:push-test — فحص إشعارات فاير بيز من أولها لآخرها
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **اتعمل عشان فشل الإرسال صامت.** `Push` ملفوف `rescue` عن قصد
 * (الفاتورة ماتقعش لأن جوجل مش بيرد)، فأي غلطة في الإعداد بتبان
 * بس كسطر في اللوج والموظف مابيوصلوش حاجة.
 *
 * بيفحص بالترتيب: المفتاحين في `.env` · الملف موجود ومقروء وفيه
 * حساب خدمة · رقم المشروع في الملف = `FCM_PROJECT_ID` · جوجل بيدّي
 * توكن وصول · عدد الأجهزة المسجّلة وإصداراتها. ولو اتبعت يوزر، بيبعتله
 * إشعار تجربة على أجهزته.
 *
 *   php artisan promax:push-test
 *   php artisan promax:push-test S-001     (كود الموظف أو إيميله)
 */
class PushTest extends Command
{
    protected $signature = 'promax:push-test {user? : كود الموظف أو إيميله — يبعتله إشعار تجربة}';

    protected $description = 'فحص إعداد إشعارات فاير بيز وإرسال إشعار تجربة';

    public function handle(): int
    {
        $project = (string) config('services.fcm.project');
        $file = (string) config('services.fcm.credentials');

        $this->line('');
        $this->line('  FCM_PROJECT_ID  : '.($project ?: '—'));
        $this->line('  FCM_CREDENTIALS : '.($file ?: '—'));

        if ($project === '' || $file === '') {
            $this->error('  ⛔ المفتاحين لازم يتكتبوا في .env ثم php artisan optimize:clear');

            return self::FAILURE;
        }

        if (! is_file($file) || ! is_readable($file)) {
            $this->error('  ⛔ الملف مش موجود أو PHP مش قادر يقراه — المسار لازم يكون مطلق');

            return self::FAILURE;
        }

        $json = json_decode((string) file_get_contents($file), true);

        if (! is_array($json) || empty($json['client_email']) || empty($json['private_key'])) {
            $this->error('  ⛔ الملف مش ملف حساب خدمة (Generate new private key من Service accounts)');

            return self::FAILURE;
        }

        if (($json['project_id'] ?? '') !== $project) {
            $this->error("  ⛔ الملف تبع مشروع «{$json['project_id']}» و.env بيقول «{$project}»");

            return self::FAILURE;
        }

        $this->info('  ✅ الملف سليم: '.$json['client_email']);

        if (! Push::authOk()) {
            $this->error('  ⛔ جوجل رفض المفتاح — شوف «FCM auth failed» في storage/logs');

            return self::FAILURE;
        }

        $this->info('  ✅ جوجل أدّى توكن وصول');

        $versions = DeviceToken::query()
            ->selectRaw("COALESCE(app_version, '?') v, COUNT(*) n")
            ->groupBy('v')->orderByDesc('n')->pluck('n', 'v');

        $this->line('  الأجهزة المسجّلة: '.$versions->sum().'  '
            .$versions->map(fn ($n, $v) => "$v×$n")->implode('  '));

        $key = (string) $this->argument('user');

        if ($key === '') {
            $this->line('  (ابعت كود موظف عشان يوصله إشعار تجربة)');

            return self::SUCCESS;
        }

        $user = User::where('code', $key)->orWhere('email', $key)->first();

        if ($user === null) {
            $this->error("  ⛔ مفيش موظف بالكود أو الإيميل «{$key}»");

            return self::FAILURE;
        }

        $sent = Push::toUser($user, 'PROMAX', 'إشعار تجربة — لو وصلك يبقى الإشعارات شغالة', ['link' => 'home']);

        if ($sent === 0) {
            $this->warn("  ⚠️ {$user->name}: مفيش جهاز استلم — لازم يفتح الأبلكيشن الجديد ويدخل مرة");

            return self::FAILURE;
        }

        $this->info("  ✅ اتبعت لـ{$sent} جهاز عند {$user->name}");

        return self::SUCCESS;
    }
}
