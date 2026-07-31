<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Transaction;
use App\Models\Zone;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * ترحيل داتا PROMAX الحقيقية (لحد 6/2026) من storage/app/data/promax.json
 * — 103 عميل بعقودهم وكشوف حساباتهم + 23 صنف بالمخزون + الزونز
 */
class PromaxImportSeeder extends Seeder
{
    /** الزونز اللي بنوزّع عليها العملاء */
    private const ZONES = [
        ['Z1', 'مدينة نصر والتجمع', 'الأحد والأربع'],
        ['Z2', 'مصر الجديدة ومدينتي', 'الاتنين'],
        ['Z3', 'المعادي والمقطم', 'الخميس'],
        ['Z4', 'وسط البلد والزمالك', 'السبت'],
    ];

    private const AREAS = [
        'Z1' => ['ش عباس العقاد - مدينة نصر', 'ش مكرم عبيد - مدينة نصر', 'الحي العاشر - مدينة نصر', 'التجمع الخامس - القاهرة الجديدة', 'ش الطيران - مدينة نصر', 'نرجس - التجمع'],
        'Z2' => ['ش الحجاز - مصر الجديدة', 'ميدان روكسي - مصر الجديدة', 'الكوربة - مصر الجديدة', 'مدينتي - المجاورة 3', 'ش الميرغني - هليوبوليس'],
        'Z3' => ['ش 9 - المعادي', 'دجلة - المعادي', 'كورنيش المعادي', 'المقطم - الهضبة الوسطى', 'زهراء المعادي'],
        'Z4' => ['ش 26 يوليو - الزمالك', 'وسط البلد - طلعت حرب', 'جاردن سيتي', 'الزمالك - ش حسن صبري'],
    ];

    /** عملاء بيتعاملوا كقنوات داخلية / جملة (مش زون مندوب) */
    private const INTERNAL = [
        'Cash Vans Sales', 'Rabbit', 'Gourrmet Egypt', 'Pickup', 'Amazon Agent',
        'Daily Dash Cash Van Mariam', 'Circle K Franshise Br(Unbilled)',
    ];

    public function run(): void
    {
        $path = storage_path('app/data/promax.json');
        if (! file_exists($path)) {
            $this->command->error("ملف الداتا مش موجود: $path");

            return;
        }

        $data = json_decode(file_get_contents($path), true);

        $this->command->info('⏳ بيرحّل داتا PROMAX الحقيقية...');

        $zones = $this->seedZones();
        $this->seedProducts($data['stock']['skus'] ?? []);
        $this->seedClients($data['clients'] ?? [], $zones);

        $this->command->info('✅ خلص الترحيل.');
    }

    /** @return array<string, Zone> */
    private function seedZones(): array
    {
        $zones = [];
        foreach (self::ZONES as [$code, $name, $day]) {
            $zones[$code] = Zone::updateOrCreate(
                ['code' => $code],
                ['name' => $name, 'day_label' => $day, 'active' => true],
            );
        }
        $this->command->info('   • '.count($zones).' زون');

        return $zones;
    }

    private function seedProducts(array $skus): void
    {
        // ⚠️ **مخزن واحد للرصيد الافتتاحي كله.** المخزون بقى صف لكل
        // (صنف، مخزن)، و`warehouse_id` عمود NOT NULL من غير default —
        // فالسيدر كان بيموت بـSQLSTATE 1364 على أول صنف، يعني
        // `db:seed` مابيعدّيش خالص على داتابيز متهاجرة.
        $warehouseId = \App\Models\Warehouse::defaultStockId();

        if ($warehouseId === null) {
            $this->command->warn('   ⚠️ مفيش مخازن — المنتجات هتتعمل من غير رصيد.');
        }

        foreach ($skus as $s) {
            $product = Product::updateOrCreate(
                ['code' => (string) $s['code']],
                [
                    'name' => $s['name'],
                    'unit' => $s['unit'],
                    'family' => $s['fam'],
                    // p50 كان أقرب حاجة للتكلفة، وسعر البيع الكامل بقى القديم والجديد
                    'cost' => $s['p50'],
                    'price_old' => $s['pfull'],
                    'price_new' => $s['pfull'],
                    'active' => true,
                ],
            );

            if ($warehouseId !== null) {
                Stock::updateOrCreate(
                    ['product_id' => $product->id, 'warehouse_id' => $warehouseId],
                    [
                        'qty' => (int) $s['qty'],
                        'hold_qty' => (int) $s['hold_q'],
                        'good_qty' => (int) $s['good_q'],
                        'counted_at' => '2026-06-30',
                    ],
                );
            }
        }
        $this->command->info('   • '.count($skus).' صنف بالمخزون');
    }

