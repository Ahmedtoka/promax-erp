<?php

namespace App\Console\Commands;

use App\Services\ContractDues;
use Illuminate\Console\Command;

/**
 * توليد مستحقات العقود الدورية.
 *
 * ⚠️ الأمر ده **بيحسب بس، مابيقيّدش**. الترحيل لكشف الحساب قرار
 * بشري من شاشة المستحقات. شغّله كل ما تخلص فترة (أو يومياً، مافيش
 * ضرر — الفترة الجارية مابتتولّدش).
 */
class GenerateContractDues extends Command
{
    protected $signature = 'contracts:dues
        {--up-to= : احسب لحد التاريخ ده بدل النهارده (Y-m-d)}';

    protected $description = 'حساب الخصومات الدورية المستحقة من بنود العقود على مشتريات كل فترة';

    public function handle(): int
    {
        $upTo = $this->option('up-to')
            ? \Illuminate\Support\Carbon::parse($this->option('up-to'))
            : today();

        $this->info('حساب المستحقات لحد '.$upTo->toDateString().' …');

        $r = ContractDues::generate($upTo);

        $this->info("   • {$r['contracts']} عقد سارٍ");
        $this->info("   • {$r['created']} استحقاق جديد");
        $this->info("   • {$r['skipped']} موجود قبل كده أو مقيّد");
        $this->newLine();
        $this->comment('⚠️ الاستحقاقات محسوبة ومش مقيّدة — رحّلها من شاشة المستحقات.');

        return self::SUCCESS;
    }
}
