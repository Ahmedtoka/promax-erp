<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════
 * طلبات تعديل لوكيشن العميل  ·  ١٧ أغسطس ٢٠٢٦
 * ═══════════════════════════════════════════════════════════════
 *
 * بلاغ المالك: «زرار تعديل عنوان العميل يظهر للغير مؤكد فقط — بمجرد
 * ما أكّد من الداشبورد يختفي من الأبلكيشن ومايطلعش للمندوب تاني».
 *
 * ═══ المشكلة اللي الطلب ده كشفها ═══
 *
 * ⚠️⚠️ **المندوب كان بيأكّد لنفسه.** `saveClientLocation` كانت بتكتب
 * `location_confirmed_at` و`location_confirmed_by` بيوزر **المندوب**
 * وقت ما يسحب النقطة. يعني:
 *
 *   • العميل بيخرج من طابور المراجعة **من غير ما حد يراجعه**.
 *   • والشاشة اللي المفروض تبني «داتابيز عناوين قوية» مابتشوفش
 *     النقط دي أصلاً — بتعدّي عليها كأنها متأكّدة.
 *   • ومع تغيير الأبلكيشن (١٦/٨) اللي بيخبّي الزرار على المؤكَّد،
 *     الزرار كان هيختفي **بمجرد ما المندوب يحفظ** — فلو النقطة غلط
 *     مايقدرش يصحّحها، ومحدش راجعها أصلاً.
 *
 * ═══ الحل: نفصل «بعت» عن «اتأكّد» ═══
 *
 *   `location_submitted_at/by` = المندوب سحب نقطة وبعتها  → طلب
 *   `location_confirmed_at/by` = بني آدم في الداشبورد راجعها → نهائي
 *
 * ⚠️ **الباك فيل بيرجّع الصفوف القديمة للطابور عن قصد.** الصف اللي
 * `location_source = rep_app` ومعاه `location_confirmed_at` هو
 * بالتأكيد تأكيد ذاتي من المندوب (الأدمن لما بيأكّد بيكتب `manual`
 * أو `visit` — `rep_app` ممنوعة في `confirm()` بالفاليديشن). فبننقل
 * التاريخ لخانة «الإرسال» ونفضّي خانة «التأكيد» — ودي **نفس اللي
 * المالك طلبه**: «شاشة أكّد منها كل الطلبات اللي اتعمل تعديل العنوان
 * بتاعها عشان نبني داتابيز قوية».
 *
 * ⚠️ **مفيش إحداثيات بتتمسح.** النقطة والعنوان زي ما هما — اللي
 * بيترجع هو **حالة المراجعة** بس.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ⚠️ **محروسة** — السيرفر مش ريبو جيت والملفات بتترفع بالإيد،
        // فالمايجريشن ممكن تتشغّل على قاعدة الأعمدة فيها موجودة.
        Schema::table('clients', function (Blueprint $table) {
            if (! Schema::hasColumn('clients', 'location_submitted_at')) {
                $table->timestamp('location_submitted_at')->nullable()
                    ->after('location_source');
            }

            if (! Schema::hasColumn('clients', 'location_submitted_by')) {
                $table->unsignedBigInteger('location_submitted_by')->nullable()
                    ->after('location_submitted_at');
            }
        });

        // ⚠️ **الفهرس على الطابور مش على العمود لوحده.** الشاشة
        // بتسأل «rep_app ولسه مااتأكّدش» في كل تحميل، والعميل عندنا
        // بالآلاف — من غير الفهرس ده الاستعلام بيقرا الجدول كله.
        Schema::table('clients', function (Blueprint $table) {
            $idx = 'clients_loc_queue_idx';

            if (! $this->hasIndex('clients', $idx)) {
                $table->index(['location_source', 'location_confirmed_at'], $idx);
            }
        });

        // ═══ الباك فيل ═══
        //
        // ⚠️ الشرط `location_source = rep_app` **ضروري**: من غيره
        // كنا هنفضّي تأكيدات الأدمن الحقيقية ونرجّع مئات العملاء
        // المراجَعين للطابور من غير سبب.
        DB::table('clients')
            ->where('location_source', 'rep_app')
            ->whereNotNull('location_confirmed_at')
            ->update([
                'location_submitted_at' => DB::raw('location_confirmed_at'),
                'location_submitted_by' => DB::raw('location_confirmed_by'),
                'location_confirmed_at' => null,
                'location_confirmed_by' => null,
            ]);
    }

    public function down(): void
    {
        // ⚠️ الرجوع بيرجّع التأكيد الذاتي زي ما كان — عشان الداون
        // مايسيبش نقط بلا أي بصمة.
        DB::table('clients')
            ->whereNotNull('location_submitted_at')
            ->whereNull('location_confirmed_at')
            ->update([
                'location_confirmed_at' => DB::raw('location_submitted_at'),
                'location_confirmed_by' => DB::raw('location_submitted_by'),
            ]);

        Schema::table('clients', function (Blueprint $table) {
            if ($this->hasIndex('clients', 'clients_loc_queue_idx')) {
                $table->dropIndex('clients_loc_queue_idx');
            }

            foreach (['location_submitted_by', 'location_submitted_at'] as $col) {
                if (Schema::hasColumn('clients', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }

    /**
     * فيه فهرس بالاسم ده؟
     *
     * ⚠️ `Schema::hasIndex` مش موجودة في كل نسخ لارافيل، والـ
     * `doctrine/dbal` مش متسطّب. السؤال المباشر لـMySQL أضمن.
     */
    private function hasIndex(string $table, string $index): bool
    {
        try {
            return DB::select(
                'SHOW INDEX FROM `'.$table.'` WHERE Key_name = ?', [$index]
            ) !== [];
        } catch (\Throwable) {
            return false;
        }
    }
};
