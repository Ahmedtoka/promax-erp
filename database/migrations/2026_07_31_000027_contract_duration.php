<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════
 * مدة التعاقد
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **المدة مش نفس تاريخ البداية والنهاية.** التواريخ بتقول «من
 * إمتى لإمتى»، والمدة بتقول «ده عقد إيه»: سنة؟ 6 شهور؟ مفتوح؟ تعامل
 * بالطلب؟
 *
 * الفرق بيبان في حالتين:
 *
 *   • **عقد مفتوح المدة** — ليه بداية ومالوش نهاية. من غير عمود
 *     المدة، التاريخ الفاضي معناه «حد نسي يملاه» ولا «مفتوح عن قصد»؟
 *     محدش يعرف، وتنبيه التجديد بيفضل ساكت أو بيرنّ غلط.
 *
 *   • **تعامل بالطلب** — مفيش عقد أصلاً. كان بيتخزن كعقد بتواريخ
 *     مخترعة عشان الشاشة تقبله.
 *
 * والمدة كمان هي اللي الفاليديشن بتقيس عليها: عقد «سنة» بتاريخ نهاية
 * بعد شهرين معناه غلطة كتابة، والخصومات هتفضل شغالة بعد ما العقد خلص.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            if (! Schema::hasColumn('contracts', 'duration')) {
                $table->string('duration', 20)->nullable()->after('type_key');
            }
        });

        // ═══ استنتاج مدة العقود الموجودة من تواريخها ═══
        //
        // ⚠️ **الاستنتاج بيحصل مرة واحدة وبتحفّظ.** الـ22 عقد الحقيقيين
        // اتقروا من الـPDF وليهم تواريخ فعلية — بنقيس الفرق ونحطّ أقرب
        // مدة. اللي مالوش نهاية بياخد `open` مش `year`: تخمين المدة
        // على عقد مفتوح بيخلّي تنبيه التجديد يرنّ في يوم محدش قرره.
        if (! Schema::hasColumn('contracts', 'duration')) {
            return;
        }

        $rows = DB::table('contracts')
            ->whereNull('duration')
            ->get(['id', 'starts_at', 'ends_at']);

        foreach ($rows as $row) {
            DB::table('contracts')->where('id', $row->id)
                ->update(['duration' => $this->guess($row->starts_at, $row->ends_at)]);
        }
    }

    /**
     * أقرب مدة للتواريخ الموجودة.
     *
     * ⚠️ بترجّع `null` لو مفيش بداية — العقد اللي مالوش تواريخ خالص
     * مانعرفش هو تعامل بالطلب ولا بيانات ناقصة، والتخمين هنا بيتحوّل
     * لحقيقة في الشاشة.
     */
    private function guess(?string $from, ?string $to): ?string
    {
        // مفيش تواريخ خالص = تعامل بالطلب، مش «بيانات ناقصة»
        if (! $from && ! $to) {
            return 'per_order';
        }

        // ⚠️ نهاية من غير بداية = بيانات ناقصة في العقد الأصلي.
        // `custom` بتخليه يتحفظ لحد ما حد يصلّح التاريخ — أي مدة
        // تانية بترفضه، وكارت العميل بيبقى مقفول.
        if (! $from) {
            return 'custom';
        }

        if (! $to) {
            return 'open';
        }

        $days = abs((int) round((strtotime($to) - strtotime($from)) / 86400)) + 1;

        // ⚠️ **النوافذ لازم تطابق `Contract::DURATIONS` بالظبط، مش
        // حدود عليا بس.** أول نسخة كانت `$days <= 368 => 'year'`،
        // فعقد 278 يوم (بدأ مارس وبينتهي 31 ديسمبر) كان بياخد `year`
        // — و`checkDuration()` بترفضها لأن سنة = 362 يوم على الأقل.
        // النتيجة كانت كارت عميل **مستحيل يتحفظ**: كل مدة بترفضه
        // وتواريخه جاية من عقد موقّع. اتنين من الـ22 عقد كده.
        return match (true) {
            $days >= 27 && $days <= 32 => 'month',
            $days >= 88 && $days <= 93 => 'quarter',
            $days >= 179 && $days <= 185 => 'half_year',
            $days >= 362 && $days <= 368 => 'year',
            $days >= 369 => 'multi_year',
            // مايقعش في أي نافذة — مدة مخصصة من غير فحص
            default => 'custom',
        };
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            if (Schema::hasColumn('contracts', 'duration')) {
                $table->dropColumn('duration');
            }
        });
    }
};
