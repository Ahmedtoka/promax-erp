<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سبب إلغاء التسليم (طلب المالك ١١ أغسطس ٢٠٢٦ مساءً):
 * المندوب وصل وماعرفش يسلّم — بيلغي بسبب إجباري والأمر بيرجع
 * «مستني». السبب بيبان في الداش بورد وبيتمسح مع أول تسليم ناجح.
 *
 * ⚠️ محروسة — السيرفر اللايف بيترفع بالإيد.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('purchase_orders', 'abort_reason')) {
            Schema::table('purchase_orders', function (Blueprint $table) {
                $table->string('abort_reason', 190)->nullable()->after('approval_note');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('purchase_orders', 'abort_reason')) {
            Schema::table('purchase_orders', function (Blueprint $table) {
                $table->dropColumn('abort_reason');
            });
        }
    }
};
