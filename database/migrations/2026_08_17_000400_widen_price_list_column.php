<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * توسيع عمود قايمة السعر النصي  ·  ١٧ أغسطس ٢٠٢٦
 *
 * انفجار على اللايف: «Data too long for column price_list» وقت
 * «حفظ الكل» في إعداد السلاسل.
 *
 * ⚠️ **السبب تاريخي**: العمود اتعمل `varchar(10)` أيام ما كانت
 * القيمة `old`/`new` بس (مايجريشن 000011). بعدين القوايم المسمّاة
 * سمحت بكود لحد **٣٠ حرف** (`alpha_dash max:30` في إنشاء القايمة)،
 * وكل الكتّاب — الإعداد الجماعي، ختم السلسلة، الاستيراد — بيكتبوا
 * الكود في العمودين. أول قايمة اسمها أطول من ١٠ حروف
 * («MasterOntheGoSophia» = ١٩) فجّرت أول حفظ.
 *
 * ⚠️ `contracts.price_list` نفس الحكاية بالظبط — بيتوسّع معاه قبل
 * ما ينفجر هو كمان أول ما عقد يمسك القايمة دي.
 *
 * ٤٠ = ٣٠ بتاعة الفاليديشن + هامش.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ⚠️ محروسة بمعنى «آمنة للتكرار» — `change()` على عمود واسع
        // خلاص مابيكسرش، والفحص مش ضروري.
        Schema::table('clients', function (Blueprint $table) {
            $table->string('price_list', 40)->default('new')->change();
        });

        Schema::table('contracts', function (Blueprint $table) {
            $table->string('price_list', 40)->nullable()->change();
        });
    }

    public function down(): void
    {
        // ⚠️ **مفيش رجوع لـ10** — التضييق بيقصقص أي كود طويل اتكتب
        // بعد التوسيع ويحوّله لقيمة مالهاش معنى في صمت.
    }
};
