<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تسكين الميدان للتشانل مانجر (قرار المالك 2026-08-05): المندوب
 * والسواق والبروموتر بيتسكّنوا لمدير — والمدير في شاشاته بيشوف
 * فريقه بس، زي ما بيشوف عملاءه بس. التسكين من شاشة «عملاء المديرين».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'manager_id')) {
                $table->foreignId('manager_id')->nullable()->after('branch_id')
                    ->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'manager_id')) {
                $table->dropConstrainedForeignId('manager_id');
            }
        });
    }
};
