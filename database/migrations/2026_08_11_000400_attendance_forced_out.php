<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الانصراف الإداري من الداش بورد (١١ أغسطس ٢٠٢٦).
 *
 * الأدمن/المدير بيسجّل انصراف موظف لسه فاتح حضور وبيحدد ساعات
 * الشغل — البانش بيتعلّم بـ`forced_by` عشان السجل يفضل شاهد إن
 * القفلة إدارية ومين اللي عملها. الساعات نفسها بتتعتمد على اليوم
 * (`attendance_days.approved_minutes` الموجود أصلاً) — مفيش عمود
 * ساعات جديد.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ⚠️ محروس — السيرفر اللايف بيترفع بإيد المالك مش جيت
        if (! Schema::hasColumn('attendance_punches', 'forced_by')) {
            Schema::table('attendance_punches', function (Blueprint $table) {
                $table->foreignId('forced_by')->nullable()->after('auto')
                    ->constrained('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('attendance_punches', 'forced_by')) {
            Schema::table('attendance_punches', function (Blueprint $table) {
                $table->dropConstrainedForeignId('forced_by');
            });
        }
    }
};
