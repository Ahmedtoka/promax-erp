<?php

namespace App\Console\Commands;

use App\Services\Gl\FoundationSeed;
use Illuminate\Console\Command;

/**
 * مراكز التكلفة والخزنتين (خطة القفل ١٩/١٠) — آمن يتعاد، و`--dry-run`
 * بيعرض اللي هيتعمل ويرجّع كل حاجة.
 *
 *   php artisan promax:cost-centers-seed --dry-run
 *   php artisan promax:cost-centers-seed --maadi-warehouse=WHK --factory=MAIN,TENTH
 */
class CostCentersSeed extends Command
{
    protected $signature = 'promax:cost-centers-seed
        {--dry-run : Show only, write nothing}
        {--maadi-warehouse= : Maadi warehouse code if it is not MAADI}
        {--factory= : Comma-separated factory warehouse codes (default: every factory warehouse except Maadi)}';

    protected $description = 'Cost centers (company / factory / Maadi) and safes MAADI-MAIN and BANK-1 - additive only';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $factory = $this->option('factory');
        $codes = $factory !== null && $factory !== ''
            ? array_values(array_filter(array_map('trim', explode(',', $factory))))
            : null;

        try {
            $r = FoundationSeed::run($dry, $this->option('maadi-warehouse'), $codes);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info($dry ? 'DRY RUN - nothing written' : 'Done');
        foreach (['created' => 'Created', 'linked' => 'Linked', 'unlinked' => 'Not linked (check)'] as $key => $label) {
            $this->line($label.': '.($r[$key] === [] ? '-' : count($r[$key])));
            foreach ($r[$key] as $row) {
                $this->line('  * '.$row);
            }
        }

        return self::SUCCESS;
    }
}
