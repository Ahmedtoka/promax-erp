<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * أوامر توريد الكي أكاونت بموافقة الحسابات — قرار المالك 2026-08-04:
 *
 *   مدير القناة بيعمل PO (سلسلة ← فرع ← مندوب ← تاريخ وساعة التوريد)
 *   ← بينزل للحسابات (بيشوفوا رصيد العميل): موافقة / تعديل / رفض
 *   ← الموافقة بتعمل أمر تجهيز للمخزن ← يتجهز ← إشعار للمندوب
 *   ← يستلم في عهدته ← يسلم للفرع ويقدر يعدل الكميات وقت التسليم
 *   ← القيد بيتكتب بالمسلَّم فعلاً.
 *
 * `approval_status` **nullable** عن قصد: null = الفلو القديم (سواق /
 * ريفيل) من غير موافقة خالص — عشان مانكسرش الفلوهات السبعة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('purchase_orders', 'approval_status')) {
                $table->string('approval_status', 20)->nullable()->index()->after('status');
            }
            if (! Schema::hasColumn('purchase_orders', 'approved_by')) {
                $table->foreignId('approved_by')->nullable()->after('approval_status')
                    ->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('purchase_orders', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('approved_by');
            }
            if (! Schema::hasColumn('purchase_orders', 'approval_note')) {
                $table->string('approval_note', 500)->nullable()->after('approved_at');
            }
            if (! Schema::hasColumn('purchase_orders', 'was_edited')) {
                $table->boolean('was_edited')->default(false)->after('approval_note');
            }
            if (! Schema::hasColumn('purchase_orders', 'due_at')) {
                // باليوم **والساعة** — عمود `due_date` القديم (يوم بس) بيفضل زي ما هو
                $table->dateTime('due_at')->nullable()->after('due_date');
            }
            if (! Schema::hasColumn('purchase_orders', 'warehouse_id')) {
                $table->foreignId('warehouse_id')->nullable()->after('client_id')
                    ->constrained('warehouses')->nullOnDelete();
            }
            if (! Schema::hasColumn('purchase_orders', 'pick_order_id')) {
                $table->foreignId('pick_order_id')->nullable()->after('warehouse_id')
                    ->constrained('pick_orders')->nullOnDelete();
            }
            if (! Schema::hasColumn('purchase_orders', 'created_by')) {
                $table->foreignId('created_by')->nullable()->after('pick_order_id')
                    ->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            foreach (['approved_by', 'warehouse_id', 'pick_order_id', 'created_by'] as $col) {
                if (Schema::hasColumn('purchase_orders', $col)) {
                    $table->dropConstrainedForeignId($col);
                }
            }
            foreach (['approval_status', 'approved_at', 'approval_note', 'was_edited', 'due_at'] as $col) {
                if (Schema::hasColumn('purchase_orders', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
