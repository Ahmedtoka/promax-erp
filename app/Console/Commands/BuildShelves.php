<?php

namespace App\Console\Commands;

use App\Models\Location;
use App\Models\Warehouse;
use App\Support\LifeBands;
use Illuminate\Console\Command;

/**
 * بناء بلوكات FEFO (2026-08-06):
 *
 *   php artisan promax:shelves              # كل المخازن النشطة
 *   php artisan promax:shelves --warehouse=MAADI
 *
 * بيعمل في كل مخزن بلوك لكل نطاق عمر: Y01 (بروماكس سنة)،
 * H01 (6 شهور)، Q01 (3 شهور)، M01 (شهر). زيادة البلوكات بعد كده
 * (Y02...) من شاشة الأرفف عادي — الأمر مابيلمسش الأرفف الموجودة
 * ولا بيكرر، فآمن يتعاد أي وقت.
 */
class BuildShelves extends Command
{
    protected $signature = 'promax:shelves {--warehouse= : كود مخزن معيّن}';

    protected $description = 'بناء بلوكات نطاقات الصلاحية (FEFO) في المخازن';

    public function handle(): int
    {
        $warehouses = Warehouse::where('active', true)
            ->when($this->option('warehouse'),
                fn ($q, $code) => $q->where('code', strtoupper($code)))
            ->get();

        if ($warehouses->isEmpty()) {
            $this->error('مفيش مخازن مطابقة.');

            return self::FAILURE;
        }

        foreach ($warehouses as $wh) {
            $made = [];

            foreach (LifeBands::BANDS as $band => $max) {
                $prefix = LifeBands::PREFIX[$band];
                $code = $prefix.'01';

                $loc = Location::firstOrNew([
                    'warehouse_id' => $wh->id,
                    'code' => $code,
                ]);

                if ($loc->exists && $loc->life_band === $band) {
                    continue; // موجود ومظبوط
                }

                $loc->fill([
                    'stand' => $prefix,
                    'level' => 1,
                    'life_band' => $band,
                    'is_pick_face' => $band === 'month', // الأقرب انتهاءً = رف السحب
                    'notes' => LifeBands::label($band),
                    'active' => true,
                ])->save();

                $made[] = $code;
            }

            $this->info($wh->displayName().': '.($made === [] ? 'البلوكات موجودة ✓' : implode('، ', $made).' اتعملوا ✓'));
        }

        return self::SUCCESS;
    }
}
