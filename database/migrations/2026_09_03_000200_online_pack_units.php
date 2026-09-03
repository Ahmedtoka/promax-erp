<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ═══ باكات الأونلاين (٣/٩ مساءً) ═══
 *
 * فاريانتات شوبيفاي هي نفس المنتج بعدد قطع مختلف (1 · 3 · 6 · 12).
 * `units` على الربط = الفاريانت ده كام قطعة من منتج السيستم،
 * و`units_per` سنابشوت على بند الأوردر وقت السينك — التجهيز بيخصم
 * qty × units_per قطعة من المخزن.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopify_product_links', function (Blueprint $table) {
            if (! Schema::hasColumn('shopify_product_links', 'units')) {
                $table->unsignedInteger('units')->default(1)->after('product_id');
            }
        });

        Schema::table('online_order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('online_order_items', 'units_per')) {
                $table->unsignedInteger('units_per')->default(1)->after('qty');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shopify_product_links', function (Blueprint $table) {
            if (Schema::hasColumn('shopify_product_links', 'units')) {
                $table->dropColumn('units');
            }
        });

        Schema::table('online_order_items', function (Blueprint $table) {
            if (Schema::hasColumn('online_order_items', 'units_per')) {
                $table->dropColumn('units_per');
            }
        });
    }
};
