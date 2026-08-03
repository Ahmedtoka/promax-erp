<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الهدية ممكن تتسجل على **طلب عميل جديد** — لسه تحت الموافقة أو
 * اتوافق عليه ولسه ماتحوّلش. المندوب بيكسب العميل بالعينة قبل ما
 * يبقى عميل رسمي، والتوزيعة لازم تتسجل باسمه مش «بدون عميل».
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('gift_handouts', 'client_request_id')) {
            Schema::table('gift_handouts', function (Blueprint $table) {
                $table->foreignId('client_request_id')->nullable()
                    ->after('client_id')->constrained('client_requests')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('gift_handouts', 'client_request_id')) {
            Schema::table('gift_handouts', function (Blueprint $table) {
                $table->dropConstrainedForeignId('client_request_id');
            });
        }
    }
};
