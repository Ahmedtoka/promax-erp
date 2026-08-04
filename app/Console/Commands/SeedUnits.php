<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;

/**
 * زرع وحدات القياس المعتمدة — أرقام المالك 2026-08-04:
 *
 *   سبريدز:      كرتونة = 12 قطعة (مفيش علبة)
 *   بروماكس بار: علبة = 12 قطعة، كرتونة = 6 علب = 72 قطعة
 *   PMX بار:     علبة = 12 قطعة، كرتونة = 72 قطعة
 *   بروكب:       كرتونة = 24 قطعة (مفيش علبة)
 *
 * ⚠️ **بيكتب فوق `units_per_case` القديم** للعائلات دي بس — القيم
 * اللي جت من استيراد GS1 كانت غلط/ناقصة والمالك أملى الأرقام دي
 * بنفسه. أي عائلة تانية (إنرچي بار مثلاً) مابنلمسهاش لحد ما ييجي
 * قرار ليها. الأمر idempotent — تشغيله مرتين مايغيّرش حاجة.
 */
class SeedUnits extends Command
{
    protected $signature = 'promax:units';

    protected $description = 'زرع وحدات القياس المعتمدة (علبة/كرتونة) لكل عائلة منتجات';

    /** family => [box_units, units_per_case] */
    private const UNITS = [
        'spreads' => [null, 12],
        'promax_bar' => [12, 72],
        'pmx_bar' => [12, 72],
        'promax_cup' => [null, 24],
    ];

    public function handle(): int
    {
        $changed = 0;

        foreach (self::UNITS as $family => [$box, $case]) {
            $products = Product::where('family', $family)->orderBy('code')->get();

            if ($products->isEmpty()) {
                $this->warn("⚠️ {$family}: مفيش منتجات");

                continue;
            }

            $this->info(Product::FAMILIES[$family].' — علبة: '.($box ?: '—').' · كرتونة: '.$case);

            foreach ($products as $p) {
                $same = (int) $p->box_units === (int) $box
                    && (int) $p->units_per_case === (int) $case;

                if ($same) {
                    continue;
                }

                $this->line(sprintf(
                    '  %s %s: علبة %s→%s · كرتونة %s→%s',
                    $p->code, $p->name,
                    $p->box_units ?: '—', $box ?: '—',
                    $p->units_per_case ?: '—', $case
                ));

                $p->update(['box_units' => $box, 'units_per_case' => $case]);
                $changed++;
            }
        }

        $this->newLine();
        $this->info($changed > 0 ? "✅ اتعدّل {$changed} صنف" : '✅ كل الأصناف مظبوطة خلاص');

        return self::SUCCESS;
    }
}
