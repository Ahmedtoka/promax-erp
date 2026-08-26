<?php

namespace App\Console\Commands;

use App\Services\Itrack;
use Illuminate\Console\Command;

/**
 * بولينج مواقع أجهزة تتبع العربيات (iTrack — ٢٦/٨).
 *
 * بيشتغل كل دقيقة من السكيدولر (routes/console.php) — ولو الحساب
 * مش متظبط في الإعدادات بيخرج في صمت من غير أي نداء للمنصة.
 * قراءة وتحديث أعمدة بس — مفيش قيود ولا حالة بتتغير، فمفيش حارس تكرار.
 */
class ItrackPoll extends Command
{
    protected $signature = 'promax:itrack-poll';

    protected $description = 'سحب آخر مواقع أجهزة التتبع من منصة iTrack وتحديث gps_devices';

    public function handle(): int
    {
        if (! Itrack::enabled()) {
            $this->line('iTrack مش متظبط في الإعدادات — مفيش حاجة تتعمل.');

            return self::SUCCESS;
        }

        $r = Itrack::pollOnce();

        if ($r['error']) {
            $this->error('⚠️ '.$r['error']);

            return self::FAILURE;
        }

        $this->info("✅ اتحدث {$r['updated']} جهاز".($r['nofix'] > 0 ? " ({$r['nofix']} من غير فيكس GPS)" : ''));

        return self::SUCCESS;
    }
}
