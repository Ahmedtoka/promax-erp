<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تجاوز طريقة التعامل على العميل  ·  ١٧ أغسطس ٢٠٢٦
 *
 * طلب المالك في شاشة الإعداد: «اختار الديفيجن + اختار كاش فان ولا
 * ديلفري ولا أونلاين» — يعني الطريقة ممكن تخالف افتراضي القسم
 * (سلسلة كونفينيانس بتتعامل ديلفري مثلاً).
 *
 * ⚠️ `fulfillment_mode` **تجاوز اختياري**: الفعلي في
 * `Client::fulfillment()` = التجاوز لو موجود وإلا افتراضي الديفيجن.
 * الفاضي مش «مفيش طريقة» — «امشي على افتراضي القسم».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (! Schema::hasColumn('clients', 'fulfillment_mode')) {
                $table->string('fulfillment_mode', 20)->nullable()
                    ->after('division');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (Schema::hasColumn('clients', 'fulfillment_mode')) {
                $table->dropColumn('fulfillment_mode');
            }
        });
    }
};
