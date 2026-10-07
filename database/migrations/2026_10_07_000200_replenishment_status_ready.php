<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * حالة «ready» لطلبات الريفيل (٧/١٠/٢٠٢٦).
 *
 * بلاغ اللايف: «Data truncated for column 'status'» لما أمين المخزن يخلّص
 * تجهيز أمر مربوط بطلب ريفيل. فلو ١٥/٨ ضاف الحالة `ready` في
 * `ReplenishmentRequest::STATUSES` و`PickOrder` بيكتبها، لكن العمود اتعمل
 * ENUM من ٢٩/٧ ومحدش وسّعه — فالتجهيز كله بيرجع 500.
 *
 * بنحوّله VARCHAR(20) زي باقي أعمدة الحالة (pick_orders / stock_transfers)
 * عشان أي حالة جديدة ماتكسرش تاني. القيم الموجودة زي ما هي.
 *
 * ⚠️ محروسة: مابتلمسش العمود لو مش ENUM.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('replenishment_requests', 'status')) {
            return;
        }

        $type = DB::selectOne(
            "SELECT DATA_TYPE AS t FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'replenishment_requests' AND COLUMN_NAME = 'status'"
        )?->t;

        if ($type === 'enum') {
            DB::statement("ALTER TABLE replenishment_requests MODIFY status VARCHAR(20) NOT NULL DEFAULT 'pending'");
        }
    }

    public function down(): void
    {
        // مفيش رجوع: لو فيه صفوف «ready» الـENUM القديم هيقصّها.
    }
};
