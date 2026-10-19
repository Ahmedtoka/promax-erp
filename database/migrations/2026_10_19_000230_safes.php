<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سجل الخزن والبنوك (خطة القفل ١٩/١٠، التصميم §٣.٢) — كل خزنة أو حساب
 * بنك صف لوحده بفرعه ومركز تكلفته ومسؤوله وحسابه في الشجرة. مصر بس
 * والجنيه بس دلوقتي: عمود العملة موجود وثابت على EGP.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('safes')) {
            return;
        }

        Schema::create('safes', function (Blueprint $t) {
            $t->id();
            $t->string('code', 20)->unique();
            $t->string('name');
            $t->string('name_en')->nullable();
            $t->string('kind', 10);   // cash|bank
            $t->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $t->foreignId('cost_center_id')->nullable()->constrained('cost_centers')->nullOnDelete();
            $t->char('currency', 3)->default('EGP');
            $t->foreignId('custodian_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('gl_account_id')->nullable()->constrained('gl_accounts')->nullOnDelete();
            $t->string('bank_name')->nullable();
            $t->string('account_no', 60)->nullable();
            $t->boolean('active')->default(true);
            // ⚠️ dateTime مش timestamp — promax-system §4
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('safes');
    }
};
