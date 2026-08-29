<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * لغة الويب مستقلة عن لغة الأبلكيشن (٢٧/٨/٢٠٢٦).
 *
 * `users.locale` بقى ملك الأبلكيشن لوحده (بيتكتب من POST /api/locale)،
 * و`users.web_locale` ملك الـERP لوحده (بيتكتب من زرار التبديل).
 * السبب: نفس الأكاونت شغال ويب عربي وأبلكيشن إنجليزي — وكان أي
 * سبمت في الويب بيقلب اللغة لأن locale واحدة كانت بتتخانق عليها
 * الاتنين.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'web_locale')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('web_locale', 5)->nullable()->after('locale');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'web_locale')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('web_locale');
            });
        }
    }
};
