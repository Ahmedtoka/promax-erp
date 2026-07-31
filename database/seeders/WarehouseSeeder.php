<?php

namespace Database\Seeders;

use App\Models\Batch;
use App\Models\BatchLocation;
use App\Models\GoodsReceipt;
use App\Models\Location;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;

/**
 * المخزنين + أرفف فرع المعادي، وترحيل الباتشات القديمة للمعادي وترصيفها.
 *
 * أرفف المعادي: 6 ستاندات (A→F) × 5 أدوار = 30 رف.
 * الدور 1 و 2 = رفوف سحب (pick face) — الأقرب انتهاءً بيتحط فيهم.
 */
class WarehouseSeeder extends Seeder
{
    private const STANDS = ['A', 'B', 'C', 'D', 'E', 'F'];
    private const LEVELS = 5;

    public function run(): void
    {
        $admin = User::where('role', 'admin')->first();

        $factory = Warehouse::updateOrCreate(
            ['code' => 'FAC'],
            [
                'name' => 'مصنع بروماكس',
                'name_en' => 'PROMAX Factory',
                'type' => Warehouse::TYPE_FACTORY,
                'address' => 'المنطقة الصناعية',
                'active' => true,
            ],
        );

        $maadi = Warehouse::updateOrCreate(
            ['code' => 'MAADI'],
            [
                'name' => 'فرع المعادي',
                'name_en' => 'Maadi Branch',
                'type' => Warehouse::TYPE_BRANCH,
                'address' => 'المعادي - القاهرة',
                'lat' => 29.9600,
                'lng' => 31.2580,
                'manager_id' => $admin?->id,
                'active' => true,
            ],
        );

        $this->command->info('   • مخزنين: '.$factory->code.' و '.$maadi->code);

        $this->shelves($maadi);
        $this->migrateOpeningStock($maadi, $admin);
    }

    private function shelves(Warehouse $warehouse): void
    {
        $made = 0;

        foreach (self::STANDS as $stand) {
            for ($level = 1; $level <= self::LEVELS; $level++) {
                Location::updateOrCreate(
                    [
                        'warehouse_id' => $warehouse->id,
                        'code' => Location::buildCode($stand, $level),
                    ],
                    [
                        'stand' => $stand,
                        'level' => $level,
                        // الدورين الأولانيين في متناول اليد = رفوف سحب
                        'is_pick_face' => $level <= 2,
                        'capacity' => 2000,
                        'active' => true,
                    ],
                );
                $made++;
            }
        }

        $this->command->info("   • $made رف في ".$warehouse->code);
    }

    /**
     * الباتشات اللي عملها BatchSeeder كانت من غير مخزن.
     * بنحطها في المعادي ونرصّفها على الأرفف بمنطق سليم:
     * الأقرب انتهاءً على رف السحب، والأبعد فوق.
     */
    private function migrateOpeningStock(Warehouse $warehouse, ?User $admin): void
    {
        // ده لازم يتم قبل أي early return — الأذون القديمة كانت من غير مخزن
        GoodsReceipt::whereNull('warehouse_id')->update(['warehouse_id' => $warehouse->id]);

        $orphans = Batch::whereNull('warehouse_id')->get();

        if ($orphans->isEmpty()) {
            $this->command->info('   • مفيش باتشات محتاجة ترحيل');

            return;
        }

        Batch::whereNull('warehouse_id')->update(['warehouse_id' => $warehouse->id]);

        $pickFaces = $warehouse->locations()->where('is_pick_face', true)->get()->values();
        $upper = $warehouse->locations()->where('is_pick_face', false)->get()->values();

        if ($pickFaces->isEmpty() || $upper->isEmpty()) {
            $this->command->warn('   ⚠️  مفيش أرفف كفاية للترصيف');

            return;
        }

        $shelved = 0;
        $i = 0;

        foreach ($orphans->sortBy('expires_on') as $batch) {
            $batch->refresh();

            if ($batch->unshelvedQty() <= 0) {
                continue;
            }

            // الأقرب انتهاءً (أول 40% من الباتشات) على رفوف السحب
            $pool = $i < (int) ceil($orphans->count() * 0.4) ? $pickFaces : $upper;
            $location = $pool[$i % $pool->count()];

            $error = BatchLocation::putAway($batch, $location, $batch->unshelvedQty());

            if ($error === null) {
                $shelved++;
            } else {
                $this->command->warn('   ⚠️  '.$batch->batch_no.': '.$error);
            }

            $i++;
        }

        $this->command->info("   • $shelved باتش اترصّف على الأرفف");
    }
}
