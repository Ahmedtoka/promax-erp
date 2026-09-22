<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مسودة شجرة حسابات العميل (٢٢ سبتمبر ٢٠٢٦).
 *
 * الملف المستلم من الحسابات بينزل هنا **زي ما هو** — بالتكرار وبالحسابات اللي
 * من غير كود — في جدول لوحده بعيد عن `gl_accounts` وقواعد الترحيل. المالك بيعدّل
 * الأسماء والأكواد والترتيب من شاشتها، ولما يعتمدها بتتدرس وتتربط. إضافة بس.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('coa_draft_accounts')) {
            return;
        }

        Schema::create('coa_draft_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('parent_id')->nullable()->index();
            $table->unsignedInteger('sort')->default(0);
            $table->string('code', 40)->nullable();          // ممكن يتكرر أو يبقى فاضي — زي الملف
            $table->string('name', 190);
            $table->string('name_ar', 190)->nullable();
            $table->string('qb_type', 60)->nullable();       // نوع الحساب زي ما جه في الملف
            $table->decimal('balance', 16, 2)->nullable();   // رصيد الملف — للعلم بس، مش قيد
            $table->string('description', 500)->nullable();
            $table->string('tax_line', 120)->nullable();
            $table->string('feed', 30)->nullable();          // الربط الوحيد المسموح: sales_ka | sales_van
            $table->string('note', 500)->nullable();
            $table->unsignedInteger('source_row')->nullable();
            $table->text('source_path')->nullable();         // المسار الأصلي في الملف — مرجع لا يتعدّل
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // مسودة بيتعب فيها المالك بإيده — مابتتمسحش برجوع
    }
};
