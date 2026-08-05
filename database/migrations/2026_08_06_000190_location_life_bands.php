<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * بلوكات FEFO (2026-08-06) — نطاق عمر لكل رف/بلوك:
 * month / quarter / half / year — و null = رف حر بيقبل أي حاجة.
 * التقسيمة والحدود في `App\Support\LifeBands`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            if (! Schema::hasColumn('locations', 'life_band')) {
                $table->string('life_band', 10)->nullable()->after('is_pick_face')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn('life_band');
        });
    }
};
