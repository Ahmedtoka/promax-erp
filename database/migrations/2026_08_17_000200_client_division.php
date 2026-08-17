<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الديفيجن التجاري على العميل  ·  ١٧ أغسطس ٢٠٢٦
 *
 * `clients.division` — مفتاح من `App\Support\Divisions` (١١ قسم).
 * طريقة التعامل (كاش فان/ديلفري/أونلاين) **مش عمود**: مشتقة من
 * القسم في الكود — عمودين كانوا هيفترقوا مع أول تعديل يدوي.
 *
 * ⚠️ `nullable` عن قصد: العميل الغير مسكَّن بيبان «بدون قسم» في
 * الشاشة ويتسكّن بالسكريبت أو بالإيد — الافتراضي المفروض كان
 * هيخبّي المشكلة.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ⚠️ محروسة — السيرفر مش ريبو جيت والملفات بتترفع بالإيد
        Schema::table('clients', function (Blueprint $table) {
            if (! Schema::hasColumn('clients', 'division')) {
                $table->string('division', 30)->nullable()->index()
                    ->after('sub_channel');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (Schema::hasColumn('clients', 'division')) {
                $table->dropColumn('division');
            }
        });
    }
};
