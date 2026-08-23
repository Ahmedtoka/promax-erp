<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جولة تظبيط الكوتيشن التانية (٢٣ أغسطس ٢٠٢٦):
 *   • `extra_pct` — خصم إضافي فوق الخصم العادي (بيتحسب تسلسلي:
 *     السعر × (1−العادي) × (1−الإضافي)).
 *   • `tax_inclusive` — تشيك بوكس «السعر شامل الضريبة»: الورقة
 *     بتكتب «الأسعار شاملة/غير شاملة الضريبة» حسب العلامة.
 *
 * ⚠️ محروسة — والافتراضيات بتخلي العروض القديمة زي ما هي.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('quotations') || Schema::hasColumn('quotations', 'extra_pct')) {
            return;
        }

        Schema::table('quotations', function (Blueprint $table) {
            $table->decimal('extra_pct', 5, 2)->default(0)->after('discount_pct');
            $table->boolean('tax_inclusive')->default(true)->after('tax_pct');
        });
    }

    public function down(): void
    {
        // مفيش رجوع — أعمدة إضافية مش بتكسر القديم
    }
};
