<?php

namespace App\Console\Commands;

use App\Models\Batch;
use App\Models\BatchLocation;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Warehouse;
use App\Services\OpeningStock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * مصالحة المستويات التلاتة: stocks ⇆ batches ⇆ الأرفف
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **السبب اللي اتكتب عشانه:** الأرصدة اللي اتكتبت بالإيد من شاشة
 * المخازن كانت بتنزل في `stocks` بس — فشاشة المخزون تقول «موجود»
 * وتسليم العهدة يقول «المتاح 0» لأنه بيقرا من الأرفف. الشاشة نفسها
 * اتصلحت (بقت بتعدّي على `OpeningStock`)، والأمر ده بيصلّح الداتا
 * اللي اتكتبت قبل الإصلاح.
 *
 * بيعمل مع `--fix`:
 *   • باتش فيه كمية لسه مااترصّفتش على رف ← بتترصّف على رف السحب
 *   • ومع `--trust-stocks` كمان: صف `stocks` مختلف عن مجموع الباتشات ←
 *     الرقم اليدوي هو الحقيقة — باتش تسوية بيتعمل ويترصّف
 *
 * ⚠️⚠️ **`--trust-stocks` بقت اختيارية (٥/١٠/٢٠٢٦).** `stocks` تجميعة
 * بتتأخر: أمر تجهيز اتسحب ولسه ماتسلّمش بيخصم من الباتش والرف ومش من
 * `stocks` لحد التسليم — فالتسوية على `stocks` كانت بتخلق بضاعة مش
 * موجودة (العسل: stocks 127 والباتشات 115، والـ12 في أمر تحميل جاهز).
 * المصدر هو الباتشات؛ `stocks` بيتعاد حسابه منها في الآخر.
 *
 * التشغيل:
 *   promax:shelve                       تقرير بس — مفيش أي كتابة
 *   promax:shelve --fix                 رصّف الكميات اللي مش على رف
 *   promax:shelve --fix --trust-stocks  + اعتبر `stocks` هو الحقيقة (للأرصدة اليدوية القديمة بس)
 */
class ShelveStock extends Command
{
    protected $signature = 'promax:shelve {--fix} {--trust-stocks : اعتبر رقم stocks هو الحقيقة وسوّي الباتشات عليه}';

    protected $description = 'مصالحة stocks/batches/الأرفف — وترصيف الأرصدة اليدوية عشان تبان في تسليم العهدة';

    public function handle(): int
    {
        $fix = (bool) $this->option('fix');
        $trustStocks = (bool) $this->option('trust-stocks');
        $rows = [];
        $fixed = 0;

        foreach (Warehouse::where('active', true)->orderBy('code')->get() as $w) {
            $productIds = Stock::where('warehouse_id', $w->id)->pluck('product_id')
                ->merge(Batch::where('warehouse_id', $w->id)->pluck('product_id'))
                ->unique();

            foreach (Product::whereIn('id', $productIds)->orderBy('code')->get() as $p) {
                $stock = Stock::where('warehouse_id', $w->id)->where('product_id', $p->id)->first();
                $stockQty = (int) ($stock?->qty ?? 0);
                $stockHold = (int) ($stock?->hold_qty ?? 0);

                $batchQty = (int) Batch::where('warehouse_id', $w->id)
                    ->where('product_id', $p->id)->sum('qty_remaining');

                $shelfQty = (int) BatchLocation::where('product_id', $p->id)
                    ->whereHas('location', fn ($q) => $q->where('warehouse_id', $w->id))
                    ->sum('qty');

                if ($stockQty === $batchQty && $batchQty === $shelfQty) {
                    continue;
                }

                $rows[] = [$w->code, $p->code, mb_substr($p->name, 0, 34), $stockQty, $batchQty, $shelfQty];

                if (! $fix) {
                    continue;
                }

                $clean = true;

                try {
                    DB::transaction(function () use ($w, $p, $stock, $stockQty, $stockHold, $batchQty, $trustStocks, &$clean) {
                    // ١) الرقم اليدوي في `stocks` هو الحقيقة — باتشات
                    //    التسوية بتتظبط عليه (لو مفيش صف stocks أصلاً،
                    //    الباتشات هي المصدر ومفيش رقم يدوي نمشي وراه)
                    if ($trustStocks && $stock !== null && $stockQty !== $batchQty) {
                        OpeningStock::apply($w, $p, $stockQty, $stockHold);
                    }

                    // ٢) أي باتش فيه كمية من غير رف — بتترصّف. من غير
                    //    كده الكمية «موجودة» بس تسليم العهدة مش شايفها
                    $shelf = null;

                    $pending = Batch::where('warehouse_id', $w->id)
                        ->where('product_id', $p->id)
                        ->where('qty_remaining', '>', 0)
                        ->lockForUpdate()->get();

                    foreach ($pending as $batch) {
                        $loose = $batch->unshelvedQty();

                        if ($loose <= 0) {
                            continue;
                        }

                        $shelf ??= OpeningStock::pickShelf($w);
                        $err = BatchLocation::putAway($batch, $shelf, $loose);

                        if ($err !== null) {
                            // اترصّفش — مايتحسبش «اتصلح» في الرسالة النهائية
                            $clean = false;
                            $this->warn("  ⚠️ {$w->code}/{$p->code} {$batch->batch_no}: $err");
                        }
                    }

                        \App\Services\StockCounting::resync($p->id, $w->id);
                    });

                    if ($clean) {
                        $fixed++;
                    }
                } catch (\App\Exceptions\Rejected $e) {
                    // رف مليان أو رفض ترصيف — الصنف ده بيتساب زي ما هو
                    // والأمر بيكمّل على الباقي بدل ما يقف في النص
                    $this->warn("  ⚠️ {$w->code}/{$p->code}: {$e->getMessage()}");
                }
            }
        }

        if ($rows === []) {
            $this->info('✅ المستويات التلاتة متطابقة في كل المخازن — مفيش حاجة تتصلح.');

            return self::SUCCESS;
        }

        $this->table(['المخزن', 'الكود', 'الصنف', 'stocks', 'باتشات', 'أرفف'], $rows);

        if ($fix) {
            $this->info("✅ اتصلح $fixed صنف — الأرقام دلوقتي متطابقة والمتاح بيبان في تسليم العهدة.");
            $this->line('   شغّل الأمر تاني من غير --fix للتأكد إن كله بقى متطابق.');
        } else {
            $this->warn('دي فروق بس — مفيش حاجة اتكتبت. شغّل بـ --fix للتصليح.');
        }

        return self::SUCCESS;
    }
}
