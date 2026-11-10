<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ═══ «كل العملاء خاضعين» — الافتراضي بقى خاضع (قرار المالك ١٠/١١/٢٠٢٦) ═══
 *
 * المبدأ: السعر المتخزّن **غير شامل دايماً** والضريبة بتتضاف فوقه مرة
 * واحدة. «شامل» بقت عرض بس، مش حالة ضريبية.
 *
 * ⚠️ العمود كان افتراضيه `false` — فأي عميل بيتعمل من غير ما حد يعلّم
 * الخانة (تحويل ليد، موافقة طلب عميل، الأبلكيشن، الاستيراد) كان بينزل
 * «معفى» في صمت، والفاتورة تطلع من غير ضريبة.
 *
 * ⚠️ المايجريشن دي بتغيّر **الافتراضي بس** — مابتلمسش صفوف موجودة.
 * تصليح الصفوف القديمة بيتعمل من `prices:apply-sheet` (dry-run الأول).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('clients', 'taxable')) {
            Schema::table('clients', function (Blueprint $t) {
                $t->boolean('taxable')->default(true)->change();
            });
        }
        if (Schema::hasColumn('client_groups', 'taxable')) {
            Schema::table('client_groups', function (Blueprint $t) {
                $t->boolean('taxable')->nullable()->default(true)->change();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('clients', 'taxable')) {
            Schema::table('clients', function (Blueprint $t) {
                $t->boolean('taxable')->default(false)->change();
            });
        }
        if (Schema::hasColumn('client_groups', 'taxable')) {
            Schema::table('client_groups', function (Blueprint $t) {
                $t->boolean('taxable')->nullable()->default(null)->change();
            });
        }
    }
};
