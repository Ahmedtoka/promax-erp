<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الحضور لكل موظف (Lane 7 — المهمة ٢).
 *
 * • اليوم والبانش بيتربطوا بملف الموظف (`employee_id`) — العامل اللي
 *   مالوش لوجين بقى ليه يوم حضور (`user_id` null) المشرف بيسجّله.
 * • مصدر البانش (`source`: app · dashboard · supervisor) + الجهاز والـIP
 *   ومين اللي سجّل (`recorded_by`). الصفوف القديمة كلها `app`.
 *
 * ⚠️ `user_id` بيبقى nullable بـ`change()` بعد ما نقرا nullability الحالية
 * — المايجريشن بيتعاد على اللايف من غير ما يلمس عمود اتغيّر قبل كده.
 * الـFK القديم على `users` فاضل زي ما هو.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->nullableUser('attendance_days');
        $this->nullableUser('attendance_punches');

        if (! Schema::hasColumn('attendance_days', 'employee_id')) {
            Schema::table('attendance_days', function (Blueprint $t) {
                $t->foreignId('employee_id')->nullable()->after('user_id')
                    ->constrained('employees')->cascadeOnDelete();
                // يوم واحد لكل موظف — نفس قاعدة `(user_id, date)`
                $t->unique(['employee_id', 'date']);
            });
        }

        Schema::table('attendance_punches', function (Blueprint $t) {
            if (! Schema::hasColumn('attendance_punches', 'employee_id')) {
                $t->foreignId('employee_id')->nullable()->after('user_id')
                    ->constrained('employees')->cascadeOnDelete();
            }
            if (! Schema::hasColumn('attendance_punches', 'source')) {
                $t->string('source', 12)->default('app')->index();
            }
            if (! Schema::hasColumn('attendance_punches', 'device')) {
                $t->string('device', 190)->nullable();
            }
            if (! Schema::hasColumn('attendance_punches', 'ip')) {
                $t->string('ip', 45)->nullable();
            }
            if (! Schema::hasColumn('attendance_punches', 'recorded_by')) {
                $t->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            }
        });
    }

    private function nullableUser(string $table): void
    {
        $col = collect(Schema::getColumns($table))->firstWhere('name', 'user_id');

        if ($col !== null && ! $col['nullable']) {
            Schema::table($table, function (Blueprint $t) {
                $t->unsignedBigInteger('user_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        Schema::table('attendance_punches', function (Blueprint $t) {
            foreach (['employee_id', 'recorded_by'] as $fk) {
                if (Schema::hasColumn('attendance_punches', $fk)) {
                    $t->dropConstrainedForeignId($fk);
                }
            }
            foreach (['source', 'device', 'ip'] as $c) {
                if (Schema::hasColumn('attendance_punches', $c)) {
                    $t->dropColumn($c);
                }
            }
        });

        if (Schema::hasColumn('attendance_days', 'employee_id')) {
            Schema::table('attendance_days', function (Blueprint $t) {
                // ⚠️ الـFK الأول — الـunique هو الإندكس اللي الـFK قاعد عليه
                $t->dropForeign(['employee_id']);
                $t->dropUnique(['employee_id', 'date']);
                $t->dropColumn('employee_id');
            });
        }
    }
};
