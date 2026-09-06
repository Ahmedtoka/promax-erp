<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ═══ محفظة المدير في المحتملين (٦/٩/٢٠٢٦ — طلب المالك) ═══
 *
 * «أنا لو أدمن بنزّل الليد على التشانل مانجر، ولو تشانل بنزّل على
 * مناديبي» — عمود `manager_id` بيسجل الليد اتوزع لأنهي مدير:
 * الأدمن بيوزع للمدير (والليد بيتشال من أي مندوب)، والمدير بيوزع
 * من محفظته لمناديبه (`assigned_to` بيتظبط و`manager_id` بيتزامن
 * مع مدير المندوب).
 *
 * ⚠️ محروسة — السيرفر مش ريبو جيت والمالك بيرفع بإيده.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            if (! Schema::hasColumn('leads', 'manager_id')) {
                $table->foreignId('manager_id')->nullable()->after('assigned_to')
                    ->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            if (Schema::hasColumn('leads', 'manager_id')) {
                $table->dropConstrainedForeignId('manager_id');
            }
        });
    }
};
