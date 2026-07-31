<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مرفقات الأوراق الرسمية (صورة أو PDF) على طلب العميل الجديد وعلى العميل نفسه
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_requests', function (Blueprint $table) {
            $table->string('docs_path')->nullable()->after('photo_path');
            $table->string('docs_type', 10)->nullable()->after('docs_path'); // image / pdf
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->string('docs_path')->nullable()->after('photo_path');
            $table->string('docs_type', 10)->nullable()->after('docs_path');
        });
    }

    public function down(): void
    {
        Schema::table('client_requests', function (Blueprint $table) {
            $table->dropColumn(['docs_path', 'docs_type']);
        });
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['docs_path', 'docs_type']);
        });
    }
};
