<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * (١٣/١١ — ملاحظات المراجعة) سعر يدوي لبند المرتجع — «الأسعار غلط ومفيش تعديل».
 *   • `return_items.manual_price`: سعر غير شامل بيكتبه المراجِع (لما مفيش فاتورة يترجع منها).
 *     `FullResync::clientReturn` بيحترمه، فإعادة التسعير بعد كده ماتدوسش عليه.
 *   • `return_items.manual_reason`: السبب (إجباري من اللوحة).
 *
 * ⚠️ إضافة أعمدة بس — مفيش داتا بتتغير.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('return_items', function (Blueprint $t) {
            if (! Schema::hasColumn('return_items', 'manual_price')) {
                $t->decimal('manual_price', 12, 4)->nullable()->after('price');
            }
            if (! Schema::hasColumn('return_items', 'manual_reason')) {
                $t->string('manual_reason', 190)->nullable()->after('manual_price');
            }
        });
    }

    public function down(): void
    {
        Schema::table('return_items', function (Blueprint $t) {
            foreach (['manual_reason', 'manual_price'] as $c) {
                if (Schema::hasColumn('return_items', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
