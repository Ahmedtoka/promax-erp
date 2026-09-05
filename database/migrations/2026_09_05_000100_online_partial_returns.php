<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المرتجع الجزئي للأونلاين (٥/٩) — «ريتيرن أو بارشال ريتيرن حسب
 * عدد القطع اللي هترجع»:
 *   returned_qty  على البند = عدد الباكات اللي رجعت (نفس وحدة شوبيفاي)
 *   returned_total على الأوردر = قيمة اللي رجع — بتتخصم من المستهدف
 *   تحصيله (التحصيل على البضاعة بس أصلاً)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('online_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('online_orders', 'returned_total')) {
                $table->decimal('returned_total', 14, 2)->default(0)->after('collected_total');
            }
        });

        Schema::table('online_order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('online_order_items', 'returned_qty')) {
                $table->unsignedInteger('returned_qty')->default(0)->after('qty');
            }
        });
    }

    public function down(): void
    {
        Schema::table('online_orders', function (Blueprint $table) {
            if (Schema::hasColumn('online_orders', 'returned_total')) {
                $table->dropColumn('returned_total');
            }
        });

        Schema::table('online_order_items', function (Blueprint $table) {
            if (Schema::hasColumn('online_order_items', 'returned_qty')) {
                $table->dropColumn('returned_qty');
            }
        });
    }
};
