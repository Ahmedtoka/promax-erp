<?php

namespace App\Console\Commands;

use App\Services\ResetTransactions;
use Illuminate\Console\Command;

/**
 * مسح الترانزاكشنز والماستر داتا بتفضل — نسخة الأوامر من زرار
 * الداش بورد. المنطق كله في `App\Services\ResetTransactions`.
 */
class WipeTransactions extends Command
{
    protected $signature = 'promax:wipe {--force : تنفيذ من غير سؤال تأكيد}';

    protected $description = 'مسح كل الحركة (بيع/مرتجع/تحويل/عهدة/قيود...) مع الإبقاء على الماستر داتا';

    public function handle(): int
    {
        $this->warn('⚠️  هيمسح كل الحركة ويصفّر المخزون وأرصدة العملاء — الماستر داتا بتفضل.');

        if (! $this->option('force') && ! $this->confirm('متأكد؟')) {
            return self::FAILURE;
        }

        $wiped = ResetTransactions::run();

        if ($wiped === []) {
            $this->info('مفيش حركة أصلاً — كله فاضي.');

            return self::SUCCESS;
        }

        foreach ($wiped as $table => $n) {
            $this->line(sprintf('  %s: %s صف', $table, number_format($n)));
        }

        $this->info('✅ الحركة اتمسحت والمخزون والأرصدة اتصفّروا.');

        return self::SUCCESS;
    }
}
