<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مركز نشاط المستخدمين (٢٢ سبتمبر ٢٠٢٦).
 *
 * `route` اسم الراوت لكل حركة (منه بنعرف القسم والشاشة حتى لحركات
 * الإنشاء/التعديل اللي كانت بتتسجل بالـURL بس)، و`status` كود الرد
 * (الحفظة اللي اترفضت بتبان مرفوضة). إضافة بس — ومحمية عشان السيرفر
 * اللايف بيتحدّث برفع الملفات وممكن تتشغل مرتين.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('activity_logs')) {
            return;
        }

        Schema::table('activity_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('activity_logs', 'route')) {
                $table->string('route', 120)->nullable()->after('method');
            }
            if (! Schema::hasColumn('activity_logs', 'status')) {
                $table->unsignedSmallInteger('status')->nullable()->after('route');
            }
        });

        // الفلترة بالفترة لوحدها (لوحة «مين شغال دلوقتي») كانت بتمسح الجدول كله
        $indexes = collect(Schema::getIndexes('activity_logs'))->pluck('name');

        if (! $indexes->contains('activity_logs_created_at_index')) {
            Schema::table('activity_logs', fn (Blueprint $table) => $table->index('created_at'));
        }
    }

    public function down(): void
    {
        // إضافة بس — مفيش رجوع بيمسح بيانات السجل
    }
};
