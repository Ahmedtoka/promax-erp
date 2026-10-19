<?php

namespace App\Console\Commands;

use App\Exceptions\Rejected;
use App\Services\Cash\Depreciation;
use Illuminate\Console\Command;

/**
 * الإهلاك الشهري للأصول الثابتة (L4-D11) — الافتراضي الشهر اللي فات
 * (بتوقيت القاهرة)، والتشغيل مرتين لنفس الشهر مابيكررش. الشهر المقفول مرفوض.
 *
 *   php artisan promax:depreciate --month=2026-09 --dry-run
 */
class Depreciate extends Command
{
    protected $signature = 'promax:depreciate {--month= : YYYY-MM (default: last month)} {--dry-run : Show only, write nothing}';

    protected $description = 'Monthly straight-line depreciation of fixed assets - one row per asset per month, idempotent';

    public function handle(Depreciation $svc): int
    {
        $month = (string) ($this->option('month') ?: now('Africa/Cairo')->subMonthNoOverflow()->format('Y-m'));
        $dry = (bool) $this->option('dry-run');

        try {
            $r = $svc->run($month, null, $dry);
        } catch (Rejected|\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(($dry ? 'DRY RUN - nothing written - ' : '').'Month '.$month);
        $this->line('Created: '.$r['created']);
        $this->line('Skipped: '.$r['skipped']);
        $this->line('Amount: '.number_format($r['amount'], 2, '.', ''));

        return self::SUCCESS;
    }
}
