<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * لقطة البضاعة على محضر التصفية (2026-08-08).
 *
 * ⚠️ **المحضر مستند بيتمضي، مش شاشة بتتحدّث.** الأرقام المالية
 * (`cash_sales` / `expected` / `received`) كانت متجمّدة من الأول
 * بالظبط للسبب ده — والبضاعة كانت هتتقرا من العهدة الحية، يعني
 * فتح المحضر بعد أسبوع بيوري أرقام النهارده مش أرقام لحظة التوقيع.
 *
 * ⚠️ **JSON مش جدول بنود.** دي **لقطة تاريخية** مالهاش علاقات ولا
 * بتتفلتر ولا بتتجمّع — جدول بنود كامل معناه FK لمنتجات ممكن تتشال
 * بعدين، وقراية أثقل، ومكسب صفر.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rep_settlements')) {
            return;
        }

        Schema::table('rep_settlements', function (Blueprint $table) {
            if (! Schema::hasColumn('rep_settlements', 'goods_json')) {
                $table->json('goods_json')->nullable()->after('note');
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('rep_settlements', 'goods_json')) {
            Schema::table('rep_settlements', fn (Blueprint $t) => $t->dropColumn('goods_json'));
        }
    }
};
