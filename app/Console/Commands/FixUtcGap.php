<?php

namespace App\Console\Commands;

use App\Models\AttendanceDay;
use App\Models\Setting;
use App\Services\Attendance;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * تصليح صفوف «النافذة» — اللي اتسجلت UTC وسط داتا مظبوطة (2026-08-23)
 * ═══════════════════════════════════════════════════════════════
 *
 * الحكاية: يوم تصليح التايم زون، أمر `promax:fix-utc-times` اشتغل
 * **قبل** ما `APP_TIMEZONE=Africa/Cairo` تتفعّل بـ`config:clear`.
 * فأي صف اتسجل في الفترة اللي بينهم اتخزن UTC (ناقص 3 ساعات) وسط
 * داتا كلها بقت بتوقيت مصر. النتيجة في الحضور تحديداً كارثية:
 * ترتيب البانشات بالوقت اتلخبط — آلة الحالات بتقرا «آخر بانش»
 * بالترتيب الزمني، فبانش قديم شكله «أحدث» من بانش النافذة، والسيستم
 * بيرفض الحضور والانصراف برسايل «انت شغال أصلاً» وأخواتها.
 *
 * الأمر ده بيزحزح +3 ساعات **بس** القيم اللي جوه النافذة دي:
 *   • البداية = لحظة تشغيل الزحزحة الأولى (متخزنة في Setting
 *     `tz_shift_done` — بتتقري أوتوماتيك).
 *   • النهاية = وقت ما عملت `config:clear` بساعة مصر — انت بتدّيها
 *     بـ`--fixed-at=HH:MM` والأمر بيحولها لصيغة التخزين بنفسه.
 *
 * وبعد الزحزحة بيعيد حساب أيام حضور النهارده كلها
 * (`Attendance::recalculate`) عشان الساعات والحالات ترجع من السجل
 * المتصلّح — من غير الخطوة دي الأعمدة المخزّنة كانت هتفضل غلط.
 *
 * التشغيل:
 *   php artisan promax:fix-utc-gap --fixed-at=11:15            ← معاينة
 *   php artisan promax:fix-utc-gap --fixed-at=11:15 --apply    ← تنفيذ
 *
 * الحمايات: معاينة افتراضية · حارس `tz_gap_done` ضد التكرار ·
 * بيرفض لو الزحزحة الأولى أصلاً ماحصلتش.
 *
 * ⚠️ حدود النافذة لازم تكون دقيقة قدر الإمكان — قيمة جوه النطاق ده
 * ممكن نظرياً تكون صف قديم متزحزح صح (نشاط حقيقي الصبح بدري).
 * عشان كده المعاينة بتطبع كل صف متأثر بجدوله — راجعها قبل --apply.
 */
class FixUtcGap extends Command
{
    protected $signature = 'promax:fix-utc-gap
                            {--fixed-at= : وقت تفعيل التايم زون (config:clear) بساعة مصر — مثال 11:15}
                            {--date= : تاريخ يوم التصليح (الافتراضي النهارده) YYYY-MM-DD}
                            {--apply : التنفيذ الفعلي — من غيره معاينة بس}';

    protected $description = 'زحزحة صفوف النافذة اللي اتسجلت UTC ما بين أمر الزحزحة وتفعيل التايم زون';

    private const FLAG = 'tz_gap_done';

    public function handle(): int
    {
        if (Setting::read(self::FLAG) !== null) {
            $this->error('❌ تصليح النافذة اتعمل قبل كده ('.Setting::read(self::FLAG).') — ممنوع يتكرر.');

            return self::FAILURE;
        }

        $from = Setting::read('tz_shift_done');

        if ($from === null) {
            $this->error('❌ مفيش زحزحة أولى متسجلة (tz_shift_done) — الأمر ده معمول لما بعدها بس.');

            return self::FAILURE;
        }

        $fixedAt = (string) $this->option('fixed-at');

        if (! preg_match('/^\d{1,2}:\d{2}$/', $fixedAt)) {
            $this->error('اديني وقت تفعيل التايم زون بساعة مصر: --fixed-at=11:15 (الساعة اللي عملت فيها config:clear).');

            return self::FAILURE;
        }

        $date = $this->option('date')
            ? Carbon::parse((string) $this->option('date'))
            : Carbon::today();

        // ⚠️ التحويل لصيغة التخزين: صفوف النافذة متخزنة UTC، يعني
        // ساعة مصر − 3. حد النافذة الأعلى بنفس المنطق.
        $to = $date->copy()->setTimeFromTimeString($fixedAt)->subHours(3)->format('Y-m-d H:i:s');

        if ($to <= $from) {
            $this->error("❌ نهاية النافذة ({$to}) قبل بدايتها ({$from}) — راجع --fixed-at و--date.");

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');

        $this->info(($apply ? '🚀 تنفيذ فعلي' : '👀 معاينة بس — من غير --apply مفيش أي تعديل')
            ." · النافذة المخزّنة: [{$from} → {$to}] · +3 ساعات");
        $this->newLine();

        $db = DB::getDatabaseName();

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

        $total = 0;

        foreach ($columns as $col) {
            // ⚠️ backticks إجبارية — `returns` كلمة محجوزة في MySQL
            $where = "`{$col->c}` IS NOT NULL AND `{$col->c}` >= ? AND `{$col->c}` < ?";

            $count = (int) DB::selectOne(
                "SELECT COUNT(*) AS n FROM `{$col->t}` WHERE {$where}", [$from, $to]
            )->n;

            if ($count === 0) {
                continue;
            }

            if ($apply) {
                DB::update(
                    "UPDATE `{$col->t}` SET `{$col->c}` = DATE_ADD(`{$col->c}`, INTERVAL 3 HOUR) WHERE {$where}",
                    [$from, $to]
                );
            }

            $this->line(sprintf('  %s%-30s %-22s %6d صف', $apply ? '✔ ' : '· ', $col->t, $col->c, $count));
            $total += $count;
        }

        $this->newLine();

        if (! $apply) {
            $this->info("المعاينة: {$total} قيمة جوه النافذة. راجع الجداول فوق — لو منطقية شغّل بـ --apply.");

            return self::SUCCESS;
        }

        // ═══ إعادة حساب أيام حضور يوم النافذة ═══
        //
        // ⚠️ **مش رفاهية** — worked_minutes وfirst_in_at والحالة كلهم
        // اتحسبوا من ترتيب بانشات ملخبط. الزحزحة صلّحت السجل الخام،
        // وإعادة الحساب بتصلّح المحصلة. من غيرها الشاشات بتفضل غلط.
        $days = AttendanceDay::whereDate('date', $date->toDateString())->get();

        foreach ($days as $day) {
            Attendance::recalculate($day);
        }

        Setting::writeMany([self::FLAG => "[{$from} → {$to}]"]);

        $this->info("✅ اتزحزح {$total} قيمة، واتعاد حساب {$days->count()} يوم حضور — والحارس اتقفل.");
        $this->line('قول للموظفين يقفلوا الأبلكيشن ويفتحوه تاني — القراية القديمة متكاشة عندهم.');

        return self::SUCCESS;
    }
}
