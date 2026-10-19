<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * السلف والجزاءات (خطة القفل ١٩/١٠، Lane 7 — المهمة ٥). السلفة بتتقسّط
 * وبتتخصم من المرتب (المهمة ٧)، والجزاء بيتخصم مرة واحدة ويتربط بقسيمة المرتب.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_advances')) {
            Schema::create('employee_advances', function (Blueprint $t) {
                $t->id();
                $t->string('number', 20)->unique();
                $t->foreignId('employee_id')->constrained('employees');
                $t->date('date');
                $t->decimal('amount', 14, 2);
                $t->unsignedSmallInteger('installments')->default(1);
                $t->decimal('installment_amount', 14, 2);
                $t->char('start_period', 7);   // YYYY-MM
                $t->decimal('recovered', 14, 2)->default(0);
                $t->foreignId('safe_id')->nullable()->constrained('safes')->nullOnDelete();
                $t->string('status', 10)->default('open');   // open|closed|void
                $t->string('note', 300)->nullable();
                $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
                $t->index(['employee_id', 'status']);
            });
        }

        if (! Schema::hasTable('hr_penalties')) {
            Schema::create('hr_penalties', function (Blueprint $t) {
                $t->id();
                $t->foreignId('employee_id')->constrained('employees');
                $t->date('date');
                $t->decimal('amount', 14, 2);
                $t->string('reason', 200);
                $t->unsignedBigInteger('payslip_id')->nullable();   // بيتملى لما يتخصم (المهمة ٦)
                $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
                $t->index(['employee_id', 'payslip_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_penalties');
        Schema::dropIfExists('employee_advances');
    }
};
