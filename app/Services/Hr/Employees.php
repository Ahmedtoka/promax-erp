<?php

namespace App\Services\Hr;

use App\Models\CostCenter;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ملفات الموظفين من اليوزرز الموجودين (Lane 7). آمن يتعاد: اليوزر اللي
 * ليه ملف بيتخطى، و`--dry-run` بيعدّ من غير ما يكتب.
 *
 *   php artisan promax:hr-employees-seed --dry-run
 */
class Employees
{
    /**
     * @return array{created:int,linked_days:int,skipped:int}
     */
    public function seedFromUsers(bool $dryRun = false): array
    {
        $created = 0;
        $skipped = 0;

        $taken = Employee::query()->whereNotNull('user_id')->pluck('user_id')->flip();
        $users = User::query()->where('active', true)->with('warehouse')->orderBy('id')->get();

        foreach ($users as $u) {
            if (isset($taken[$u->id])) {
                $skipped++;

                continue;
            }

            $created++;

            if ($dryRun) {
                continue;
            }

            DB::transaction(function () use ($u) {
                Employee::create([
                    'code' => $this->codeFor($u),
                    'user_id' => $u->id,
                    'name' => mb_substr((string) $u->name, 0, 120),
                    'name_en' => $u->name_en !== null ? mb_substr((string) $u->name_en, 0, 120) : null,
                    'cost_center_id' => $u->warehouse_id ? CostCenter::forWarehouse($u->warehouse)?->id : null,
                ]);
            });
        }

        return ['created' => $created, 'linked_days' => $this->backfill($dryRun), 'skipped' => $skipped];
    }

    /** كود اليوزر لو صالح ومش متاخد، وإلا EMP-xxxx */
    private function codeFor(User $u): string
    {
        $code = trim((string) $u->code);

        if ($code !== '' && mb_strlen($code) <= 20 && ! Employee::query()->where('code', $code)->exists()) {
            return $code;
        }

        return Employee::nextCode();
    }

    /**
     * ربط أيام الحضور والبانشات القديمة بملف الموظف — بس لما أعمدة
     * `employee_id` تتعمل (المهمة ٢). الصف اللي مربوط مابيتلمسش.
     */
    private function backfill(bool $dryRun): int
    {
        $linked = 0;

        foreach (['attendance_days', 'attendance_punches'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'employee_id')) {
                continue;
            }

            $q = DB::table($table)->whereNull($table.'.employee_id')
                ->join('employees', 'employees.user_id', '=', $table.'.user_id');

            if ($dryRun) {
                $n = $q->count();
            } else {
                $n = DB::table($table)->whereNull('employee_id')->whereNotNull('user_id')
                    ->whereIn('user_id', DB::table('employees')->whereNotNull('user_id')->select('user_id'))
                    ->update(['employee_id' => DB::raw('(SELECT e.id FROM employees e WHERE e.user_id = '.$table.'.user_id)')]);
            }

            if ($table === 'attendance_days') {
                $linked = $n;
            }
        }

        return $linked;
    }
}
