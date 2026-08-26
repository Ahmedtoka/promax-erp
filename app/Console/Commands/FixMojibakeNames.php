<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\QuotationItem;
use Illuminate\Console\Command;

/**
 * ═══════════════════════════════════════════════════════════════
 * تصليح أسماء المنتجات المبوّظة من الاستيراد (٢٦ أغسطس ٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * البلاغ: «فيه علامة استفهام في أول حرف» — أسماء زي «�رو ماكس
 * بروتين بار» في عرض السعر. السبب: أول حرف («ب») اتخزن وقت
 * الاستيراد كرمز الاستبدال U+FFFD، فبيظهر � في كل مكان بيعرض
 * الاسم — المشكلة في الداتا مش في أي شاشة.
 *
 * الأمر بيمسح `products` (name + name_en) و`quotation_items`
 * (الأسماء المجمّدة) وبيصلّح الحالة الأكيدة بس:
 *
 *   U+FFFD وبعده مباشرة «رو ماكس»  ←  «برو ماكس»
 *
 * أي U+FFFD تاني (في نص الاسم مثلاً) بيتقال عليه في التقرير من
 * غير ما يتلمس — تصليحه تخمين، والتخمين في أسماء الكتالوج ممنوع.
 *
 * معاينة افتراضية + --apply (عقيدة أوامر الداتا ٢٣/٨). مفيش حارس
 * تكرار محتاجينه: الأمر idempotent — بعد التصليح مفيش FFFD يتلقط.
 *
 * التشغيل:  php artisan promax:fix-names
 *           php artisan promax:fix-names --apply
 */
class FixMojibakeNames extends Command
{
    protected $signature = 'promax:fix-names {--apply : التنفيذ الفعلي — من غيره معاينة بس}';

    protected $description = 'تصليح أسماء فيها رمز U+FFFD بدل «ب» في «برو ماكس» — منتجات + بنود عروض الأسعار';

    private const BAD = "\u{FFFD}";

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $this->info($apply ? '🚀 تنفيذ فعلي' : '👀 معاينة بس — من غير --apply مفيش أي تعديل');
        $this->newLine();

        $fixed = 0;
        $suspect = 0;

        // ═══ المنتجات — الاسمين ═══
        foreach (Product::where('name', 'like', '%'.self::BAD.'%')
            ->orWhere('name_en', 'like', '%'.self::BAD.'%')->get() as $p) {
            foreach (['name', 'name_en'] as $col) {
                [$new, $ok] = $this->mend((string) $p->{$col});

                if ($new === (string) $p->{$col}) {
                    continue;
                }

                $this->line(sprintf('  منتج #%-5d %-8s %s  ←  %s', $p->id, $col, $p->{$col}, $new));
                $fixed++;

                if ($apply) {
                    $p->update([$col => $new]);
                }
            }
        }

        // ═══ الأسماء المجمّدة على بنود عروض الأسعار ═══
        foreach (QuotationItem::where('name', 'like', '%'.self::BAD.'%')->get() as $qi) {
            [$new, $ok] = $this->mend((string) $qi->name);

            if ($new === (string) $qi->name) {
                continue;
            }

            $this->line(sprintf('  بند عرض #%-4d %s  ←  %s', $qi->id, $qi->name, $new));
            $fixed++;

            if ($apply) {
                $qi->update(['name' => $new]);
            }
        }

        // ═══ التقرير الختامي: أي FFFD لسه موجود (مش مطابق للقاعدة) ═══
        $left = Product::where('name', 'like', '%'.self::BAD.'%')
            ->orWhere('name_en', 'like', '%'.self::BAD.'%')->count()
            + QuotationItem::where('name', 'like', '%'.self::BAD.'%')->count();

        // في المعاينة اللي «هيتصلح» لسه محسوب ضمن الموجود — نطرحه
        if (! $apply) {
            $left = max(0, $left - $fixed);
            $suspect = $left;
        } else {
            $suspect = $left;
        }

        $this->newLine();
        $this->info("✅ ".($apply ? 'اتصلح' : 'هيتصلح')." {$fixed} اسم.");

        if ($suspect > 0) {
            $this->warn("⚠️ فيه {$suspect} اسم فيه رمز تالف في مكان تاني مش مطابق لقاعدة «برو ماكس» — دول محتاجين تصليح يدوي من شاشة المنتجات، والأمر ماليمسهومش عن قصد.");
        }

        if (! $apply && $fixed > 0) {
            $this->newLine();
            $this->info('لو المعاينة مظبوطة: php artisan promax:fix-names --apply');
        }

        return self::SUCCESS;
    }

    /**
     * القاعدة الأكيدة الوحيدة: FFFD + «رو ماكس» = «برو ماكس».
     * بتصلّح كل التكرارات جوه الاسم الواحد (لو الاسم فيه المقطع مرتين).
     */
    private function mend(string $name): array
    {
        $new = str_replace(self::BAD.'رو ماكس', 'برو ماكس', $name);

        return [$new, $new !== $name];
    }
}
