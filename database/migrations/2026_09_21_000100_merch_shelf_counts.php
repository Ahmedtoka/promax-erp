<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جرد الرف للمنسق + إنهاء الزيارة بدون تصوير (٢١ سبتمبر ٢٠٢٦).
 *
 * `shelf_counts` — المنسق بيكتب بإيده لكل صنف: الكمية بوحدة القياس
 * وتاريخ الإنتاج والانتهاء. الصف مربوط بالزيارة **وبالفرع**، عشان
 * الزيارة الجاية تفتح على آخر جرد اتعمل في نفس الفرع وتقارن بيه.
 *
 * `merch_visits.no_photos` — الزيارة اتقفلت من غير صورة قبل/بعد بعلم
 * المنسق وبسببه المكتوب. مش بتختفي: بتطلع تنبيه لمديره وشارة حمرا
 * في شاشة متابعة الرفوف.
 *
 * ⚠️ كل خطوة محروسة — السيرفر اللايف بيترفع عليه بالإيد.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('shelf_counts')) {
            Schema::create('shelf_counts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('merch_visit_id')->constrained()->cascadeOnDelete();
                $table->foreignId('client_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_id')->constrained()->cascadeOnDelete();
                // الكمية زي ما المنسق كتبها بوحدتها، و`pieces` المحسوبة
                // بالقطع عشان المقارنة بين الزيارات تبقى على وحدة واحدة
                $table->decimal('qty', 10, 2)->default(0);
                $table->string('unit', 10)->default('piece');
                $table->unsignedInteger('pieces')->default(0);
                $table->date('production_date')->nullable();
                $table->date('expiry_date')->nullable();
                $table->string('note', 190)->nullable();
                $table->timestamps();

                $table->index(['client_id', 'product_id', 'id'], 'shelf_counts_branch_idx');
            });
        }

        Schema::table('merch_visits', function (Blueprint $table) {
            if (! Schema::hasColumn('merch_visits', 'no_photos')) {
                $table->boolean('no_photos')->default(false)->after('photo_after');
            }
            if (! Schema::hasColumn('merch_visits', 'no_photo_reason')) {
                $table->string('no_photo_reason', 190)->nullable()->after('no_photos');
            }
        });
    }

    public function down(): void
    {
        Schema::table('merch_visits', function (Blueprint $table) {
            foreach (['no_photo_reason', 'no_photos'] as $col) {
                if (Schema::hasColumn('merch_visits', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::dropIfExists('shelf_counts');
    }
};
