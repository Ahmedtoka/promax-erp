<?php

namespace App\Console\Commands;

use App\Services\Handover;
use Illuminate\Console\Command;

/**
 * يولّد موديولز وشاشات أداة التسليم من السايدبار (`Access::NAV`).
 *
 * ⚠️ إضافة بس — آمن يتشغّل كل ما شاشة جديدة تدخل السايدبار، ومابيلمسش
 * أي اسم أو حالة المالك عدّلها.
 */
class HandoverSeed extends Command
{
    protected $signature = 'promax:handover-seed {--dry-run : اعرض اللي هيتضاف من غير ما تكتب}';

    protected $description = 'Seed handover modules/screens from the sidebar groups (adds missing rows only)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $out = Handover::seedFromNav($dry);

        $this->info(($dry ? '[dry-run] ' : '')
            .'modules added: '.$out['modules_added'].' · screens added: '.$out['screens_added']);

        return self::SUCCESS;
    }
}
