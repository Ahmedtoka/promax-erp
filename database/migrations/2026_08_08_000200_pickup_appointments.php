<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════
 * مواعيد الاستلام — يوم وساعة (2026-08-08)
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **معادين مختلفين تماماً، وخلطهم كان بيضيّع نص المعلومة:**
 *
 *   • `pick_orders.pickup_at`      = المندوب ييجي **المخزن** إمتى
 *   • `purchase_orders.pickup_at`  = نفس الحاجة، بس متسجّلة على أمر
 *                                     التوريد لأن المدير بيحددها
 *                                     **وقت إنشاء الأمر** — وأمر
 *                                     التجهيز نفسه مابيتعملش غير بعد
 *                                     موافقة الحسابات، فلازم مكان
 *                                     يستنى فيه الميعاد لحد ما يتنسخ
 *   • `purchase_orders.due_at`     = البضاعة توصل **الفرع** إمتى
 *                                     (موجود من قبل — مش بيتلمس)
 *
 * يعني الأمر الواحد ممكن يقول: «تعالى بكره 9 ص خد البضاعة من مخزن
 * المعادي» + «سلّمها لأون ذا رن يوم 11 قبل 2 ظهراً».
 *
 * ⚠️ **`needed_on` القديم تاريخ بس من غير ساعة** — و«تعالى بكره»
 * من غير ساعة معناها المندوب يوصل المخزن الساعة 8 يلاقي الشيفت
 * مابدأش. العمود القديم بيفضل موجود (فيه داتا) والبيانات بتتنقل
 * للجديد بالساعة 9 صباحاً كافتراض معقول.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pick_orders') && ! Schema::hasColumn('pick_orders', 'pickup_at')) {
            Schema::table('pick_orders', function (Blueprint $table) {
                $table->dateTime('pickup_at')->nullable()->after('needed_on')->index();
            });

            // ⚠️ النقل بالساعة 9 ص — `needed_on` تاريخ بس، والتحويل
            // المباشر كان هيخلي كل المواعيد القديمة 12:00 منتصف الليل
            DB::table('pick_orders')
                ->whereNotNull('needed_on')
                ->update(['pickup_at' => DB::raw("TIMESTAMP(needed_on, '09:00:00')")]);
        }

        if (Schema::hasTable('purchase_orders') && ! Schema::hasColumn('purchase_orders', 'pickup_at')) {
            Schema::table('purchase_orders', function (Blueprint $table) {
                // ⚠️ **قبل `due_at` في ترتيب الأعمدة عن قصد** — الاستلام
                // بيسبق التسليم زمنياً، وترتيب الأعمدة بيقرا في أي
                // `SELECT *` وفي شاشات التصدير
                $table->dateTime('pickup_at')->nullable()->after('warehouse_id')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('pick_orders', 'pickup_at')) {
            Schema::table('pick_orders', fn (Blueprint $t) => $t->dropColumn('pickup_at'));
        }

        if (Schema::hasColumn('purchase_orders', 'pickup_at')) {
            Schema::table('purchase_orders', fn (Blueprint $t) => $t->dropColumn('pickup_at'));
        }
    }
};
