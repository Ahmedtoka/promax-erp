<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مكنة البصمة ZKTeco (K40 Pro) — بروتوكول ADMS / iclock (٧/١١/٢٠٢٦).
 *
 * • `fp_devices` — المكن المسجّلة بالسيريال. مكنة سيريالها مش هنا بتترفض.
 * • `fp_logs` — البصمة الخام زي ما المكنة بعتتها، **مابتتعدلش**. `unique(device, pin, time)`
 *   = المكنة لو بعتت نفس السطر تاني (انقطاع نت / إعادة رفع) مابيتكررش.
 *   `result` = اتعمل إيه بيها في الحضور (applied · duplicate · unmapped · ...).
 * • `fp_device_users` — اليوزرز المتسجلين على المكنة (من OPERLOG) — عشان الربط بالموظف.
 * • `fp_commands` — أوامر للمكنة (إعادة رفع البصمات، رفع أسماء الموظفين) بتاخدها في getrequest.
 * • `employees.fp_pin` — رقم الموظف على المكنة.
 * • `hr_permissions` — الأذونات بالساعة (تأخير/انصراف بدري/خروج في النص) — المعتمد منها
 *   بيتشال من دقايق التأخير في المرتبات.
 *
 * ⚠️ dateTime مش timestamp — promax-system §4.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fp_devices')) {
            Schema::create('fp_devices', function (Blueprint $t) {
                $t->id();
                $t->string('serial', 40)->unique();
                $t->string('name', 80);
                $t->string('location', 120)->nullable();
                // keys = زرار الحضور/الانصراف على المكنة هو اللي بيحدد · toggle = أول بصمة حضور واللي بعدها انصراف
                $t->string('mode', 10)->default('keys');
                $t->boolean('active')->default(true);
                // البصمات اللي قبل التاريخ ده بتتسجل خام بس ومابتدخلش الحضور (رفع التاريخ القديم أول مرة)
                $t->dateTime('apply_from')->nullable();
                $t->string('attlog_stamp', 20)->nullable();
                $t->string('operlog_stamp', 20)->nullable();
                $t->dateTime('last_seen_at')->nullable();
                $t->dateTime('last_punch_at')->nullable();
                $t->string('ip', 45)->nullable();
                $t->string('firmware', 60)->nullable();
                $t->unsignedInteger('users_count')->nullable();
                $t->unsignedInteger('fp_count')->nullable();
                $t->unsignedInteger('logs_count')->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
            });
        }

        if (! Schema::hasTable('fp_logs')) {
            Schema::create('fp_logs', function (Blueprint $t) {
                $t->id();
                $t->foreignId('fp_device_id')->constrained('fp_devices')->cascadeOnDelete();
                $t->string('pin', 20);
                $t->dateTime('punched_at');
                $t->unsignedSmallInteger('status')->default(0);
                $t->unsignedSmallInteger('verify')->default(0);
                $t->string('workcode', 20)->nullable();
                $t->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
                $t->foreignId('attendance_punch_id')->nullable()->constrained('attendance_punches')->nullOnDelete();
                $t->string('punch_type', 8)->nullable();
                $t->string('result', 16)->index();
                $t->dateTime('created_at')->nullable();

                $t->unique(['fp_device_id', 'pin', 'punched_at']);
                $t->index(['punched_at']);
                $t->index(['employee_id', 'punched_at']);
            });
        }

        if (! Schema::hasTable('fp_device_users')) {
            Schema::create('fp_device_users', function (Blueprint $t) {
                $t->id();
                $t->foreignId('fp_device_id')->constrained('fp_devices')->cascadeOnDelete();
                $t->string('pin', 20);
                $t->string('name', 80)->nullable();
                $t->string('card', 30)->nullable();
                $t->unsignedSmallInteger('privilege')->default(0);
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
                $t->unique(['fp_device_id', 'pin']);
            });
        }

        if (! Schema::hasTable('fp_commands')) {
            Schema::create('fp_commands', function (Blueprint $t) {
                $t->id();
                $t->foreignId('fp_device_id')->constrained('fp_devices')->cascadeOnDelete();
                $t->string('command', 500);
                // pending · sent · done · failed
                $t->string('status', 10)->default('pending')->index();
                $t->integer('return_code')->nullable();
                $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $t->dateTime('sent_at')->nullable();
                $t->dateTime('done_at')->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
            });
        }

        if (! Schema::hasColumn('employees', 'fp_pin')) {
            Schema::table('employees', function (Blueprint $t) {
                $t->string('fp_pin', 20)->nullable()->unique()->after('code');
            });
        }

        if (! Schema::hasTable('hr_permissions')) {
            Schema::create('hr_permissions', function (Blueprint $t) {
                $t->id();
                $t->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
                $t->date('date');
                // late_in · early_out · during
                $t->string('kind', 10);
                $t->unsignedSmallInteger('minutes');
                $t->string('reason', 300)->nullable();
                // pending · approved · rejected · cancelled
                $t->string('status', 10)->default('pending')->index();
                $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
                $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
                $t->dateTime('decided_at')->nullable();
                $t->string('decision_note', 300)->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
                $t->index(['employee_id', 'date']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_permissions');
        if (Schema::hasColumn('employees', 'fp_pin')) {
            Schema::table('employees', function (Blueprint $t) {
                $t->dropUnique(['fp_pin']);
                $t->dropColumn('fp_pin');
            });
        }
        Schema::dropIfExists('fp_commands');
        Schema::dropIfExists('fp_device_users');
        Schema::dropIfExists('fp_logs');
        Schema::dropIfExists('fp_devices');
    }
};
