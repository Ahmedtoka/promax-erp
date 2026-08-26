<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════
 * أجهزة تتبع العربيات — iTrack (٢٦ أغسطس ٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * كل صف = جهاز GPS متركب في عربية، متربط بمندوب/سواق. آخر موقع
 * وحالة بيتحدثوا بأمر البولينج `promax:itrack-poll` كل دقيقة.
 *
 * ⚠️ محروسة (السيرفر مش جيت) — وكل الأعمدة الزمنية dateTime
 *    مش timestamp (عقيدة فخ ON UPDATE — ٢٣/٨).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('gps_devices')) {
            return;
        }

        Schema::create('gps_devices', function (Blueprint $table) {
            $table->id();
            $table->string('imei', 20)->unique();
            $table->string('name')->nullable();          // اسم الجهاز في المنصة
            $table->string('plate', 30)->nullable();     // لوحة العربية
            $table->string('sim', 30)->nullable();
            $table->foreignId('user_id')->nullable()->index(); // المندوب المربوط — بلا FK زي باقي الجداول
            $table->boolean('active')->default(true);

            // ═══ آخر قراءة من البولينج ═══
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->unsignedSmallInteger('speed')->nullable();   // كم/س
            $table->unsignedSmallInteger('course')->nullable();  // 0 = شمال
            $table->tinyInteger('acc')->nullable();              // 1 دايرة / 0 مطفية / -1 مفيش
            $table->tinyInteger('datastatus')->nullable();       // 2 أونلاين / 4 أوفلاين / 3 منتهي / 5 محظور / 1 عمره ما اتصل
            $table->smallInteger('battery')->nullable();         // -1 = مفيش
            $table->decimal('today_km', 8, 2)->nullable();       // عداد اليوم بالكيلومتر

            $table->dateTime('gps_time')->nullable();            // وقت آخر إحداثية
            $table->dateTime('heart_time')->nullable();          // آخر اتصال بالمنصة
            $table->dateTime('fetched_at')->nullable();          // آخر بولينج ناجح
            $table->dateTime('platform_expiry')->nullable();     // انتهاء اشتراك الجهاز

            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gps_devices');
    }
};
