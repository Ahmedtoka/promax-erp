<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المرجع الجغرافي الكامل (2026-08-05) — من شيت Governate.xlsx:
 *
 * - المحافظات: كود ISO 3166-2:EG + العاصمة + الإقليم (عربي/إنجليزي) + إحداثيات.
 * - المناطق: إحداثيات + نوع (حي/كمبوند/مدينة جديدة/مركز...).
 *
 * الداتا نفسها بتتثبت بأمر `php artisan promax:geo` من
 * `database/data/geo.json` — مش من هنا، عشان تتعاد بأمان في أي وقت.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('governorates', function (Blueprint $table) {
            if (! Schema::hasColumn('governorates', 'iso_code')) {
                $table->string('iso_code', 10)->nullable()->after('name_en');   // EG-C
            }
            if (! Schema::hasColumn('governorates', 'capital')) {
                $table->string('capital', 120)->nullable()->after('iso_code');
            }
            if (! Schema::hasColumn('governorates', 'capital_en')) {
                $table->string('capital_en', 120)->nullable()->after('capital');
            }
            if (! Schema::hasColumn('governorates', 'region')) {
                $table->string('region', 120)->nullable()->after('capital_en'); // إقليم القاهرة الكبرى
            }
            if (! Schema::hasColumn('governorates', 'region_en')) {
                $table->string('region_en', 120)->nullable()->after('region');
            }
            if (! Schema::hasColumn('governorates', 'lat')) {
                $table->decimal('lat', 10, 6)->nullable()->after('region_en');
            }
            if (! Schema::hasColumn('governorates', 'lng')) {
                $table->decimal('lng', 10, 6)->nullable()->after('lat');
            }
        });

        Schema::table('zones', function (Blueprint $table) {
            if (! Schema::hasColumn('zones', 'type')) {
                $table->string('type', 40)->nullable()->after('governorate');   // حي/كمبوند/مدينة جديدة
            }
            if (! Schema::hasColumn('zones', 'lat')) {
                $table->decimal('lat', 10, 6)->nullable()->after('type');
            }
            if (! Schema::hasColumn('zones', 'lng')) {
                $table->decimal('lng', 10, 6)->nullable()->after('lat');
            }
        });
    }

    public function down(): void
    {
        Schema::table('governorates', function (Blueprint $table) {
            $table->dropColumn(['iso_code', 'capital', 'capital_en', 'region', 'region_en', 'lat', 'lng']);
        });
        Schema::table('zones', function (Blueprint $table) {
            $table->dropColumn(['type', 'lat', 'lng']);
        });
    }
};
