<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * باكفيل فريق العميل (١١ أغسطس ٢٠٢٦ مساءً).
 *
 * ⚠️ **ليه:** التسكين القديم كان بيظبط `rep_id` بس و`manager_id`
 * بيفضل فاضي — فالعميل المتسكّن مش داخل بول فريق مديره، وكروت
 * «فرق القنوات» مجاميعها أقل من الإجمالي، وزملاء المندوب مش
 * شايفينه (قاعدة البول بتقرا manager_id).
 *
 * بتعمل إيه: أي عميل ليه مندوب أساسي، والمندوب ده ليه مدير،
 * و`manager_id` بتاع العميل فاضي → بياخد مدير مندوبه. ولو المندوب
 * الأساسي هو نفسه مدير قناة → العميل بياخده هو. آمنة للإعادة
 * (idempotent) — بتحدّث الفاضي بس وعمرها ما تلمس مدير متظبط.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('clients', 'manager_id') || ! Schema::hasColumn('clients', 'rep_id')) {
            return;
        }

        // العميل ياخد مدير مندوبه الأساسي
        DB::statement(<<<'SQL'
            UPDATE clients c
            JOIN users u ON u.id = c.rep_id
            SET c.manager_id = u.manager_id
            WHERE c.manager_id IS NULL
              AND u.manager_id IS NOT NULL
        SQL);

        // ولو المندوب الأساسي نفسه مدير قناة — العميل بتاع فريقه هو
        DB::statement(<<<'SQL'
            UPDATE clients c
            JOIN users u ON u.id = c.rep_id
            SET c.manager_id = u.id
            WHERE c.manager_id IS NULL
              AND u.role = 'manager'
        SQL);
    }

    public function down(): void
    {
        // باكفيل بيانات — مفيش رجوع آمن (مانعرفش مين كان فاضي قبلها)
    }
};
