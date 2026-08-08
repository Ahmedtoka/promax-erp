<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════
 * دورة التجهيز + طرق التحصيل (قرارات المالك ٨ أغسطس ٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pick_orders', function (Blueprint $table) {
            // ⚠️ **«ابدأ التجهيز» بقى إجباري والسيرفر بيرفض «جاهز»
            // قبله** (قرار المالك). العمود ده هو اللي بيثبت إنها
            // اتبدت — و`status = picking` لوحدها ماكانتش بتقول
            // **إمتى**، فمدة التجهيز ماكانش لها مصدر أصلاً.
            if (! Schema::hasColumn('pick_orders', 'started_at')) {
                $table->timestamp('started_at')->nullable()->after('pickup_at');
            }
        });

        // ═══ طرق التحصيل (قرار المالك ٨/٨/٢٠٢٦) ═══
        //
        // ⚠️ **التحصيل كان رقم بلا طريقة.** المحاسب بيقفل اليومية
        // ومعاه «١٢٠٠٠ تحصيل» من غير ما يعرف كام منهم كاش في الخزنة
        // وكام شيك في الدرج وكام تحويل لازم يتطابق مع كشف البنك.
        //
        // ⚠️ **الشيك بيدخل حساب العميل فوراً زي الكاش** (قرار المالك
        // صراحةً) — يعني القيد `collection` زي ما هو، والفرق في
        // البيانات المرفقة مش في المحاسبة.
        Schema::table('transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('transactions', 'method')) {
                // cash | card | cheque | transfer
                $table->string('method', 20)->nullable()->after('kind');
            }

            // ⚠️ **إجباري لغير النقدي** — الفاليديشن في الكنترولر مش
            // في الداتابيز، لأن القيود القديمة كلها بلا ريفرنس.
            if (! Schema::hasColumn('transactions', 'reference')) {
                $table->string('reference', 100)->nullable()->after('method');
            }

            // ═══ بيانات الشيك — بتتملي لما `method = cheque` بس ═══
            if (! Schema::hasColumn('transactions', 'cheque_bank')) {
                $table->string('cheque_bank', 120)->nullable()->after('reference');
            }

            if (! Schema::hasColumn('transactions', 'cheque_due')) {
                $table->date('cheque_due')->nullable()->after('cheque_bank');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pick_orders', function (Blueprint $table) {
            if (Schema::hasColumn('pick_orders', 'started_at')) {
                $table->dropColumn('started_at');
            }
        });

        Schema::table('transactions', function (Blueprint $table) {
            foreach (['method', 'reference', 'cheque_bank', 'cheque_due'] as $col) {
                if (Schema::hasColumn('transactions', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
