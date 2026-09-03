<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * رقم أوردر الأونلاين مايتكررش (قرار المالك ٤/٩) — البحث والباركود
 * بيعتمدوا عليه. shopify_id أصلاً unique فالتكرار مش وارد عملياً،
 * والإندكس ده خط دفاع صريح.
 *
 * ⚠️ محروسة مرتين: لو فيه تكرار قديم في الداتا الحية بنسجّل في اللوج
 * وبنعدّي بدل ما المايجريشن يقع نص سكة على اللايف.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('online_orders')) {
            return;
        }

        $dups = DB::table('online_orders')
            ->select('number')->groupBy('number')
            ->havingRaw('COUNT(*) > 1')->count();

        if ($dups > 0) {
            Log::error("online_orders: {$dups} رقم أوردر مكرر — الإندكس الفريد ماتعملش. نضّف التكرار وشغّل المايجريشن تاني.");

            return;
        }

        try {
            Schema::table('online_orders', function (Blueprint $table) {
                $table->unique('number', 'online_orders_number_unique');
            });
        } catch (\Throwable $e) {
            // موجود من قبل — تمام
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('online_orders')) {
            try {
                Schema::table('online_orders', function (Blueprint $table) {
                    $table->dropUnique('online_orders_number_unique');
                });
            } catch (\Throwable $e) {
                // مش موجود — تمام
            }
        }
    }
};
