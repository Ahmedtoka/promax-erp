<?php

namespace App\Console\Commands;

use App\Services\Hr\PayrollGl;
use Illuminate\Console\Command;

/**
 * حسابات المرتبات وقواعد ترحيلها في الدفتر (Lane 7) — آمن يتعاد، بيضيف الناقص بس.
 *
 *   php artisan promax:hr-gl-seed --dry-run
 */
class HrGlSeed extends Command
{
    protected $signature = 'promax:hr-gl-seed {--dry-run : Count only, write nothing}';

    protected $description = 'Payroll GL accounts (advances, salaries/insurance/tax payable, employer insurance) and posting rules - missing rows only';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $r = PayrollGl::ensureAccounts($dry);

        $this->info($dry ? 'DRY RUN - nothing written' : 'Done');
        $this->line('Accounts created: '.$r['created']);
        $this->line('Posting rules created: '.$r['rules']);

        return self::SUCCESS;
    }
}
