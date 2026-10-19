<?php

namespace App\Console\Commands;

use App\Services\Costing\CostingGlSeed;
use Illuminate\Console\Command;

/**
 * حسابات وقواعد التكاليف (الخط ٩، D1–D3) — آمن يتعاد، و`--dry-run` بيعرض
 * الأعداد ويرجّع كل حاجة. التشغيل الحقيقي بيفعّل المخزون الدائم.
 *
 *   php artisan promax:costing-gl-seed --dry-run
 */
class CostingGlSeedCommand extends Command
{
    protected $signature = 'promax:costing-gl-seed {--dry-run : Show only, write nothing}';

    protected $description = 'Lane 9 costing GL accounts (by system key) and posting rules - additive only; a real run switches perpetual inventory on';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $why = CostingGlSeed::blocker();
        if ($why !== null && ! $dry) {
            $this->error($why);

            return self::FAILURE;
        }

        $r = CostingGlSeed::run($dry);
        if ($why !== null) {
            $this->warn('  * '.$why);
        }

        $this->info($dry ? 'DRY RUN - nothing written' : 'Done');
        $this->line('Accounts created: '.$r['accounts_created']);
        $this->line('Rules created: '.$r['rules_created']);
        foreach ($r['notes'] as $note) {
            $this->warn('  * '.$note);
        }

        return self::SUCCESS;
    }
}
