<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مفتاح ثابت لصف المورد اللي المعالج بيديره (الخط ٣، ١٩/١٠/٢٠٢٦ — D6).
 *
 * الرصيد الافتتاحي للمورد بالاسم = صف `opening` واحد بمفتاح
 * `golive-opening:supplier:{id}`، والتعديل/المسح بيلاقيه بيه من غير ما يلمس
 * أي افتتاحي تاني اتسجل يدوي. اليونيك بيمنع صفين لنفس المورد.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('supplier_transactions') || Schema::hasColumn('supplier_transactions', 'idem_key')) {
            return;
        }

        Schema::table('supplier_transactions', function (Blueprint $t) {
            $t->string('idem_key', 64)->nullable()->after('source_id');
            $t->unique('idem_key', 'supplier_tx_idem_key_unique');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('supplier_transactions', 'idem_key')) {
            Schema::table('supplier_transactions', function (Blueprint $t) {
                $t->dropUnique('supplier_tx_idem_key_unique');
                $t->dropColumn('idem_key');
            });
        }
    }
};
