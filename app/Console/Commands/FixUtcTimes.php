<?php

namespace App\Console\Commands;

use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * زحزحة تواريخ UTC القديمة +3 ساعات — مرة واحدة (2026-08-23)
 * ═══════════════════════════════════════════════════════════════
 *
 * الحكاية: اللايف اشتغل فترة من غير `APP_TIMEZONE=Africa/Cairo` في
 * الـ`.env`، فلارافيل خزّنت كل التواريخ بتوقيت UTC — والشاشات كانت
 * بتعرض «جه الساعة 07:19» لموظف جه فعلاً 10:19 (مصر UTC+3 صيفاً).
 * المدد كانت صح لأنها فروق، بس الساعات المعروضة كلها متأخرة 3 ساعات.
 *
 * الأمر ده بيلف على **كل** أعمدة datetime/timestamp في قاعدة البيانات
 * وبيزوّد القيم القديمة 3 ساعات عشان تبقى بتوقيت مصر زي الداتا الجديدة.
 *
 * ⚠️ **الترتيب على اللايف — في وقت هادي (بالليل قبل شغل الميدان):**
 *   1. ضيف `APP_TIMEZONE=Africa/Cairo` في `.env`
 *   2. `php artisan config:clear`
 *   3. `php artisan promax:fix-utc-times`          ← معاينة بس
 *   4. `php artisan promax:fix-utc-times --apply`  ← التنفيذ الفعلي
 *
 * الحمايات:
 *   • **من غير `--apply` = معاينة بس** — بيطبع الجداول والأعمدة والأعداد.
 *   • **حارس التكرار**: بعد التنفيذ بيكتب Setting باسم `tz_shift_done`
 *     — تشغيله تاني بيرفض فوراً. تشغيل الزحزحة مرتين = كل الساعات
 *     تتقدم 6 ساعات، وده أخطر من المشكلة الأصلية.
 *   • **كات أوف = لحظة التشغيل**: بيزحزح بس القيم الأقدم من «دلوقتي»
 *     — أي صف اتكتب بعد تصليح الـ`.env` (بقى توقيت مصر أصلاً) مش
 *     هيتلمس، حتى لو الأمر اتأخر شوية بعد الخطوة 2. القيم المستقبلية
 *     (استحقاق أوامر التوريد مثلاً) متسجلة كتاريخ-منتصف-ليل بنية
 *     «يوم» مش «لحظة» — فسيبها زي ما هي أصح.
 *
 * ⚠️ ممنوع تعديل الأمر ده يشتغل من غير الحارس — لو حصلت مشكلة نصلحها
 * بأمر جديد، مش بإعادة تشغيل ده.
 */
class FixUtcTimes extends Command
{
    protected $signature = 'promax:fix-utc-times
                            {--apply : التنفيذ الفعلي — من غيره معاينة بس}
                            {--hours=3 : فرق الساعات (مصر صيفاً = 3)}';

    protected $description = 'زحزحة كل التواريخ المخزّنة بتوقيت UTC لتوقيت مصر — مرة واحدة بس';

    /** حارس التكرار */
    private const FLAG = 'tz_shift_done';

    public function handle(): int
    {
        if (Setting::read(self::FLAG) !== null) {
            $this->error('❌ الزحزحة اتعملت قبل كده ('.Setting::read(self::FLAG).') — ممنوع تتكرر.');

            return self::FAILURE;
        }

        $hours = max(1, min(6, (int) $this->option('hours')));
        $apply = (bool) $this->option('apply');

        // ⚠️ الكات أوف بيتاخد مرة واحدة هنا — مش جوه اللوب، عشان كل
        // الأعمدة تتحاسب بنفس اللحظة بالظبط.
        $cutoff = now()->format('Y-m-d H:i:s');

        $db = DB::getDatabaseName();

        // كل أعمدة الوقت في الجداول الحقيقية (مش الفيوهات) —
        // DATE بره الحسبة: اليوم يوم مهما كانت التايم زون.
        $columns = DB::select(
            'SELECT c.TABLE_NAME AS t, c.COLUMN_NAME AS c
             FROM information_schema.COLUMNS c
             JOIN information_schema.TABLES tb
               ON tb.TABLE_SCHEMA = c.TABLE_SCHEMA AND tb.TABLE_NAME = c.TABLE_NAME
             WHERE c.TABLE_SCHEMA = ?
               AND tb.TABLE_TYPE = "BASE TABLE"
               AND c.DATA_TYPE IN ("datetime", "timestamp")
             ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION',
            [$db]
        );

        if ($columns === []) {
            $this->error('مفيش أعمدة وقت؟ اتأكد إنك على قاعدة البيانات الصح.');

            return self::FAILURE;
        }

        $this->info(($apply ? '🚀 تنفيذ فعلي' : '👀 معاينة بس — من غير --apply مفيش أي تعديل')
            ." · +{$hours} ساعات · الأقدم من {$cutoff}");
        $this->newLine();

        $total = 0;

        foreach ($columns as $col) {
            // ⚠️ backticks إجبارية — `returns` كلمة محجوزة في MySQL
            $where = "`{$col->c}` IS NOT NULL AND `{$col->c}` < ?";

            $count = (int) DB::selectOne(
                "SELECT COUNT(*) AS n FROM `{$col->t}` WHERE {$where}", [$cutoff]
            )->n;

            if ($count === 0) {
                continue;
            }

            if ($apply) {
                DB::update(
                    "UPDATE `{$col->t}` SET `{$col->c}` = DATE_ADD(`{$col->c}`, INTERVAL {$hours} HOUR) WHERE {$where}",
                    [$cutoff]
                );
            }

            $this->line(sprintf('  %s%-30s %-22s %6d صف', $apply ? '✔ ' : '· ', $col->t, $col->c, $count));
            $total += $count;
        }

        $this->newLine();

        if ($apply) {
            Setting::writeMany([self::FLAG => $cutoff]);
            $this->info("✅ اتزحزح {$total} قيمة وقت (+{$hours} ساعات) — والحارس اتقفل، الأمر مش هيشتغل تاني.");
        } else {
            $this->info("المعاينة: {$total} قيمة هتتزحزح. شغّل بـ --apply للتنفيذ.");
        }

        return self::SUCCESS;
    }
}
