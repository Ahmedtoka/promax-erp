<?php

namespace App\Console\Commands;

use App\Models\AttendanceDay;
use App\Models\Setting;
use App\Services\Attendance;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════
 * التصليحة النهائية لحادثة التايم زون (2026-08-23)
 * ═══════════════════════════════════════════════════════════════
 *
 * مبنية على تشخيص promax:tz-doctor الفعلي من اللايف — مش تخمين:
 *
 *   • كل البانشات التاريخية `at` اتداست بـ10:47:12 (فخ ON UPDATE) —
 *     بنرجّعها من `created_at` (البانش بيتسجل لحظة حدوثه، فوقته
 *     الحقيقي = وقت إنشاء الصف، والـcreated_at اتصلّح بالزحزحة).
 *   • بانشات النهارده من id 163 وطالع اتسجلت وPHP لسه UTC —
 *     بنزوّدها 3 ساعات وبنبني `at` منها.
 *   • track_events.happened_at وwarehouse_visits.checked_in_at
 *     اتداسوا بنفس الفخ — نفس العلاج: القيمة الحقيقية ≈ created_at
 *     (بنصلّح بس اللي بعيد عن created_at بأكتر من 30 دقيقة).
 *   • باقي صفوف النهارده اللي اتسجلت UTC بعد نهاية نافذة الإصلاح
 *     السابقة (08:15:00) — زحزحة +3 بالنطاق زي أمر النافذة.
 *   • وآخر حاجة: إعادة حساب كل أيام حضور النهارده.
 *
 * ⚠️ **الترتيب إجباري والأمر بيفرضه بنفسه:**
 *   1. APP_TIMEZONE=Africa/Cairo في .env + config:clear —
 *      الأمر **بيرفض يشتغل** لو PHP لسه UTC.
 *   2. php artisan migrate (مايجريشن قتل الفخ) —
 *      الأمر **بيرفض يشتغل** لو الفخ لسه موجود.
 *   3. المعاينة ثم --apply. حارس تكرار زي إخواته.
 *
 * ملحوظة موثّقة: أحداث تتبع الصبح بدري النهارده (قبل 10:47) ممكن
 * تتزحزح 3 ساعات زيادة في خطوة النطاق — عيب تجميلي في التايم لاين
 * مقبول، عكسه كان هيسيب مستندات النهارده كلها غلط.
 */
class TzFinalRepair extends Command
{
    protected $signature = 'promax:tz-final-repair
                            {--apply : التنفيذ الفعلي — من غيره معاينة بس}';

    protected $description = 'استرجاع أوقات البانشات والتتبع المداسة + تصليح صفوف اليوم الـUTC + إعادة حساب الحضور';

    private const FLAG = 'tz_final_done';

    /** لحظة تشغيل الزحزحة الأولى — من التشخيص */
    private const CUTOFF = '2026-08-23 07:47:12';

    /** نهاية اللي صلّحه أمر النافذة السابق */
    private const GAP_END = '2026-08-23 08:15:00';

    /** أول بانش اتسجل بعد الزحزحة (من dump التشخيص: 163–171 UTC) */
    private const FIRST_POST_PUNCH = 163;

