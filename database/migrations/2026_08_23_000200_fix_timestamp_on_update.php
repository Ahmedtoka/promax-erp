<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════
 * قتل فخ ON UPDATE CURRENT_TIMESTAMP الضمني (2026-08-23)
 * ═══════════════════════════════════════════════════════════════
 *
 * MySQL القديم (explicit_defaults_for_timestamp=0) بيدي أول عمود
 * timestamp في الجدول خاصية `ON UPDATE CURRENT_TIMESTAMP` ضمنياً —
 * يعني **أي UPDATE على الصف بيدوس على قيمة العمود بوقت التعديل**.
 * ده اللي مسح أوقات البانشات التاريخية كلها يوم تصليح التايم زون:
 * أمر الزحزحة عدّل created_at فـMySQL كتب في `at` وقت تشغيل الأمر.
 *
 * تشخيص promax:tz-doctor حدد التلات أعمدة المصابة بالظبط —
 * بنحوّلهم DATETIME: بيشيل الـDEFAULT والـON UPDATE مع بعض،
 * والقيم الموجودة بتتنقل زي ما هي من غير أي تحويل.
 *
 * ⚠️ لازم تتنفذ **قبل** أمر promax:tz-final-repair — الأمر بيرفض
 * يشتغل والفخ لسه موجود عشان تصليحه مايتداسش هو كمان.
 */
return new class extends Migration
{
    public function up(): void
    {
        $fix = [
            ['attendance_punches', 'at'],
            ['track_events', 'happened_at'],
            ['warehouse_visits', 'checked_in_at'],
        ];

        foreach ($fix as [$table, $column]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            // محروسة بالمعنى الحقيقي: لو العمود متصلّح خلاص (مفيش
            // on update في الـEXTRA) مانلمسوش — التشغيل التاني آمن.
            $extra = DB::selectOne(
                'SELECT EXTRA AS e FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$table, $column]
            );

            if ($extra === null || stripos((string) $extra->e, 'on update') === false) {
                continue;
            }

            DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` DATETIME NULL DEFAULT NULL");
        }
    }

    public function down(): void
    {
        // مفيش رجوع — الفخ ده مانرجعهوش بإيدنا أبداً
    }
};
