<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\Costing\CostSync;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * مزامنة طبقة التكلفة من تاريخ (الخط ٩ — D12) وترحيل القيود اللي اتغيرت.
 * آمن يتعاد (الـids ثابتة). `--dry-run` بيعدّ ويرجّع كل حاجة.
 *
 *   php artisan promax:costing-sync --from=2026-08-01 --dry-run
 */
class CostingSyncCommand extends Command
{
    protected $signature = 'promax:costing-sync {--from= : YYYY-MM-DD (default: gl_start_date or the first transaction date)} {--dry-run : Count only, write nothing}';

    protected $description = 'Lane 9: sync sale/return cost entries from a date and repost the changed ones';

    public function handle(): int
    {
        $from = $this->option('from') ?: (Setting::read('gl_start_date') ?: DB::table('transactions')->min('date'));
        if (! $from) {
            $this->info('No transactions - nothing to sync.');

            return self::SUCCESS;
        }
        try {
            $date = Carbon::parse((string) $from)->startOfDay();
        } catch (\Throwable) {
            $this->error('Invalid --from date: '.$from);

            return self::FAILURE;
        }
        $dry = (bool) $this->option('dry-run');

        if ($dry) {
            $level = DB::transactionLevel();
            DB::beginTransaction();
            try {
                $r = CostSync::run($date, false);
            } finally {
                while (DB::transactionLevel() > $level) {
                    DB::rollBack();
                }
            }
        } else {
            $r = CostSync::run($date, true);
        }

        $this->info(($dry ? 'DRY RUN - nothing written. ' : 'Done. ').'From '.$date->toDateString());
        $this->line('Entries synced: '.$r['synced']);
        $this->line('Entries deleted: '.$r['deleted']);

        return self::SUCCESS;
    }
}
