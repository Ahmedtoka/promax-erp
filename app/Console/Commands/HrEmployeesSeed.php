<?php

namespace App\Console\Commands;

use App\Services\Hr\Employees;
use Illuminate\Console\Command;

/**
 * ملف موظف لكل يوزر نشط مالوش ملف (Lane 7) — آمن يتعاد.
 *
 *   php artisan promax:hr-employees-seed --dry-run
 */
class HrEmployeesSeed extends Command
{
    protected $signature = 'promax:hr-employees-seed {--dry-run : Count only, write nothing}';

    protected $description = 'One employee file per active user without one, then link old attendance rows - additive only';

    public function handle(Employees $employees): int
    {
        $dry = (bool) $this->option('dry-run');
        $r = $employees->seedFromUsers($dry);

        $this->info($dry ? 'DRY RUN - nothing written' : 'Done');
        $this->line('Created: '.$r['created']);
        $this->line('Skipped (already have a file): '.$r['skipped']);
        $this->line('Attendance days linked: '.$r['linked_days']);

        return self::SUCCESS;
    }
}
