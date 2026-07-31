<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * دعم اللغتين: لغة اليوزر + أسماء إنجليزية للداتا.
 * Bilingual support: per-user locale + English names for data records.
 *
 * كل خطوة متحرّسة بـ hasColumn عشان المايجريشن يعدي حتى لو اتشغل قبل كده.
 */
return new class extends Migration
{
    /** الجداول اللي محتاجة عمود اسم إنجليزي */
    private const NAMED_TABLES = [
        'products',
        'zones',
        'channels',
        'client_groups',
        'clients',
    ];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'locale')) {
                // الافتراضي إنجليزي — القرار متسجل في سكيل promax-i18n
                $table->string('locale', 5)->default('en')->after('role');
            }
        });

        foreach (self::NAMED_TABLES as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (! Schema::hasColumn($tableName, 'name_en')) {
                    $table->string('name_en')->nullable()->after('name');
                }
            });
        }

        // وحدة المنتج ليها اسم إنجليزي كمان (بار 70جم → 70g Bar)
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'unit_en')) {
                $table->string('unit_en')->nullable()->after('unit');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'locale')) {
                $table->dropColumn('locale');
            }
        });

        foreach (self::NAMED_TABLES as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (Schema::hasColumn($tableName, 'name_en')) {
                    $table->dropColumn('name_en');
                }
            });
        }

        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'unit_en')) {
                $table->dropColumn('unit_en');
            }
        });
    }
};
