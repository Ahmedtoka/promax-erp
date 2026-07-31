<?php

use App\Support\Governorates;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════
 * فلو تعريف العميل — المحافظة واللوكيشن والضريبة وأساس أيام السداد
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **المحافظة مش نفس المنطقة.** المنطقة (`zones`) وحدة تشغيلية —
 * خط سير مندوب. المحافظة وحدة جغرافية إدارية وبتتطلب في فاتورة
 * الضرايب. لحد دلوقتي `EtaExport` كان بيحط محافظة **الشركة** على كل
 * عميل عشان الحقل مايبقاش فاضي — يعني كل فاتورة إلكترونية بتطلع
 * بعنوان غلط. العمود ده بيقفل الثغرة دي.
 *
 * ⚠️ **لينك اللوكيشن غير الإحداثيات.** `lat/lng` بتتقري من الأبلكيشن
 * وبترسم الدبوس على الخريطة. اللينك (خرايط جوجل) هو اللي المندوب
 * بيبعته لبعضه ويفتحه على تليفونه. تخزين اللينك في `address`
 * بيكسّر البحث بالعنوان وبيخلّي الفاتورة تطبع رابط بدل عنوان.
 *
 * ⚠️ **أساس أيام السداد.** الرقم لوحده مش كفاية — لازم نعرف بيتحسب
 * من إمتى. الاتفاق: من **أول توريد للعميل** (`first_activity_at`)
 * مش من تاريخ كل فاتورة. من غير العمود ده كل شاشة هتفترض افتراض
 * مختلف والاستحقاق يطلع برقمين.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (! Schema::hasColumn('clients', 'governorate')) {
                // ⚠️ مفتاح ثابت (`cairo`) مش نص حر. النص الحر بيتخزن
                // بلغة الواجهة وقت الإنشاء، فالعميل اللي اتعمل من
                // الشاشة الإنجليزية بيطلع "Cairo" في التقرير العربي،
                // والتجميع بالمحافظة بيدي مجموعتين لنفس المحافظة.
                $table->string('governorate', 40)->nullable()->index();
            }

            if (! Schema::hasColumn('clients', 'location_url')) {
                $table->string('location_url', 500)->nullable();
            }

            if (! Schema::hasColumn('clients', 'tax_cycle')) {
                // دورة الإقرار الضريبي: شهري / ربع سنوي / سنوي
                $table->string('tax_cycle', 20)->nullable();
            }
        });

        Schema::table('zones', function (Blueprint $table) {
            if (! Schema::hasColumn('zones', 'governorate')) {
                // المنطقة بتقع في محافظة — عشان اختيار المحافظة
                // في الفورم يفلتر المناطق بدل ما يعرض الـ18 كلهم
                $table->string('governorate', 40)->nullable()->index();
            }
        });

        // ═══════════ تخمين محافظة المناطق الموجودة ═══════════
        // ⚠️ لازم بعد ما العمود يتعمل وفي `up()` نفسها. لو سبناه للسيدر،
        // اللي مش هيشغّل السيدر هيلاقي كل المناطق من غير محافظة والفلتر
        // في فورم العميل هيبقى بلا معنى.
        // ⚠️ استعلام خام مش Eloquent — الموديل ممكن يكون اتغيّر بعدين
        // والمايجريشن القديمة لازم تفضل شغّالة زي ما هي.
        foreach (DB::table('zones')->whereNull('governorate')->get(['id', 'name', 'name_en']) as $zone) {
            $guess = Governorates::guessFromZone($zone->name, $zone->name_en);

            if ($guess !== null) {
                DB::table('zones')->where('id', $zone->id)->update(['governorate' => $guess]);
            }
        }

        // العميل بياخد محافظة منطقته — أدق من الفاضي، والمستخدم يعدّلها
        DB::table('clients')
            ->join('zones', 'zones.id', '=', 'clients.zone_id')
            ->whereNull('clients.governorate')
            ->whereNotNull('zones.governorate')
            ->update(['clients.governorate' => DB::raw('zones.governorate')]);

        Schema::table('contracts', function (Blueprint $table) {
            if (! Schema::hasColumn('contracts', 'payment_days_from')) {
                $table->string('payment_days_from', 20)->default('first_supply');
            }
        });

        Schema::table('contract_clauses', function (Blueprint $table) {
            if (! Schema::hasColumn('contract_clauses', 'preset')) {
                // ⚠️ العلامة دي **حماية للبنود المكتوبة بإيد**. الـ22 عقد
                // الحقيقيين اتقروا من الـPDF وبنودهم اتكتبت بنصها. لو
                // الفورم قدر يمسح أي بند بنفس النوع لما التشيك بوكس
                // يتقفل، تشغيلة واحدة بتمسح تحليل يومين. الفورم بيلمس
                // الصفوف اللي `preset` مليان فيها بس.
                $table->string('preset', 40)->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('contract_clauses', function (Blueprint $table) {
            if (Schema::hasColumn('contract_clauses', 'preset')) {
                $table->dropIndex(['preset']);
                $table->dropColumn('preset');
            }
        });

        Schema::table('contracts', function (Blueprint $table) {
            if (Schema::hasColumn('contracts', 'payment_days_from')) {
                $table->dropColumn('payment_days_from');
            }
        });

        Schema::table('zones', function (Blueprint $table) {
            if (Schema::hasColumn('zones', 'governorate')) {
                $table->dropIndex(['governorate']);
                $table->dropColumn('governorate');
            }
        });

        Schema::table('clients', function (Blueprint $table) {
            foreach (['location_url', 'tax_cycle'] as $column) {
                if (Schema::hasColumn('clients', $column)) {
                    $table->dropColumn($column);
                }
            }

            if (Schema::hasColumn('clients', 'governorate')) {
                // ⚠️ الإندكس قبل العمود — MySQL بيرفض إسقاط عمود
                // عليه إندكس في نفس الـ ALTER في بعض النسخ
                $table->dropIndex(['governorate']);
                $table->dropColumn('governorate');
            }
        });
    }
};
