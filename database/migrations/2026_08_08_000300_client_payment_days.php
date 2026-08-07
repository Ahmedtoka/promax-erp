<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════
 * مواعيد السداد على العميل نفسه (2026-08-08)
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **كانت على العقد بس** — يعني العميل الآجل اللي مالوش عقد (كل
 * الكاش فان والجملة تقريباً) مالوش مدة سداد ولا نقطة بداية، وأعمار
 * ديونه كانت بتتحسب من تاريخ الفاتورة افتراضياً من غير ما حد قرر ده.
 *
 * القرار (المالك 2026-08-08): **الشروط تتحدد على العميل وقت التعريف،
 * والعقد يغلبها لو موجود وسارٍ.** المدير التجاري بيحدد وهو بيعرّف
 * العميل: كاش ولا آجل ولا الاتنين، وكام يوم، ومن إمتى نعد.
 *
 * ⚠️ **نفس مفردات العقد بالظبط** (`first_supply` / `invoice`) — لو
 * اخترعنا مفردات تانية هنا، `Client::paymentBasis()` هتترجم بين
 * قاموسين وأول اختلاف بينهم بيطلع رقم استحقاق مختلف عن العقد الموقّع.
 *
 * `payment_terms` نفسه موجود من مايجريشن 000090 وبيقبل `cash`/`credit`؛
 * القيمة التالتة `both` مش محتاجة تغيير سكيما (العمود `string(10)`)،
 * بس الموديل والفاليديشن هما اللي بيعرّفوها.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (! Schema::hasColumn('clients', 'payment_days')) {
                // ⚠️ `nullable` مش `default 0` — «صفر يوم» يعني مستحق
                // فوراً وده قرار، و`null` يعني **محدش حدد** وده
                // الفرق اللي بيخلّي الشاشة تقول «غير محدد» بدل ما
                // تعرض ميعاد استحقاق النهارده لكل عميل في السيستم.
                $table->unsignedSmallInteger('payment_days')->nullable()->after('payment_terms');
            }

            if (! Schema::hasColumn('clients', 'payment_days_from')) {
                $table->string('payment_days_from', 20)->nullable()->after('payment_days');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            foreach (['payment_days_from', 'payment_days'] as $col) {
                if (Schema::hasColumn('clients', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
