<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════
 * مين المدير اللي وافق على طلب البضاعة؟  ·  ١٥ أغسطس ٢٠٢٦
 * ═══════════════════════════════════════════════════════════════
 *
 * سؤال المالك بالنص: «عاوز أعرف مين المدير اللي وافق على
 * الريبلانشمنت».
 *
 * ═══ السبب: الموافق ماكانش بيتسجّل أصلاً ═══
 *
 * `replenishment_requests` فيه `requested_by` (مين طلب)
 * و`assigned_to` (مين هيوصّل) و`assigned_at` (إمتى) — بس **مفيش
 * أي عمود لمين وافق ونزّل الطلب**. والموافقة دي قرار بيحرّك بضاعة
 * ويولّد أمر توريد بقيمة مالية، فلازم يكون ليها صاحب موثّق زي
 * موافقة الحسابات على أمر التوريد (`approved_by`) بالظبط.
 *
 * ⚠️ عمود واحد بس: `assigned_by`. مافيش `approved_at` جديد —
 * `assigned_at` الموجود هو نفس اللحظة بالضبط (الاتنين بيتكتبوا
 * في نفس الـ`update` جوه `assignTo`)، وعمود تاني بنفس القيمة
 * بيبقى مصدر تاني للحقيقة يفترقوا مع أول باج.
 *
 * ═══ الباك-فيل ═══
 *
 * الطلبات القديمة بياخدوا الموافق من `purchase_orders.created_by`
 * بتاع الأمر اللي اتولد منها — ده **نفس الشخص** بالظبط بعد إصلاح
 * ١٥ أغسطس (`assignTo` بتكتب الاتنين من `$actor`)، فالباك-فيل
 * دقيق مش تخمين.
 *
 * ⚠️ الصفوف اللي أمرها كمان مالوش `created_by` بتفضل NULL —
 * والشاشة بتقول «غير مسجَّل». مافيش فولباك على «أول أدمن».
 *
 * ⚠️ مايجريشن محروسة: `hasTable`/`hasColumn` قبل أي لمسة.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('replenishment_requests')) {
            return;
        }

        if (! Schema::hasColumn('replenishment_requests', 'assigned_by')) {
            Schema::table('replenishment_requests', function (Blueprint $table) {
                $table->foreignId('assigned_by')->nullable()->after('assigned_to')
                    ->constrained('users')->nullOnDelete();
            });
        }

        // باك-فيل من صاحب أمر التوريد المتولّد
        if (Schema::hasColumn('replenishment_requests', 'purchase_order_id')
            && Schema::hasTable('purchase_orders')
            && Schema::hasColumn('purchase_orders', 'created_by')) {

            DB::table('replenishment_requests as r')
                ->join('purchase_orders as po', 'po.id', '=', 'r.purchase_order_id')
                ->whereNull('r.assigned_by')
                ->whereNotNull('po.created_by')
                ->update(['r.assigned_by' => DB::raw('po.created_by')]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('replenishment_requests')
            && Schema::hasColumn('replenishment_requests', 'assigned_by')) {
            Schema::table('replenishment_requests', function (Blueprint $table) {
                $table->dropConstrainedForeignId('assigned_by');
            });
        }
    }
};
