<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جدولة العملاء المحتملين (سكشن المحتملين ٢٦/٨):
 *
 * المدير بيجدول أسبوع كل مندوب: «بكره روح ده وده، وبعده ده» —
 * صف لكل (ليد × يوم بتاريخه). ⚠️ **تواريخ حقيقية مش نمط أسبوعي**
 * زي خطط سير العملاء: الليد بيتزار مرة أو اتنين ويتحول/يقفل —
 * مش زيارة متكررة كل أسبوع.
 *
 * إثبات الزيارة أوتوماتيك: تأكيد البيانات (confirmed_at) في نفس
 * اليوم — مفيش أي خطوة زيادة على المندوب.
 *
 * ⚠️ محروسة + dateTime (عقيدة ٢٣/٨).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('lead_plans')) {
            return;
        }

        Schema::create('lead_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->index();     // المندوب
            $table->foreignId('lead_id')->index();
            $table->date('plan_date');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->foreignId('created_by')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            // نفس الليد مايتجدولش مرتين في نفس اليوم — أسبوع تاني عادي
            $table->unique(['lead_id', 'plan_date']);
            $table->index(['user_id', 'plan_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_plans');
    }
};
