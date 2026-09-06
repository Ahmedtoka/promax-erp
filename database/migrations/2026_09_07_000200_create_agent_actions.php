<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════
 * مساعد بروماكس — الأكشنات بموافقة (المرحلة التانية ٧/٩/٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * الإيجنت بيجهّز العملية (اقتراح) والمستخدم بيأكّدها بزرار — التنفيذ
 * الفعلي بيحصل وقت التأكيد بس، بنفس مسار كود الشاشة الأصلية.
 *
 * ⚠️ الأعمدة الزمنية dateTime مش timestamp — عقيدة التايم زون.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agent_actions')) {
            Schema::create('agent_actions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('type', 30);              // collection ...
                $table->json('payload');                 // بيانات العملية المقترحة
                // pending / confirmed / cancelled / failed
                $table->string('status', 12)->default('pending');
                $table->json('result')->nullable();      // نتيجة التنفيذ
                $table->text('error')->nullable();
                $table->dateTime('confirmed_at')->nullable();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_actions');
    }
};
