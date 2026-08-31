<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * باقي أسئلة المراجعة (٢٨ أغسطس ٢٠٢٦ — الجولة التانية).
 *
 * سلسلة السؤال بالحرف زي ما المالك وصفها:
 *   حسابه موجود؟ → كام الحساب → معاك كشف الحساب؟ → ارفعه →
 *   **معاك إذون استلام كشف الحساب؟** → **اتعملت فاتورة ضريبية؟**
 *
 * و«التشانيل مانجر هيروح للعميل وهو هناك هيدوس تم التأكيد إن
 * الحساب مظبوط» — ده `confirmed_at`/`confirmed_by`.
 *
 * ⚠️ **مايجريشن منفصلة مش تعديل على `000200`**: الأولى ممكن تكون
 * اشتغلت على اللايف خلاص، وتعديل مايجريشن اشتغلت بيخلي السيرفرين
 * مختلفين في صمت. القاعدة: مايجريشن جديدة محروسة.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('account_audits')) {
            return;
        }

        Schema::table('account_audits', function (Blueprint $table) {
            // إذون استلام الكشف — الهارد كوبي الموقّعة
            if (! Schema::hasColumn('account_audits', 'has_receipt')) {
                $table->boolean('has_receipt')->nullable()->after('statement_name');
            }

            // اتعملت فاتورة ضريبية؟ (Billed / Unbilled)
            if (! Schema::hasColumn('account_audits', 'tax_invoice')) {
                $table->boolean('tax_invoice')->nullable()->after('has_receipt');
            }

            // تأكيد مدير القناة من عند العميل نفسه
            if (! Schema::hasColumn('account_audits', 'confirmed_at')) {
                // ⚠️ dateTime مش timestamp — فخ ON UPDATE (٢٣/٨)
                $table->dateTime('confirmed_at')->nullable()->after('tax_invoice');
            }

            if (! Schema::hasColumn('account_audits', 'confirmed_by')) {
                $table->foreignId('confirmed_by')->nullable()->after('confirmed_at')
                    ->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('account_audits')) {
            return;
        }

        Schema::table('account_audits', function (Blueprint $table) {
            foreach (['has_receipt', 'tax_invoice', 'confirmed_at'] as $col) {
                if (Schema::hasColumn('account_audits', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
