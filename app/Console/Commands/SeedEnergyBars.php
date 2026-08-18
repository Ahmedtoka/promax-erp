<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════
 * صور الإنرجي بار  ·  ١٨ أغسطس ٢٠٢٦ (نسخة مصححة)
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **النسخة الأولانية كانت غلط** — عرّفت EB-01..04 كأصناف جداد،
 * والمالك كان معرّف الأربعة خلاص بباركودهم («برو ماكس انيرجى بار
 * شوكولاتة 55 غرام»...). النسخة دي بتصلّح الاتنين:
 *
 *   ١. بتمسح الدوبليكيتات EB-01..04 — بس لو لسه درافت من غير أي
 *      حركة (وهي كده أكيد: الدرافت مايتباعش أصلاً بحكم Sellable).
 *   ٢. بتربط صور `public/img/Energybar/` بأصناف المالك الحقيقية
 *      بالكود بتاعها.
 *
 * idempotent — تشغيله تاني مش هيضر.
 *
 * التشغيل:  php artisan promax:energy-bars
 */
class SeedEnergyBars extends Command
{
    protected $signature = 'promax:energy-bars';

    protected $description = 'مسح دوبليكيتات EB-01..04 وربط صور الإنرجي بار بأصناف المالك الحقيقية';

    /** كود الصنف الحقيقي عند المالك → ملف الصورة */
    private const LINKS = [
        '6224003852471' => 'Chocolate.png',   // شوكولاتة 55 جم
        '6224003852488' => 'Coconut.png',     // جوز الهند 55 جم
        '6224003852495' => 'Dates.png',       // بلح 55 جم
        '6224003852501' => 'Fruits.png',      // فواكه 55 جم
    ];

    public function handle(): int
    {
        // ═══ ١. مسح الدوبليكيتات اللي النسخة الأولانية عملتها ═══
        foreach (['EB-01', 'EB-02', 'EB-03', 'EB-04'] as $code) {
            $dupe = Product::where('code', $code)->first();

            if ($dupe === null) {
                continue;
            }

            // ⚠️ حزام أمان: لو حد فعّله أو اتباع بيه (مستحيل نظرياً —
            // درافت) مانمسحوش في صمت.
            $used = DB::table('invoice_items')->where('product_id', $dupe->id)->exists()
                || DB::table('custody_items')->where('product_id', $dupe->id)->exists();

            if ($dupe->active || $used) {
                $this->warn("⚠️ {$code} عليه حركة أو متفعّل — ماتمسحش. راجعه بإيدك.");

                continue;
            }

            DB::transaction(function () use ($dupe) {
                $dupe->stocks()->delete();

                if (Schema::hasTable('price_list_items')) {
                    DB::table('price_list_items')->where('product_id', $dupe->id)->delete();
                }

                $dupe->delete();
            });

            $this->info("🗑 {$code} (الدوبليكيت) اتمسح.");
        }

        // ═══ ٢. ربط الصور بالأصناف الحقيقية ═══
        foreach (self::LINKS as $code => $img) {
            $product = Product::where('code', $code)->first();

            if ($product === null) {
                $this->warn("⚠️ مفيش صنف بكود {$code} — اتخطّى.");

                continue;
            }

            $rel = 'img/Energybar/'.$img;

            if (! is_file(public_path($rel))) {
                $this->warn("⚠️ الصورة {$rel} مش مرفوعة على السيرفر — {$code} اتخطّى.");

                continue;
            }

            // ⚠️ `image_url` — imageSrc() بتقدّم `image_path` (المرفوع
            // من الشاشة) عليها، فلو المالك رفع صورة أحدث من الفورم
            // بعدين هي اللي هتكسب. ده المطلوب.
            $product->update(['image_url' => '/'.$rel]);

            $this->info("🖼 {$product->name} ← {$img}");
        }

        $this->line('');
        $this->line('افتح شاشة المخزون والتسعير — الصور المفروض ظاهرة. لو مش باينة اعمل ريفريش بـ Ctrl+F5.');

        return self::SUCCESS;
    }
}
