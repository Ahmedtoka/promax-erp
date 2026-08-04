<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * ═══════════════════════════════════════════════════════════════
 * تسكين صور المنتجات الرسمية — من public/img (2026-08-04)
 * ═══════════════════════════════════════════════════════════════
 *
 * الصور جاية من المالك في 3 فولدرات (Pro Spread / Probar / ProCap)
 * والربط **بالباركود** مش بالاسم — الاسم بيتغير والباركود ثابت.
 *
 * ⚠️ الصورة بتتنسخ لـ `storage/app/public/products/` وبيتسجل
 * `image_path` — نفس مسار الرفع اليدوي بالظبط (`ProductImage`)،
 * فبتظهر في كل حتة بيظهر فيها `imageSrc()`: الكتالوج، العهدة،
 * البيع والمرتجع في الأبلكيشن، تسليم العهدة، التجهيز، الجرد،
 * أوامر التوريد. محتاج `php artisan storage:link` يكون اتعمل.
 *
 * الأصناف اللي مالهاش صورة (PMX بار، إنرچي بار، كاشو، لوز،
 * برو كاب الساده) بتطلع في التقرير — أول ما صورها توصل تتضاف هنا.
 */
class SeedImages extends Command
{
    protected $signature = 'promax:images {--force : اكتب فوق الصور المسكّنة قبل كده}';

    protected $description = 'تسكين صور المنتجات من public/img على الأصناف بالباركود';

    /** باركود => ملف الصورة جوه public/img */
    private const MAP = [
        // ═══ سبريدز — Pro Spread ═══
        '6224003852112' => 'Pro Spread/Pro-Spread-Chocolate.png',   // شوكو برو
        '6224003852013' => 'Pro Spread/Pro-Spread-Peanut.png',      // زبدة الفول 300
        '6224003852044' => 'Pro Spread/Pro-Spread-Peanut.png',      // زبدة الفول 500 — نفس الصورة
        '6224003852129' => 'Pro Spread/Pro-Spread-Pistachio.png',   // برو دبي بيستاشيو
        '6224003852136' => 'Pro Spread/Pro-Spread-bueno.png',       // بوينو

        // ═══ بروماكس بار — Probar ═══
        '6224003852082' => 'Probar/Pro-Bar-Blueberry.png',          // توت بري
        '6224003852068' => 'Probar/Pro-Bar-Chocolate.png',          // شوكولات
        '6224003852075' => 'Probar/Pro-Bar-Coconut.png',            // جوز هند
        '6224003852051' => 'Probar/Pro-Bar-Coffee.png',             // قهوة
        '6224003852105' => 'Probar/Pro-Bar-Cookies.png',            // كوكيز كريم
        '6224003852099' => 'Probar/Pro-Bar-Peanut.png',             // سوداني

        // ═══ بروكب — ProCap ═══
        '6224003852198' => 'ProCap/Procap--Pistachio.png',          // دبي بيستاشيو
        '6224003852150' => 'ProCap/Procap--chocolate.png',          // شوكولات
        '6224003852167' => 'ProCap/Procap--coffee.png',             // قهوة
        '6224003852174' => 'ProCap/Procap--honey.png',              // عسل
        '6224003852181' => 'ProCap/Procap-Cookies.png',             // كوكيز
        '6224003852143' => 'ProCap/Procap-Ice-Vanilla.png',         // فانيليا ايس كريم
    ];

    public function handle(): int
    {
        $destDir = storage_path('app/public/products');
        File::ensureDirectoryExists($destDir);

        $done = 0;
        $skipped = 0;

        foreach (self::MAP as $barcode => $rel) {
            $product = Product::where('barcode', $barcode)->first();
            $source = public_path('img/'.$rel);

            if ($product === null) {
                $this->warn("  ⚠️ مفيش صنف بالباركود {$barcode} ({$rel})");

                continue;
            }

            if (! File::exists($source)) {
                $this->warn("  ⚠️ الملف مش موجود: img/{$rel}");

                continue;
            }

            // ⚠️ صورة مرفوعة يدوي ماتتداسش من غير --force — حد ممكن
            // يكون رفع صورة أحدث من كارت الصنف
            if ($product->image_path && ! $this->option('force')) {
                $this->line("  ⏭️ {$product->code} {$product->name} — عنده صورة خلاص (استخدم --force)");
                $skipped++;

                continue;
            }

            $file = 'products/'.$product->code.'.png';
            File::copy($source, $destDir.'/'.$product->code.'.png');
            $product->update(['image_path' => $file]);

            $this->info("  ✅ {$product->code} {$product->name} ← img/{$rel}");
            $done++;
        }

        // الأصناف اللي لسه من غير صورة — عشان المالك يبعت صورها
        $missing = Product::where('active', true)->whereNull('image_path')->orderBy('code')->get();

        if ($missing->isNotEmpty()) {
            $this->newLine();
            $this->warn('  أصناف لسه من غير صورة ('.$missing->count().'):');

            foreach ($missing as $p) {
                $this->line("    • {$p->code} — {$p->name}");
            }
        }

        $this->newLine();
        $this->info("✅ اتسكّن {$done} صورة".($skipped > 0 ? " · اتخطى {$skipped}" : ''));

        return self::SUCCESS;
    }
}
