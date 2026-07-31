<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * إضافة الرول والكود للمستخدمين + توكنات الأبلكيشن
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // admin / manager / rep / courier
            $table->string('role', 20)->default('rep')->after('email')->index();
            $table->string('code', 30)->nullable()->unique()->after('role'); // REP-014
            $table->string('phone', 30)->nullable()->after('code');
            $table->foreignId('zone_id')->nullable()->after('phone')
                ->constrained()->nullOnDelete();
            $table->boolean('active')->default(true)->after('zone_id');
        });

        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60)->default('mobile');
            $table->string('token', 80)->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_tokens');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('zone_id');
            $table->dropColumn(['role', 'code', 'phone', 'active']);
        });
    }
};
