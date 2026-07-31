<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * القنوات الأربعة (كي أكاونت / أونلاين / كاش فان / جملة)
 * + ربط العملاء والمستخدمين بالقنوات
 */
return new class extends Migration
{
    public function up(): void
    {
        // ===== القنوات =====
        Schema::create('channels', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();   // key_account / online / cash_van / wholesale
            $table->string('name');
            $table->decimal('discount', 5, 4)->default(0); // نسبة الخصم من سعر البيع للكاستمر
            $table->string('color', 10)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // ===== ربط العميل بالقناة =====
        Schema::table('clients', function (Blueprint $table) {
            $table->foreignId('channel_id')->nullable()->after('zone_id')
                ->constrained()->nullOnDelete();
            // للكي أكاونت بس: chain (سلاسل هايبر وماركت) / convenience (كونفينيانس ومحطات)
            $table->string('sub_channel', 20)->nullable()->after('channel_id')->index();
            // العميل الأب (فرع تابع لسلسلة)
            $table->foreignId('parent_id')->nullable()->after('sub_channel')
                ->constrained('clients')->nullOnDelete();
            // لو false يبقى خصم العميل نفسه هو المعتمد مش خصم القناة
            $table->boolean('uses_channel_discount')->default(true)->after('discount');
        });

        // ===== ربط المستخدم بالقناة =====
        Schema::table('users', function (Blueprint $table) {
            // القناة اللي المندوب شغّال عليها
            $table->foreignId('channel_id')->nullable()->after('zone_id')
                ->constrained()->nullOnDelete();
        });

        // مدير ممكن يكون مسئول عن أكتر من قناة
        Schema::create('channel_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['channel_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_user');

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('channel_id');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropConstrainedForeignId('channel_id');
            $table->dropColumn(['sub_channel', 'uses_channel_discount']);
        });

        Schema::dropIfExists('channels');
    }
};
