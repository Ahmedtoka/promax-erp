<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * توسيع عمود كود حساب النقدية مع المندوب  ·  ١١ سبتمبر ٢٠٢٦
 *
 * `gl_accounts.code` اتعمل `varchar(20)` في 2026_09_11_000100_gl_core،
 * لكن `GlAccount::repCash()` بيولّد الكود بـ`{parent->code}.{user->code}`
 * و`users.code` نفسه `varchar(30)` (2026_07_29_000002) — يعني أقصى كود
 * ممكن (كود جذر ٤ حروف + نقطة + كود مستخدم ٣٠ حرف) = ٣٥ حرف، وده أطول
 * من الـ٢٠ اللي العمود بيقبلها. `1406 Data too long` بتطلع أول ما مندوب
 * بكود طويل (زي أكواد التيست العشوائية `SLS-XXXXXXXXXXXXX`) ياخد أول
 * تحصيل كاش ليه.
 *
 * ٨٠ = هامش فوق الأقصى النظري (٣٥) لأي مستوى تسلسل أعمق مستقبلاً.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ⚠️ محروسة بمعنى «آمنة للتكرار» — `change()` على عمود واسع
        // خلاص مابيكسرش، والفحص مش ضروري.
        // ⚠️ من غير `->unique()` هنا عمداً — الفهرس الفريد موجود من
        // مايجريشن 000100 وMODIFY COLUMN في ماي إس كيو إل مابيلمسوش؛
        // إعادة كتابتها كانت هتحاول تعمل فهرس مكرر وترمي «Duplicate key name».
        Schema::table('gl_accounts', function (Blueprint $table) {
            $table->string('code', 80)->change();
        });
    }

    public function down(): void
    {
        // ⚠️ **مفيش رجوع لـ20** — التضييق بيقصقص أي كود طويل اتكتب
        // بعد التوسيع ويحوّله لقيمة مالهاش معنى في صمت.
    }
};
