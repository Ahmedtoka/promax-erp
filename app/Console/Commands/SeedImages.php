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

        // ═══ صور PMX بار (2026-08-06) — الربط بالاسم الإنجليزي ═══
        // المالك رفع الفولدر بأسماء المنتجات نفسها («PMX Protein Bar
        // Cookies 70 Gram.png») — مفيش باركودات في الـMAP ليهم، فالمطابقة
        // بالاسم المطبّع، وباحتياطي كلمات النكهة جوه عائلة pmx_bar.
        [$d, $s] = $this->seedFolderByName('Pmx', 'pmx_bar', $destDir);
        $done += $d;
        $skipped += $s;

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

    /**
     * تسكين فولدر صور بالاسم الإنجليزي — الملف اسمه اسم المنتج.
     *
     * المطابقة على مرحلتين:
     *  1. الاسم المطبّع بالكامل (حروف صغيرة، من غير رموز ومسافات).
     *  2. احتياطي: كل كلمات النكهة (بعد شيل الكلمات العامة) لازم
     *     تظهر في اسم منتج من العائلة المحددة — بيمسك فروق بسيطة
     *     زي «Gram» مقابل «g» أو «&» زيادة.
     *
     * @return array{0: int, 1: int}  [اتسكّن، اتخطى]
     */
    private function seedFolderByName(string $folder, string $family, string $destDir): array
    {
        $dir = public_path('img/'.$folder);

        if (! File::isDirectory($dir)) {
            return [0, 0];
        }

        $normalize = fn (string $s) => preg_replace('/[^a-z0-9]+/', '', strtolower($s));
        // الكلمات العامة اللي مش بتفرّق نكهة عن نكهة
        $generic = ['pmx', 'protein', 'bar', '70', 'gram', 'g', 'gm'];

        $products = Product::where('family', $family)->get();
        $byNorm = $products->keyBy(fn ($p) => $normalize((string) $p->name_en));

        $done = 0;
        $skipped = 0;

        foreach (File::files($dir) as $file) {
            if (! in_array(strtolower($file->getExtension()), ['png', 'jpg', 'jpeg', 'webp'], true)) {
                continue;
            }

            $base = $file->getFilenameWithoutExtension();
            $product = $byNorm->get($normalize($base));

            // احتياطي: كلمات النكهة كلها لازم تظهر في اسم المنتج
            if ($product === null) {
                $tokens = array_values(array_diff(
                    preg_split('/[^a-z0-9]+/', strtolower($base), -1, PREG_SPLIT_NO_EMPTY),
                    $generic,
                ));

                $hits = $products->filter(function ($p) use ($tokens) {
                    $name = strtolower((string) $p->name_en);

                    foreach ($tokens as $t) {
                        if (! str_contains($name, $t)) {
                            return false;
                        }
                    }

                    return $tokens !== [];
                });

                // مطابقة واحدة بالظبط — اتنين يعني الاسم مش مميِّز، نسيبها
                $product = $hits->count() === 1 ? $hits->first() : null;
            }

            if ($product === null) {
                $this->warn("  ⚠️ {$folder}/{$file->getFilename()} — مالقتش منتج مطابق");

                continue;
            }

            if ($product->image_path && ! $this->option('force')) {
                $this->line("  ⏭️ {$product->code} {$product->name} — عنده صورة خلاص (استخدم --force)");
                $skipped++;

                continue;
            }

            $ext = strtolower($file->getExtension());
            File::copy($file->getPathname(), $destDir.'/'.$product->code.'.'.$ext);
            $product->update(['image_path' => 'products/'.$product->code.'.'.$ext]);

            $this->info("  ✅ {$product->code} {$product->name} ← img/{$folder}/{$file->getFilename()}");
            $done++;
        }

        return [$done, $skipped];
    }
}
