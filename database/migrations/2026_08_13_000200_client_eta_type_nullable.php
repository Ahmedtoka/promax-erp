<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════
 * `clients.eta_type` يقبل الفاضي (١٣ أغسطس ٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **العمود اتعمل `NOT NULL default 'B'`** في مايجريشن
 * `000016_tax_and_settings` — يعني «نوع المستلم عند المصلحة» عمره
 * ما يقدر يكون **غير محدد**. والسيستم كله بيقول العكس:
 *
 *   • فورم العميل فيه اختيار فاضي («— اختر نوع المستلم —»)
 *   • `ErpController::clientFields` بتشيل المفتاح لما يكون فاضي
 *     وبتعتمد على ديفولت الداتابيز، والتعليق بتاعها بيقول بالحرف:
 *     «العميل اللي مش خاضع للضريبة مالوش لازمة قيمة في الخانة دي»
 *   • والقرار المكتوب: ختم كل عميل بـ«شخص اعتباري» بيطلّع تصدير
 *     المصلحة غلط
 *
 * وأي مسار بيكتب `null` صراحةً (سيدر، استيراد، tinker، إنشاء عميل
 * من الأبلكيشن) كان بيقع على:
 *
 *     SQLSTATE[23000] 1048: Column 'eta_type' cannot be null
 *
 * ⚠️ **الديفولت `B` فاضل زي ما هو عن قصد.** الصفوف الموجودة
 * مابتتلمسش، والعميل الجديد اللي الفورم مابعتش له قيمة بياخد نفس
 * السلوك الحالي بالظبط — التغيير الوحيد إن `null` بقت **مسموحة**
 * بدل ما تكون خطأ SQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('clients', 'eta_type')) {
            return;
        }

        Schema::table('clients', function (Blueprint $table) {
            $table->string('eta_type', 1)->nullable()->default('B')->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('clients', 'eta_type')) {
            return;
        }

        // ⚠️ الرجوع بيحتاج يملا الفاضي الأول — عمود `NOT NULL` مايتعملش
        // على جدول فيه `null`، والرول باك على لايف فيه داتا بيقع.
        \Illuminate\Support\Facades\DB::table('clients')
            ->whereNull('eta_type')->update(['eta_type' => 'B']);

        Schema::table('clients', function (Blueprint $table) {
            $table->string('eta_type', 1)->default('B')->change();
        });
    }
};
