<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سيريال الفاتورة الورقية  ·  ١٩ أغسطس ٢٠٢٦
 *
 * طلب المالك: المندوب بيكتب فاتورة ورقية مختومة بإيده في الشارع
 * (مثلاً 65221) — السيريال ده لازم يتسجل على فاتورة السيستم عشان
 * المطابقة بين الدفتر الورقي والسيستم تبقى ممكنة. بيتبعت من شاشة
 * المراجعة في الأبلكيشن، وللفواتير القديمة بيتضاف من الداشبورد.
 *
 * ⚠️ مفهرس — البحث بالسيريال الورقي هيبقى استعلام يومي للمطابقة.
 * ⚠️ محروسة — السيرفر مش ريبو جيت والمالك بيرفع بإيده.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'paper_ref')) {
                $table->string('paper_ref', 30)->nullable()->index()->after('number');
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'paper_ref')) {
                $table->dropColumn('paper_ref');
            }
        });
    }
};
