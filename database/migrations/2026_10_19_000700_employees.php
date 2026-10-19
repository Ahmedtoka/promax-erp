<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ملف الموظف (خطة القفل ١٩/١٠، Lane 7 — المهمة ١). الموظف كيان مستقل عن
 * اليوزر: عامل المصنع ممكن مايبقاش ليه لوجين خالص (`user_id` null)، والمرتب
 * والتأمين والضريبة ومركز التكلفة بتاعته هنا مش على `users`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employees')) {
            return;
        }

        Schema::create('employees', function (Blueprint $t) {
            $t->id();
            $t->string('code', 20)->unique();
            $t->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $t->string('name', 120);
            $t->string('name_en', 120)->nullable();
            $t->foreignId('cost_center_id')->nullable()->constrained('cost_centers')->nullOnDelete();
            $t->string('department', 80)->nullable();
            $t->string('job_title', 80)->nullable();
            $t->foreignId('manager_id')->nullable()->constrained('employees')->nullOnDelete();
            $t->date('hire_date')->nullable();
            $t->date('end_date')->nullable();
            $t->string('contract_path')->nullable();
            $t->string('national_id', 14)->nullable();
            $t->string('insurance_no', 20)->nullable();
            $t->string('pay_method', 10)->default('cash');   // cash|bank
            $t->string('bank_account', 40)->nullable();
            $t->decimal('basic_salary', 14, 2)->default(0);
            $t->decimal('allowances', 14, 2)->default(0);
            $t->decimal('insurable_wage', 14, 2)->nullable();
            $t->boolean('insured')->default(true);
            $t->boolean('taxable')->default(true);
            $t->string('employee_share_borne_by', 10)->default('employee');   // employee|company
            $t->boolean('attendance_required')->default(true);
            $t->boolean('active')->default(true);
            // ⚠️ dateTime مش timestamp — promax-system §4
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
