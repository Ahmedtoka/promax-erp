<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * بوابة المراجعة في تجهيز الأونلاين (٥/٩ — قرار المالك):
 * «تم التجهيز» بتخصم البضاعة والأوردر بيفضل في شاشة التجهيز عليه
 * زرار «مراجعة» — المراجع بيشوف المحتوى ويدوس تمام فتتطبع الفاتورة
 * ويتعلّم reviewed_at، وساعتها بس بينزل «جاهزة للشحن».
 *
 * ⚠️ dateTime مش timestamp — عقيدة التايم زون.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('online_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('online_orders', 'reviewed_at')) {
                $table->dateTime('reviewed_at')->nullable()->after('ready_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('online_orders', function (Blueprint $table) {
            if (Schema::hasColumn('online_orders', 'reviewed_at')) {
                $table->dropColumn('reviewed_at');
            }
        });
    }
};
