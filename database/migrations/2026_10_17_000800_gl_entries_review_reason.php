<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سبب علامة «محتاج مراجعة» بيتسجّل لحظة الترحيل (`rule` = حساب ناقص/معلّق،
 * `closed_period` = اتقيّد في شهر مقفول) — البادج كان بيستنتجه من حالة
 * الفترة **دلوقتي**، فقيد معلّق اتقفل شهره بعدين كان بيقول «فترة مقفولة».
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('gl_entries') && ! Schema::hasColumn('gl_entries', 'review_reason')) {
            Schema::table('gl_entries', function (Blueprint $table) {
                $table->string('review_reason', 40)->nullable()->after('needs_review');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('gl_entries', 'review_reason')) {
            Schema::table('gl_entries', function (Blueprint $table) {
                $table->dropColumn('review_reason');
            });
        }
    }
};
