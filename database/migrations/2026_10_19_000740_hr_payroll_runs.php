<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مسير المرتبات الشهري (خطة القفل ١٩/١٠، Lane 7 — المهمة ٦):
 * مسودة → اعتماد الأدمن → صرف من خزنة/بنك → قسايم.
 *
 * ⚠️ كل أعمدة الوقت `dateTime()` مش `timestamp()` (فخ ON UPDATE على اللايف).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payroll_runs')) {
            Schema::create('payroll_runs', function (Blueprint $t) {
                $t->id();
                $t->char('period', 7)->unique();   // YYYY-MM
                $t->date('date');                  // آخر يوم في الشهر — تاريخ القيد
                $t->string('status', 10)->default('draft')->index();   // draft|approved|paid
                $t->decimal('gross', 14, 2)->default(0);
                $t->decimal('net', 14, 2)->default(0);
                $t->decimal('employer_cost', 14, 2)->default(0);
                $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $t->dateTime('approved_at')->nullable();
                $t->dateTime('paid_at')->nullable();
                $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
            });
        }

        if (! Schema::hasTable('payroll_payments')) {
            Schema::create('payroll_payments', function (Blueprint $t) {
                $t->id();
                $t->foreignId('payroll_run_id')->constrained('payroll_runs')->cascadeOnDelete();
                $t->foreignId('safe_id')->constrained('safes');
                $t->string('kind', 10);   // cash|bank
                $t->date('date');
                $t->decimal('amount', 14, 2);
                $t->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
                $t->unique(['payroll_run_id', 'kind']);
            });
        }

        if (! Schema::hasTable('payslips')) {
            Schema::create('payslips', function (Blueprint $t) {
                $t->id();
                $t->foreignId('payroll_run_id')->constrained('payroll_runs')->cascadeOnDelete();
                $t->foreignId('employee_id')->constrained('employees');
                $t->foreignId('cost_center_id')->nullable()->constrained('cost_centers')->nullOnDelete();
                $t->string('pay_method', 10)->default('cash');
                $t->string('borne_by', 10)->default('employee');
                foreach (['basic', 'gross', 'insurance_wage', 'insurance_employee', 'insurance_employer',
                    'tax', 'advances', 'deductions', 'net'] as $col) {
                    $t->decimal($col, 14, 2)->default(0);
                }
                $t->foreignId('payroll_payment_id')->nullable()->constrained('payroll_payments')->nullOnDelete();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
                $t->unique(['payroll_run_id', 'employee_id']);
            });
        }

        if (! Schema::hasTable('payslip_lines')) {
            Schema::create('payslip_lines', function (Blueprint $t) {
                $t->id();
                $t->foreignId('payslip_id')->constrained('payslips')->cascadeOnDelete();
                $t->string('code', 20);
                $t->string('kind', 10);   // earning|deduction|employer
                $t->decimal('qty', 10, 2)->nullable();
                $t->decimal('amount', 14, 2)->default(0);
                $t->boolean('manual')->default(false);
                $t->string('note', 300)->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
                $t->index(['payslip_id', 'code']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payslip_lines');
        Schema::dropIfExists('payslips');
        Schema::dropIfExists('payroll_payments');
        Schema::dropIfExists('payroll_runs');
    }
};
