<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ليدات الموبايل (بايبلاين ٢٦/٨ — مرحلة ٣):
 *
 * • leads.confirmed_at/confirmed_by — لحظة «تأكيد البيانات» من
 *   الميدان = **النقطة الأولى** في حصاد الشهر.
 * • client_requests.lead_id — طلب فتح الأكاونت الجاي من ليد:
 *   الاعتماد بيقفل الليد «كسبناه» أوتوماتيك = **النقطة التانية**.
 *
 * ⚠️ محروسة + dateTime مش timestamp (عقيدة ٢٣/٨).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            if (! Schema::hasColumn('leads', 'confirmed_at')) {
                $table->dateTime('confirmed_at')->nullable();
            }

            if (! Schema::hasColumn('leads', 'confirmed_by')) {
                $table->foreignId('confirmed_by')->nullable();
            }
        });

        Schema::table('client_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('client_requests', 'lead_id')) {
                $table->foreignId('lead_id')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            foreach (['confirmed_at', 'confirmed_by'] as $col) {
                if (Schema::hasColumn('leads', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('client_requests', function (Blueprint $table) {
            if (Schema::hasColumn('client_requests', 'lead_id')) {
                $table->dropColumn('lead_id');
            }
        });
    }
};
