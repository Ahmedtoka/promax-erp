<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الإجازات (خطة القفل ١٩/١٠، Lane 7 — المهمة ٣): رصيد لكل موظف لكل سنة لكل
 * نوع، وطلبات بيعتمدها المدير المباشر. ⚠️ الاعتماد بس هو اللي بيزوّد `used`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('leave_balances')) {
            Schema::create('leave_balances', function (Blueprint $t) {
                $t->id();
                $t->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
                $t->smallInteger('year');
                $t->string('type', 10);   // annual|casual|sick|unpaid
                $t->decimal('entitled', 6, 1);
                $t->decimal('used', 6, 1)->default(0);
                $t->string('note', 300)->nullable();
                // ⚠️ dateTime مش timestamp — promax-system §4
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
                $t->unique(['employee_id', 'year', 'type']);
            });
        }

        if (! Schema::hasTable('leave_requests')) {
            Schema::create('leave_requests', function (Blueprint $t) {
                $t->id();
                $t->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
                $t->string('type', 10);
                $t->date('from');
                $t->date('to');
                $t->decimal('days', 6, 1);
                $t->string('note', 300)->nullable();
                $t->string('status', 10)->default('pending')->index();   // pending|approved|rejected|cancelled
                $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
                $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
                $t->dateTime('decided_at')->nullable();
                $t->string('decision_note', 300)->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
                $t->index(['employee_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('leave_balances');
    }
};
