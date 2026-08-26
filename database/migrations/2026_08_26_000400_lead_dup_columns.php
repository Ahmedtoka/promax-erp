<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * فحص الشبيهات (بايبلاين ٢٦/٨): أعمدة اقتراح «الليد ده شبه عميل
 * موجود» — الفحص بيملاها والمالك بيقرر يدوي صح/غلط واحد واحد.
 *
 * ⚠️ محروسة — السيرفر مش جيت.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            if (! Schema::hasColumn('leads', 'dup_client_id')) {
                $table->foreignId('dup_client_id')->nullable()->index();
            }

            if (! Schema::hasColumn('leads', 'dup_reason')) {
                // name | phone | address — سبب الاشتباه للعرض
                $table->string('dup_reason', 20)->nullable();
            }

            if (! Schema::hasColumn('leads', 'dup_dismissed')) {
                // المالك قال «غلط، مش هو» — مايتسألش عن نفس الليد تاني
                $table->boolean('dup_dismissed')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            foreach (['dup_client_id', 'dup_reason', 'dup_dismissed'] as $col) {
                if (Schema::hasColumn('leads', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
