<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════
 * مين عمل أمر التوريد؟ — باك-فيل  ·  ١٥ أغسطس ٢٠٢٦
 * ═══════════════════════════════════════════════════════════════
 *
 * بلاغ المالك: «عاوز أعرف مين اللي عمل الـPO — مفيش مين اللي عمله».
 *
 * ═══ السبب ═══
 *
 * `purchase_orders.created_by` اتضاف يوم ٤ أغسطس، وتلات مسارات
 * الإنشاء في `OpsController` (يدوي / استيراد دفعة / استيراد شيت)
 * بتكتبه صح. لكن المسار الرابع — `ReplenishmentRequest::assignTo`،
 * اللي بيحوّل طلب البضاعة لأمر توريد — **كان بيسيبه NULL**. وده
 * أكتر مسار بيتنفّذ فعلاً، فأغلب الأوامر المعلّمة `replenishment`
 * في الليست طلعت بلا صاحب. المسار اتصلّح؛ الملف ده للصفوف القديمة.
 *
 * ═══ من فين بنجيب الصاحب ═══
 *
 * ١. **`replenishment_requests`** — الطلب بيمسك `purchase_order_id`
 *    بعد التحويل، وفيه `requested_by`. ده مصدر حقيقي مش تخمين:
 *    اللي طلب البضاعة هو سبب وجود الأمر. الأولوية ليه.
 *
 * ٢. **`pick_orders.requested_by`** — لو الأمر اتربط بإذن تجهيز
 *    (`purchase_orders.pick_order_id`)، اللي طلب التحميل معروف.
 *
 * ⚠️ **مافيش فولباك على «أول أدمن» ولا على `assigned_to`.**
 *    المندوب المكلَّف بالتسليم **مش** اللي عمل الأمر، وكتابته في
 *    الخانة دي كذب هيتقرا كحقيقة بعد كده. الصف اللي مالوش مصدر
 *    موثوق بيفضل NULL، والشاشة بتقول «غير مسجَّل».
 *
 * ⚠️ مايجريشن محروس: `hasTable` + `hasColumn` على كل جدول قبل ما
 *    نلمسه — السيرفر مش ريبو جيت والملفات بتترفع بالإيد.
 *
 * ⚠️ `down()` **فاضية بقصد**: مافيش طريقة نعرف بيها أنهي صف كان
 *    NULL قبل التشغيل، فالتراجع كان هيمسح بيانات صح كمان.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('purchase_orders')
            || ! Schema::hasColumn('purchase_orders', 'created_by')) {
            return;
        }

        // ═══ ١. من طلب البضاعة اللي اتحوّل للأمر ═══
        if (Schema::hasTable('replenishment_requests')
            && Schema::hasColumn('replenishment_requests', 'purchase_order_id')
            && Schema::hasColumn('replenishment_requests', 'requested_by')) {

            DB::table('purchase_orders as po')
                ->join('replenishment_requests as r', 'r.purchase_order_id', '=', 'po.id')
                ->whereNull('po.created_by')
                ->whereNotNull('r.requested_by')
                ->update(['po.created_by' => DB::raw('r.requested_by')]);
        }

        // ═══ ٢. من إذن التجهيز المربوط بالأمر ═══
        if (Schema::hasTable('pick_orders')
            && Schema::hasColumn('purchase_orders', 'pick_order_id')
            && Schema::hasColumn('pick_orders', 'requested_by')) {

            DB::table('purchase_orders as po')
                ->join('pick_orders as pk', 'pk.id', '=', 'po.pick_order_id')
                ->whereNull('po.created_by')
                ->whereNotNull('pk.requested_by')
                ->update(['po.created_by' => DB::raw('pk.requested_by')]);
        }
    }

    public function down(): void
    {
        // بقصد فاضية — شوف الشرح في رأس الملف.
    }
};
