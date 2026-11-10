<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سعر الوحدة في سطور المرتجع بـ٤ أرقام عشرية — زي سطور الفاتورة وأمر التوريد
 * (2026_11_10_000200). من غيره المرتجع بيخزّن 82.89 بدل 82.8947، والإجمالي
 * (المحسوب من السعر الكامل) يبان مش مساوي الكمية × السعر المعروض.
 * توسيع بس — مفيش رقم موجود بيتغيّر.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('return_items')) {
            return;
        }
        Schema::table('return_items', function (Blueprint $t) {
            foreach (['price', 'list_price'] as $c) {
                if (Schema::hasColumn('return_items', $c)) {
                    $t->decimal($c, 14, 4)->default(0)->change();
                }
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('return_items')) {
            return;
        }
        Schema::table('return_items', function (Blueprint $t) {
            foreach (['price', 'list_price'] as $c) {
                if (Schema::hasColumn('return_items', $c)) {
                    $t->decimal($c, 10, 2)->default(0)->change();
                }
            }
        });
    }
};
