<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * السلاسل والمجموعات (Circle K، جورميه، بونجور...) + إحداثيات العملاء
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_groups', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->foreignId('channel_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sub_channel', 20)->nullable();
            $table->decimal('discount', 5, 4)->default(0); // خصم السلسلة كلها
            $table->boolean('uses_group_discount')->default(false);
            $table->string('contact_name')->nullable();
            $table->string('contact_phone', 30)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->foreignId('group_id')->nullable()->after('channel_id')
                ->constrained('client_groups')->nullOnDelete();
            // موقع الفرع على الخريطة
            $table->decimal('lat', 10, 7)->nullable()->after('address');
            $table->decimal('lng', 10, 7)->nullable()->after('lat');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('group_id');
            $table->dropColumn(['lat', 'lng']);
        });
        Schema::dropIfExists('client_groups');
    }
};
