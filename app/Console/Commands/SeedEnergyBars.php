<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * الإنرجي بار الأربعة  ·  ١٨ أغسطس ٢٠٢٦
 * ═══════════════════════════════════════════════════════════════
 *
 * أمر مرة واحدة: بيعرّف الأربع فليفرز الجداد بصورهم اللي المالك
 * رافعها في `public/img/Energybar/` — وبيسيبهم **درافت** (active=0)
 * عشان دوكترين الدرافت: الصنف مايظهرش في بيع ولا تسعير ولا API غير
 * لما يتسعّر ويتفعّل بإيد المالك من شاشة المنتجات.
 *
 * ⚠️ **idempotent** — بيدوّر بالكود، فتشغيله مرتين مش هيكرّر. ولو
 * كود واقع مستخدم لصنف تاني بيتخطّاه برسالة بدل ما يكتب فوقه.
 *
 * ⚠️ صف رصيد بصفر في كل مخزن مفعّل — نفس اللي شاشة إضافة الصنف
 * بتعمله بالحرف. من غيره الصنف مايبانش في شاشات المخازن.
 *
 * التشغيل:  php artisan promax:energy-bars
 */
class SeedEnergyBars extends Command
{
    protected $signature = 'promax:energy-bars';

    protected $description = 'تعريف أصناف الإنرجي بار الأربعة بصورهم (درافت لحد ما تتسعّر)';

    private const BARS = [
        ['code' => 'EB-01', 'flavor' => 'شوكولاتة', 'flavor_en' => 'Chocolate', 'img' => 'Chocolate.png'],
        ['code' => 'EB-02', 'flavor' => 'جوز هند', 'flavor_en' => 'Coconut', 'img' => 'Coconut.png'],
        ['code' => 'EB-03', 'flavor' => 'بلح', 'flavor_en' => 'Dates', 'img' => 'Dates.png'],
        ['code' => 'EB-04', 'flavor' => 'فواكه', 'flavor_en' => 'Fruits', 'img' => 'Fruits.png'],
    ];

    public function handle(): int
    {
        $warehouseIds = Warehouse::where('active', true)->pluck('id');

        foreach (self::BARS as $bar) {
            $existing = Product::where('code', $bar['code'])->first();

            if ($existing !== null) {
                $this->warn("⏭ {$bar['code']} موجود خلاص ({$existing->name}) — اتخطّى.");

                continue;
            }

            // الصورة موجودة فعلاً على الديسك؟ — نحذّر بدل ما نربط 404
            $imgRel = 'img/Energybar/'.$bar['img'];

            if (! is_file(public_path($imgRel))) {
                $this->warn("⚠️ الصورة {$imgRel} مش موجودة على السيرفر — الصنف هيتعمل من غيرها.");
                $imgRel = null;
            }

            DB::transaction(function () use ($bar, $imgRel, $warehouseIds) {
                $product = Product::create([
                    'code' => $bar['code'],
                    'name' => 'بروماكس إنرجي بار '.$bar['flavor'],
                    'name_en' => 'PROMAX Energy Bar '.$bar['flavor_en'],
                    'family' => 'energy_bar',
                    'unit' => 'علبة',
                    'unit_en' => 'Pack',
                    'shelf_life_months' => Product::SHELF_LIFE['energy_bar'],
                    // ⚠️ **درافت عن قصد** — من غير سعر. يتسعّر من شاشة
                    // التسعير وبعدين يتفعّل من شاشة المنتجات، ولحد
                    // ساعتها مش هيظهر في أي منتقي ولا في الأبلكيشن.
                    'active' => false,
                    'taxable' => true,
                    // ⚠️ `image_url` مش `image_path` — الصور في `public/img`
                    // مش في storage، وimageSrc() بتقرا العمود ده مباشرة.
                    'image_url' => $imgRel ? '/'.$imgRel : null,
                ]);

                foreach ($warehouseIds as $warehouseId) {
                    $product->stocks()->firstOrCreate(
                        ['warehouse_id' => $warehouseId],
                        ['qty' => 0, 'hold_qty' => 0, 'good_qty' => 0],
                    );
                }
            });

            $this->info("✔ {$bar['code']} — بروماكس إنرجي بار {$bar['flavor']} اتعرّف (درافت).");
        }

        $this->line('');
        $this->line('الخطوة الجاية: سعّرهم من شاشة التسعير، وبعدين فعّلهم من شاشة المنتجات (⏸ درافت → اكتيف).');

        return self::SUCCESS;
    }
}
