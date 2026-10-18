<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * نوع الصنف (خطة القفل ١٩/١٠): منتج تام / خامة / تعبئة.
 *
 * الخامات والتعبئة بتعيش في نفس `products` و`batches` و`warehouses`
 * (قرار التصميم §٤)، والفرق بينها وبين المنتج التام إنها **مابتتباعش**
 * — `Product::sellable()` بيستبعدها. الموجود كله بياخد `finished`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('products') && ! Schema::hasColumn('products', 'kind')) {
            Schema::table('products', function (Blueprint $table) {
                $table->string('kind', 20)->default('finished')->after('family')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'kind')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropIndex(['kind']);
                $table->dropColumn('kind');
            });
        }
    }
};
