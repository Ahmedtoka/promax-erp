<?php

namespace App\Console\Commands;

use App\Models\Lead;
use Illuminate\Console\Command;

/**
 * ═══════════════════════════════════════════════════════════════
 * تصفير ميتا العملاء المحتملين (٢٦ أغسطس ٢٠٢٦ — طلب المالك)
 * ═══════════════════════════════════════════════════════════════
 *
 * «هبدأ أسكّن المناديب من الصفر — لما المندوب ينزل ويشوف العميل
 * ساعتها بيحط ملاحظته وأنا بحط المتوقع». فالأمر بيصفّر تلات حاجات
 * على **المفتوحين بس**:
 *
 *   • المتوقع شهرياً ← 0   (كان تقدير موديل الشيت مش تقدير بشري)
 *   • الملاحظات ← فاضية    (كانت ميتا الاستيراد: قطاع/أولوية/SKU)
 *   • المندوب المسؤول ← لا أحد (التوزيع هيبدأ يدوي أو Apply)
 *
 * ⚠️ المقفولين (كسبناهم/خسرناهم/المتحوّلين) مايتلمسوش — تاريخهم
 * هو اللي «حصاد الشهر» هيتبني عليه (مين جاب مين).
 *
 * معاينة افتراضية + --apply (عقيدة أوامر الداتا ٢٣/٨). مفيش حارس
 * تكرار في Settings: الأمر idempotent — تشغيله تاني بيلاقي أصفار.
 *
 * التشغيل:  php artisan promax:leads-reset-meta
 *           php artisan promax:leads-reset-meta --apply
 */
class LeadsResetMeta extends Command
{
    protected $signature = 'promax:leads-reset-meta {--apply : التنفيذ الفعلي — من غيره معاينة بس}';

    protected $description = 'تصفير المتوقع شهرياً والملاحظات والمندوب المسؤول لكل العملاء المحتملين المفتوحين';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $this->info($apply ? '🚀 تنفيذ فعلي' : '👀 معاينة بس — من غير --apply مفيش أي تعديل');
        $this->newLine();

        $base = fn () => Lead::whereIn('status', Lead::OPEN_STATUSES);

        $withExpected = (clone $base())->where('expected_monthly', '>', 0)->count();
        $withNotes = (clone $base())->whereNotNull('notes')->where('notes', '!=', '')->count();
        $withRep = (clone $base())->whereNotNull('assigned_to')->count();
        $total = $base()->count();

        $this->line("  إجمالي المحتملين المفتوحين: {$total}");
        $this->line("  عندهم متوقع شهرياً > 0:     {$withExpected}  ← هيبقى 0");
        $this->line("  عندهم ملاحظات:               {$withNotes}  ← هتتشال");
        $this->line("  متسكّنين على مندوب:          {$withRep}  ← هيبقوا بلا مندوب");
        $this->newLine();

        if (! $apply) {
            $this->info('لو تمام: php artisan promax:leads-reset-meta --apply');

            return self::SUCCESS;
        }

        $n = $base()->update([
            'expected_monthly' => 0,
            'notes' => null,
            'assigned_to' => null,
        ]);

        $this->info("✅ اتصفّر {$n} عميل محتمل — ابدأ التسكين والمناديب هيكتبوا ملاحظاتهم من الميدان.");

        return self::SUCCESS;
    }
}
