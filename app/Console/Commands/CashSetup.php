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
        $r = CashGlSeed::run($dry);

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
