<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * آخر مرة الفاريانت ظهر في «هات المنتجات» (٧/١٠/٢٠٢٦).
 *
 * طلب المالك: «منتجات شيلتها من شوبيفاي بترجع مع كل سينك». الجلب بقى
 * للأكتيف بس، والعمود ده بيقول مين مارجعش في آخر جلب (اتمسح/اتأرشف/درافت)
 * عشان يتعلّم ويتمسح بزرار واحد.
 *
 * ⚠️ محروسة · `dateTime` مش `timestamp` (فخ ON UPDATE).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('shopify_product_links') && ! Schema::hasColumn('shopify_product_links', 'seen_at')) {
            Schema::table('shopify_product_links', function (Blueprint $table) {
                $table->dateTime('seen_at')->nullable()->after('sku_pushed_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('shopify_product_links', 'seen_at')) {
            Schema::table('shopify_product_links', fn (Blueprint $table) => $table->dropColumn('seen_at'));
        }
    }
};
