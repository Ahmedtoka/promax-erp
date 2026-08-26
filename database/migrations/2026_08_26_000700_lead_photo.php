<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * صورة المكان من الميدان (فلو الليد المطور ٢٦/٨) — المندوب بيصور
 * المحل وقت «تأكيد البيانات»، والصورة بتتنقل للعميل لو اتفتح له
 * أكاونت. ⚠️ محروسة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            if (! Schema::hasColumn('leads', 'photo_path')) {
                $table->string('photo_path')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            if (Schema::hasColumn('leads', 'photo_path')) {
                $table->dropColumn('photo_path');
            }
        });
    }
};
