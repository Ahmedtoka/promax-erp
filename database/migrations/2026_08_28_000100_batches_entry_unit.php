<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * حفظ وحدة وكمية الإدخال على الباتش (٢٨/٨/٢٠٢٦).
 *
 * المخزون بالقطعة دايماً، والعلبة/الكرتونة مضاعِف إدخال — بس
 * الضرب كان بيحصل ويتنسي: الباتش بيتخزن ٣٢٠ قطعة ومحدش يعرف
 * إن اللي اتكتب كان «٢٠ كرتونة».
 *
 * 🔴 والمشكلة اللي كشفت ده (بلاغ المالك ٢٨/٨): مضاعِف الكرتونة كان
 * متسجّل غلط على الصنف وقت الاستلام. بعد ما اتصحح، مفيش طريقة
 * تعيد حساب الإذن — الرقم المتخزن قطع صمّاء. بحفظ الكمية والوحدة
 * الأصليتين، «إعادة الحساب بالمضاعِف الصحيح» بقت ضغطة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            if (! Schema::hasColumn('batches', 'entry_qty')) {
                $table->unsignedInteger('entry_qty')->nullable()->after('qty_received');
            }
            if (! Schema::hasColumn('batches', 'entry_unit')) {
                $table->string('entry_unit', 10)->nullable()->after('entry_qty');
            }
        });
    }

    public function down(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            foreach (['entry_qty', 'entry_unit'] as $col) {
                if (Schema::hasColumn('batches', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