    public function handle(): int
    {
        if (Setting::read(self::FLAG) !== null) {
            $this->error('❌ التصليحة النهائية اتعملت قبل كده ('.Setting::read(self::FLAG).') — ممنوع تتكرر.');

            return self::FAILURE;
        }

        // ═══ حارس الترتيب ١: PHP لازم يكون على توقيت مصر ═══
        if (config('app.timezone') !== 'Africa/Cairo') {
            $this->error('❌ PHP لسه على '.config('app.timezone').' — ظبط APP_TIMEZONE=Africa/Cairo في .env');
            $this->line('   وبعدين: php artisan config:clear && php artisan optimize:clear');
            $this->line('   واتأكد بـ: php artisan promax:tz-doctor');

            return self::FAILURE;
        }

        // ═══ حارس الترتيب ٢: فخ ON UPDATE لازم يكون اتقتل الأول ═══
        $traps = DB::select(
            'SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND EXTRA LIKE "%on update%"
               AND TABLE_NAME IN ("attendance_punches", "track_events", "warehouse_visits")'
        );

        if ($traps !== []) {
            $this->error('❌ فخ ON UPDATE لسه موجود — شغّل php artisan migrate الأول (مايجريشن 2026_08_23_000200).');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        // صفوف اتكتبت UTC ⇐ قيمتها ≤ دلوقتي−3س. اللي بعد تصليح الـ.env
        // (توقيت مصر) قيمتها قريبة من دلوقتي — النطاق ده مش بيلمسها.
        $utcEdge = now()->subHours(3)->format('Y-m-d H:i:s');

        $this->info(($apply ? '🚀 تنفيذ فعلي' : '👀 معاينة بس — من غير --apply مفيش أي تعديل')
            ." · حد الصفوف الـUTC: {$utcEdge}");
        $this->newLine();

        $stmts = [];

        // ═══ ١) البانشات التاريخية: at := created_at ═══
        $stmts[] = ['البانشات التاريخية (يدوي): at من created_at',
            "UPDATE `attendance_punches` SET `at` = `created_at`
             WHERE `created_at` < ? AND `auto` = 0 AND `at` <> `created_at`",
            [self::CUTOFF]];

        // ⚠️ بانش القفل التلقائي وقته الحقيقي آخر ثانية في يوم الشيفت
        // مش لحظة الكرون — بنبنيه من تاريخ اليوم نفسه
        $stmts[] = ['بانشات القفل التلقائي: at = آخر ثانية في يوم الشيفت',
            "UPDATE `attendance_punches` p JOIN `attendance_days` d ON d.`id` = p.`attendance_day_id`
             SET p.`at` = CONCAT(d.`date`, ' 23:59:59')
             WHERE p.`created_at` < ? AND p.`auto` = 1",
            [self::CUTOFF]];

        // ═══ ٢) بانشات النهارده الـUTC (من 163): +3س وat منها ═══
        // ⚠️ ترتيب الـSET مقصود — MySQL بيقيّم بالترتيب، فـat بتتحسب
        // من created_at **القديمة** قبل ما نزوّدها هي نفسها.
        $stmts[] = ['بانشات النهارده الـUTC: +3 ساعات',
            'UPDATE `attendance_punches`
             SET `at` = DATE_ADD(`created_at`, INTERVAL 3 HOUR),
                 `created_at` = DATE_ADD(`created_at`, INTERVAL 3 HOUR),
                 `updated_at` = DATE_ADD(`updated_at`, INTERVAL 3 HOUR)
             WHERE `id` >= ? AND `created_at` <= ?',
            [self::FIRST_POST_PUNCH, $utcEdge]];

        // ═══ ٣) أعمدة الفخ التانية: القيمة الحقيقية ≈ created_at ═══
        // البعيد عن created_at بأكتر من 30 دقيقة = مداس (10:47 أو 15:55)
        $stmts[] = ['track_events: happened_at المداسة من created_at',
            'UPDATE `track_events` SET `happened_at` = `created_at`
             WHERE ABS(TIMESTAMPDIFF(MINUTE, `happened_at`, `created_at`)) > 30',
            []];

        $stmts[] = ['warehouse_visits: checked_in_at المداسة من created_at',
            'UPDATE `warehouse_visits` SET `checked_in_at` = `created_at`
             WHERE ABS(TIMESTAMPDIFF(MINUTE, `checked_in_at`, `created_at`)) > 30',
            []];

        // ═══ ٤) باقي صفوف النهارده الـUTC: زحزحة بالنطاق ═══
        // نفس منطق أمر النافذة — من نهاية اللي هو صلّحه لحد حد الـUTC.
        // البانشات مستثناة (خطوة ٢ غطتها بالـid) وأعمدة المواعيد
        // اليدوية (due_at/pickup_at) مستثناة: المستخدم كتبها بساعة
        // مصر أصلاً — زحزحتها كانت هتبوّظ مواعيد صح.
        $skip = [
            'attendance_punches' => true,
            'purchase_orders.due_at' => true,
            'purchase_orders.pickup_at' => true,
        ];

        $columns = DB::select(
            'SELECT c.TABLE_NAME AS t, c.COLUMN_NAME AS c
             FROM information_schema.COLUMNS c
             JOIN information_schema.TABLES tb
               ON tb.TABLE_SCHEMA = c.TABLE_SCHEMA AND tb.TABLE_NAME = c.TABLE_NAME
             WHERE c.TABLE_SCHEMA = DATABASE() AND tb.TABLE_TYPE = "BASE TABLE"
               AND c.DATA_TYPE IN ("datetime", "timestamp")
             ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION'
        );

        foreach ($columns as $col) {
            if (isset($skip[$col->t]) || isset($skip[$col->t.'.'.$col->c])) {
                continue;
            }

            $stmts[] = ["نطاق UTC: {$col->t}.{$col->c}",
                "UPDATE `{$col->t}` SET `{$col->c}` = DATE_ADD(`{$col->c}`, INTERVAL 3 HOUR)
                 WHERE `{$col->c}` >= ? AND `{$col->c}` <= ?",
                [self::GAP_END, $utcEdge]];
        }

        // ═══ التنفيذ / المعاينة ═══
        $total = 0;

        foreach ($stmts as [$label, $sql, $bind]) {
            $countSql = preg_replace('/^\s*UPDATE\s+(`\w+`(?:\s+\w+)?(?:\s+JOIN[^S]+?ON[^S]+?)?)\s+SET\s.+?\sWHERE\s/is',
                'SELECT COUNT(*) AS n FROM $1 WHERE ', $sql);
            $n = (int) DB::selectOne($countSql, $bind)->n;

            if ($n === 0) {
                continue;
            }

            if ($apply) {
                DB::update($sql, $bind);
            }

            $this->line(sprintf('  %s%-52s %6d صف', $apply ? '✔ ' : '· ', mb_substr($label, 0, 50), $n));
            $total += $n;
        }

        $this->newLine();

        if (! $apply) {
            $this->info("المعاينة: {$total} تعديل. راجع وبعدين --apply.");

            return self::SUCCESS;
        }

        // ═══ ٥) إعادة حساب أيام حضور النهارده من السجل المتصلّح ═══
        $days = AttendanceDay::whereDate('date', today()->toDateString())->get();

        foreach ($days as $day) {
            Attendance::recalculate($day);
        }

        Setting::writeMany([self::FLAG => now()->toDateTimeString()]);

        $this->info("✅ {$total} تعديل + إعادة حساب {$days->count()} يوم حضور — والحارس اتقفل.");
        $this->line('قول للموظفين يقفلوا الأبلكيشن ويفتحوه — وجرّب انصراف لأي حد منهم للتأكيد.');

        return self::SUCCESS;
    }
}
