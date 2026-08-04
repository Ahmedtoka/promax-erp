<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * وحدات القياس — قرار المالك 2026-08-04:
 *
 * المخزون كله بيتخزن **بالقطعة** زي ما هو — مفيش أي تغيير في
 * الباتشات ولا العهدة ولا الفواتير. العلبة والكرتونة طبقة تحويل
 * عند الإدخال بس (استلام / تسليم عهدة).
 *
 *   - `units_per_case` (موجود من الأول): قطع **الكرتونة**.
 *   - `box_units` (الجديد): قطع **العلبة** — للأصناف اللي ليها
 *     تدريج وسطاني (بروتين بار: علبة 12 قطعة، الكرتونة 6 علب).
 *     الأصناف اللي مالهاش علبة (سبريدز، بروكب) بتسيبها NULL.
 *
 * الأرقام المعتمدة (بتتزرع بـ `php artisan promax:units`):
 *   سبريدز: كرتونة = 12 قطعة (مفيش علبة)
 *   بروماكس بار: علبة = 12، كرتونة = 6 علب = 72
 *   PMX بار: علبة = 12، كرتونة = 72
 *   بروكب: كرتونة = 24 قطعة (مفيش علبة)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'box_units')) {
            Schema::table('products', function (Blueprint $table) {
                $table->unsignedInteger('box_units')->nullable()->after('units_per_case');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'box_units')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('box_units');
            });
        }
    }
};
