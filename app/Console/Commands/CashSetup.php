<?php

namespace App\Console\Commands;

use App\Services\Cash\CashGlSeed;
use Illuminate\Console\Command;

/**
 * حسابات وقواعد وبنود Lane 4 على الشغّال (L4-D13) — آمن يتعاد، و`--dry-run`
 * بيعرض الأعداد ويرجّع كل حاجة.
 *
 *   php artisan promax:cash-setup --dry-run
 */
class CashSetup extends Command
{
    protected $signature = 'promax:cash-setup {--dry-run : Show only, write nothing}';

    protected $description = 'Lane 4 GL accounts (by system key), posting rules and expense categories - additive only';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        // ⚠️ من غير شجرة الحسابات (الإعداد/المعالج لسه ماتعملش) الحسابات
        // الجديدة كانت هتتولد يتيمة من غير أب — الشجرة الأول
        $empty = ! \App\Models\Gl\GlAccount::query()->exists();
        if ($empty && ! $dry) {
            $this->error('The chart of accounts is empty - set up the GL tree first (GL wizard or GlSeeder), then run this again. Nothing written.');

            return self::FAILURE;
        }
        $r = CashGlSeed::run($dry);
        if ($empty) {
            $this->warn('  * chart of accounts is empty - a real run is refused until the GL tree exists');
        }

        $this->info($dry ? 'DRY RUN - nothing written' : 'Done');
        $this->line('Accounts created: '.$r['accounts_created']);
        $this->line('Rules created: '.$r['rules_created']);
        $this->line('Categories created: '.$r['categories_created']);
        foreach ($r['notes'] as $note) {
            $this->warn('  * '.$note);
        }

        return self::SUCCESS;
    }
}
