<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ═══ باندل شوبيفاي — فاريانت واحد = كذا منتج مختلف (٢٦/٩/٢٠٢٦) ═══
 *
 * طلب المالك: «عندي باندل على شوبيفاي، عاوز أربطه بأكتر من منتج،
 * ولما يتجهز يخصم من كل صنف حسب الربط».
 *
 * `bundle` = [{"product_id": 12, "units": 2}, ...] — القطع من كل منتج
 * في الباندل الواحد. فاضي = ربط عادي بمنتج واحد (`product_id` + `units`).
 *
 * ⚠️ على البند نسخة مستقلة عن الربط (سنابشوت): تعديل الباندل بعدين
 * بيلمس الأوردرات المفتوحة بس، والمؤكد بيفضل على اللي اتجهز بيه.
 *
 * ⚠️ محروسة — اللايف مش ريبو جيت.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('shopify_product_links') && ! Schema::hasColumn('shopify_product_links', 'bundle')) {
            Schema::table('shopify_product_links', function (Blueprint $table) {
                $table->json('bundle')->nullable()->after('units');
            });
        }

        if (Schema::hasTable('online_order_items') && ! Schema::hasColumn('online_order_items', 'bundle')) {
            Schema::table('online_order_items', function (Blueprint $table) {
                $table->json('bundle')->nullable()->after('units_per');
            });
        }
    }

    public function down(): void
    {
        foreach (['shopify_product_links', 'online_order_items'] as $t) {
            if (Schema::hasColumn($t, 'bundle')) {
                Schema::table($t, fn (Blueprint $table) => $table->dropColumn('bundle'));
            }
        }
    }
};
