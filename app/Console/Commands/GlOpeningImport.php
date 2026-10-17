<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Gl\OpeningBalances;
use Illuminate\Console\Command;

/**
 * مديونيات ما قبل السيستم من CSV (كود العميل · المبلغ · التاريخ).
 * من غير `--post` معاينة بس — مفيش كتابة. التفاصيل في `OpeningBalances`.
 */
class GlOpeningImport extends Command
{
    protected $signature = 'promax:gl-opening {file : CSV — client code, amount, date}
        {--post : Write the opening entries (default is preview only)}
        {--by= : Code of the admin recording the import (required with --post)}';

    protected $description = 'Preview / post client opening balances (pre-system receivables) from a CSV';

    public function handle(OpeningBalances $svc): int
    {
        $file = (string) $this->argument('file');
        if (! is_file($file)) {
            $this->error('File not found: '.$file);

            return self::FAILURE;
        }

        $p = $svc->preview($file);
        $this->table(['#', 'code', 'client', 'amount', 'date', 'status'], array_map(fn ($r) => [
            $r['line'], $r['code'], $r['client'] ?? '—', number_format($r['amount'], 2), $r['date'] ?? '—',
            __('gl.opening_status_'.$r['status']),
        ], $p['rows']));
        $this->line(sprintf('ready: %d · errors/skipped: %d · total ready: %s · dated before gl_start_date: %d',
            $p['ok'], $p['errors'], number_format($p['total'], 2), $p['before_gl_start']));

        if (! $this->option('post')) {
            $this->info('Preview only — nothing written. Re-run with --post --by=<admin code> to post.');

            return self::SUCCESS;
        }

        $by = User::where('code', (string) $this->option('by'))->where('role', 'admin')->where('active', true)->first();
        if ($by === null) {
            $this->error('--by must be the code of an active admin.');

            return self::FAILURE;
        }
        if (! $this->confirm('Post '.$p['ok'].' opening entries totalling '.number_format($p['total'], 2).' on '.config('database.connections.mysql.database').'?')) {
            return self::FAILURE;
        }

        try {
            $n = $svc->post($file, $by);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info('Posted '.$n.' opening entries.');

        return self::SUCCESS;
    }
}