    /** @param  array<string, Zone>  $zones */
    private function seedClients(array $rows, array $zones): void
    {
        // ترتيب: غير Circle K بالحجم، وبعدين فروع Circle K — وتوزيع دائري على الزونز
        $field = array_values(array_filter($rows, fn ($c) => ! in_array($c['name'], self::INTERNAL, true)));
        $internal = array_values(array_filter($rows, fn ($c) => in_array($c['name'], self::INTERNAL, true)));

        $ck = array_values(array_filter($field, fn ($c) => str_starts_with($c['name'], 'Circle K')));
        $other = array_values(array_filter($field, fn ($c) => ! str_starts_with($c['name'], 'Circle K')));
        usort($other, fn ($a, $b) => $b['purchases'] <=> $a['purchases']);
        usort($ck, fn ($a, $b) => $b['purchases'] <=> $a['purchases']);

        $order = ['Z1', 'Z2', 'Z3', 'Z4'];
        $assign = [];
        foreach ($other as $i => $c) {
            $assign[$c['name']] = $order[$i % 4];
        }
        foreach ($ck as $i => $c) {
            $assign[$c['name']] = $order[($i + 2) % 4];
        }

        $seq = 1000;
        $txnCount = 0;

        foreach ($rows as $row) {
            $seq++;
            $isInternal = in_array($row['name'], self::INTERNAL, true);
            $zoneCode = $assign[$row['name']] ?? null;
            $areas = $zoneCode ? self::AREAS[$zoneCode] : [];
            $address = $areas ? $areas[$seq % count($areas)] : 'قناة داخلية';

            $client = Client::updateOrCreate(
                ['code' => 'CL-'.$seq],
                [
                    'name' => $row['name'],
                    'phone' => sprintf('01%d%07d', $seq % 3, ($seq * 7919) % 10000000),
                    'address' => $address,
                    'zone_id' => $zoneCode ? $zones[$zoneCode]->id : null,
                    'category' => $isInternal ? 'internal' : ($row['cat_py'] ?? 'ok'),
                    'status' => 'active',
                    'discount' => $row['contract']['disc'] ?? 0,
                    'purchases' => $row['purchases'],
                    'collections' => $row['collections'],
                    'returns' => $row['returns'],
                    'rebates' => $row['rebates'],
                    'settlements' => $row['settlements'],
                    'balance' => $row['balance'],
                    'first_activity_at' => $row['first'] ?? null,
                    'last_activity_at' => $row['last'] ?? null,
                    'last_payment_at' => $row['last_pay'] ?? null,
                ],
            );

            // ---- العقد ----
            if (! empty($row['contract'])) {
                Contract::updateOrCreate(
                    ['client_id' => $client->id],
                    [
                        'chain' => $row['contract']['chain'] ?? null,
                        'type' => $row['contract']['type'] ?? null,
                        'discount' => $row['contract']['disc'] ?? 0,
                        'terms' => $row['contract']['terms'] ?? null,
                        'ends_at' => $row['contract']['end'] ?? null,
                        'note' => $row['contract']['note'] ?? null,
                    ],
                );
            }

            // ---- كشف الحساب ----
            $client->transactions()->delete();
            $chunk = [];
            foreach ($row['txns'] ?? [] as $t) {
                $chunk[] = [
                    'client_id' => $client->id,
                    'date' => $t['d'],
                    'memo' => mb_substr((string) ($t['m'] ?? ''), 0, 2000),
                    'debit' => $t['dr'] ?? 0,
                    'credit' => $t['cr'] ?? 0,
                    'kind' => $t['c'] ?? 'sale',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            if ($chunk) {
                foreach (array_chunk($chunk, 200) as $part) {
                    DB::table('transactions')->insert($part);
                }
                $txnCount += count($chunk);
            }
        }

        $this->command->info('   • '.count($rows).' عميل ('.count($internal).' قناة داخلية)');
        $this->command->info('   • '.number_format($txnCount).' حركة في كشوف الحساب');
    }
}
