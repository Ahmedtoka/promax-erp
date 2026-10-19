<?php

namespace App\Console\Commands;

use App\Services\Handover;
use Illuminate\Console\Command;

/**
 * «الشاشة دي اتغيّرت» — بعد أي تعديل على شاشة اتسلّمت:
 *
 *   php artisan promax:handover-touch erp.clients ops.invoices
 *
 * الشاشة بتاخد «اتعدلت بعد الاستلام» من غير ما التعليم يتصفّر.
 * ⚠️ بيرجع فشل لو ولا شاشة طابقت — غالباً اسم راوت مكتوب غلط.
 */
class HandoverTouch extends Command
{
    protected $signature = 'promax:handover-touch {route* : أسماء الراوتس اللي اتغيّرت}';

    protected $description = 'Flag handover screens as changed after their mark (touched_at = now)';

    public function handle(): int
    {
        $routes = array_values(array_filter(array_map('trim', (array) $this->argument('route'))));
        $n = Handover::touch($routes);

        $this->info('screens touched: '.$n);

        if ($n === 0) {
            $this->error('no handover screen matches: '.implode(', ', $routes));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
