<?php

namespace App\Console\Commands;

use App\Services\Hr\Leave;
use Illuminate\Console\Command;

/**
 * رصيد إجازة افتتاحي لكل موظف نشط لكل نوع (Lane 7) — آمن يتعاد.
 *
 *   php artisan promax:hr-leave-seed --year=2026 --dry-run
 */
class HrLeaveSeed extends Command
{
    protected $signature = 'promax:hr-leave-seed {--year= : Balance year, default this year} {--dry-run : Count only, write nothing}';

    protected $description = 'Opening leave balance per active employee per type from HR settings - missing rows only';

    public function handle(): int
    {
        $year = (int) ($this->option('year') ?: now()->year);

        if ($year < 2000 || $year > 2100) {
            $this->error('Bad --year');

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $r = Leave::seedOpening($year, $dry);

        $this->info($dry ? 'DRY RUN - nothing written' : 'Done');
        $this->line('Year: '.$year);
        $this->line('Balances created: '.$r['created']);

        return self::SUCCESS;
    }
}
