<?php

namespace App\Console\Commands;

use App\Models\BatchLocation;
use App\Models\Location;
use App\Models\Warehouse;
use App\Support\LifeBands;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * بناء بلوكات FEFO (2026-08-06):
 *
 *   php artisan promax:shelves              # كل المخازن النشطة
 *   php artisan promax:shelves --warehouse=MAADI
 *
 * بيعمل بالترتيب في كل مخزن:
 *  1. بلوك لكل نطاق عمر: Y01 (بروماكس سنة)، H01 (6 شهور)،
 *     Q01 (3 شهور)، M01 (شهر) — من غير ما يلمس الموجود.
 *  2. **ترحيل البضاعة القديمة أوتوماتيك** (قرار المالك 2026-08-06):
 *     أي كمية على رف حر (من غير نطاق) بتتنقل للبلوك المطابق لعمر
 *     باتشها. اللي من غير تاريخ انتهاء بيفضل مكانه.
 *  3. **مسح الأرفف القديمة** اللي فضيت بعد الترحيل — واللي لسه
 *     عليها بضاعة (بدون صلاحية) بيتبلغ عنها من غير مسح.
 *
 * آمن يتعاد أي وقت — والصفوف التاريخية اللي بتشاور على رف اتمسح
 * (nullOnDelete) بتفضل سليمة.
 */
class BuildShelves extends Command
{
    protected $signature = 'promax:shelves {--warehouse= : كود مخزن معيّن}';

    protected $description = 'بناء بلوكات FEFO + ترحيل البضاعة القديمة ومسح الأرفف الحرة الفاضية';

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

            $this->migrateFreeShelves($wh);
        }

        return self::SUCCESS;
    }

    /**
     * ترحيل بضاعة الأرفف الحرة للبلوكات الصح + مسح اللي فضي منها.
     *
     * النقل مباشر على `batch_locations` (مش moveTo) عن قصد — الحارس
     * بيسمح بس بالنطاق المطابق وده بالظبط اللي بنعمله، والدمج مع
     * صف موجود لنفس الباتش على البلوك محتاج معالجة الـunique هنا.
     */
    private function migrateFreeShelves(Warehouse $wh): void
    {
        $moved = 0;
        $kept = 0;

        $rows = BatchLocation::query()
            ->whereHas('location', fn ($q) => $q->where('warehouse_id', $wh->id)->whereNull('life_band'))
            ->where('qty', '>', 0)
            ->with(['batch', 'location'])
            ->get();

        foreach ($rows as $bl) {
            $batch = $bl->batch;
            $target = $batch ? LifeBands::suggest($wh->id, $batch) : null;

            if ($target === null) {
                $kept++; // من غير تاريخ انتهاء — مالوش نطاق، بيفضل مكانه

                continue;
            }

            DB::transaction(function () use ($bl, $target) {
                // نفس الباتش موجود على البلوك؟ ندمج بدل ما الـunique يضرب
                $existing = BatchLocation::where('batch_id', $bl->batch_id)
                    ->where('location_id', $target->id)->first();

                if ($existing) {
                    $existing->increment('qty', $bl->qty);
                    $bl->delete();
                } else {
                    $bl->update(['location_id' => $target->id]);
                }
            });

            $moved++;
        }

        // الأرفف الحرة اللي فضيت — بتتمسح (طلب المالك: امسح القديم)
        $deleted = 0;

        foreach (Location::where('warehouse_id', $wh->id)->whereNull('life_band')->get() as $loc) {
            if ((int) $loc->batchLocations()->where('qty', '>', 0)->sum('qty') === 0) {
                $loc->delete();
                $deleted++;
            } else {
                $this->warn("  {$loc->code}: لسه عليه بضاعة من غير تاريخ انتهاء — سيبته.");
            }
        }

        if ($moved || $deleted || $kept) {
            $this->info("  ترحيل: $moved كمية اتنقلت للبلوكات، $deleted رف قديم اتمسح".($kept ? "، $kept من غير صلاحية فضلوا" : '').'.');
        }
    }
}
