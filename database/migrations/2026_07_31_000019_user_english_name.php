<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * اسم الموظف بالإنجليزي.
 *
 * ⚠️ مايجريشن `000008` زوّد `name_en` لجداول الأسماء المزدوجة كلها
 * **ماعدا `users`** — زوّد `locale` بس. فأسماء الفريق كانت بتظهر
 * عربي جوه الواجهة الإنجليزية في كل شاشة فيها «المندوب»، وده كسر
 * قاعدة نقاء اللغة اللي السيستم كله قايم عليها.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'name_en')) {
                $table->string('name_en')->nullable()->after('name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'name_en')) {
                $table->dropColumn('name_en');
            }
        });
    }
};
