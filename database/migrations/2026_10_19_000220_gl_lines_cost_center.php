<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مركز التكلفة بُعد على سطر القيد (التصميم §٤.١) — `Rules::linesFor()`
 * بيحطه على السطر و`Ledger` بيكتبه. فاضي = مالوش مركز.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('gl_lines') && Schema::hasTable('cost_centers')
            && ! Schema::hasColumn('gl_lines', 'cost_center_id')) {
            Schema::table('gl_lines', function (Blueprint $t) {
                $t->foreignId('cost_center_id')->nullable()->after('account_id')
                    ->constrained('cost_centers')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('gl_lines', 'cost_center_id')) {
            Schema::table('gl_lines', fn (Blueprint $t) => $t->dropConstrainedForeignId('cost_center_id'));
        }
    }
};
