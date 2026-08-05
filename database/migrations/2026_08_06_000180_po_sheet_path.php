<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * شيت الأمر كمرجع (2026-08-06) — الملف اللي السلسلة بعتته بيتحفظ
 * في storage/app/po-sheets ومساره على الأمر، ينزل أي وقت من
 * قايمة الأوامر أو الموافقات.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('purchase_orders', 'sheet_path')) {
                $table->string('sheet_path')->nullable()->after('source');
            }
            if (! Schema::hasColumn('purchase_orders', 'sheet_name')) {
                // اسم الملف الأصلي — عشان التنزيل يطلع بنفس الاسم
                $table->string('sheet_name', 190)->nullable()->after('sheet_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn(['sheet_path', 'sheet_name']);
        });
    }
};
