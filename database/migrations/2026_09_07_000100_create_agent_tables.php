<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════
 * مساعد بروماكس — جداول المحادثات والتشغيلات (٧/٩/٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * المرحلة الأولى: شات قراءة فقط جوه الـERP. كل سؤال بيتسجل run
 * كامل (الأدوات اللي اتنادت + التوكنز + الوقت + الحالة) عشان
 * المراجعة والتكلفة.
 *
 * ⚠️ الأعمدة الزمنية dateTime مش timestamp — عقيدة التايم زون
 * (فخ ON UPDATE CURRENT_TIMESTAMP على اللايف).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agent_conversations')) {
            Schema::create('agent_conversations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('title', 160)->nullable();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
            });
        }

        if (! Schema::hasTable('agent_runs')) {
            Schema::create('agent_runs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('conversation_id')
                    ->constrained('agent_conversations')->cascadeOnDelete();
                $table->text('user_message');
                $table->string('agent_name', 40);
                $table->json('tools_called')->nullable();
                $table->json('response')->nullable();
                $table->unsignedInteger('tokens_in')->default(0);
                $table->unsignedInteger('tokens_out')->default(0);
                $table->unsignedInteger('duration_ms')->default(0);
                // ok / failed / refused — string مش enum عشان إضافة حالة
                // بعدين متبقاش ALTER على اللايف
                $table->string('status', 10)->default('ok');
                $table->text('error')->nullable();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_runs');
        Schema::dropIfExists('agent_conversations');
    }
};
