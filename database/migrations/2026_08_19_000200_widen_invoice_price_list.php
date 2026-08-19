<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * توسيع `invoices.price_list`  ·  ١٩ أغسطس ٢٠٢٦
 *
 * نفس انفجار «MasterOntheGoSophia» بتاع مايجريشن 000400 بالظبط بس
 * في جدول الفواتير: العمود اتعمل `varchar(10)` أيام old/new
 * (مايجريشن 000011)، وأول فاتورة لعميل على قايمة مسمّاة أطول من
 * ١٠ حروف ضربت «Data too long for column price_list» ورفضت البيعة
 * من الأبلكيشن (بلاغ ١٩/٨ بسكرين شوت INV-1045).
 *
 * 000400 وسّعت clients وcontracts — والفواتير اتنست. دي آخر واحدة:
 * مفيش عمود price_list نصي في أي جدول تاني (اتفحصت المايجريشنز)،
 * وprice_mode بتاع أوامر التوريد قيمه ثابتة قصيرة فمش محتاج.
 *
 * ٤٠ = ٣٠ بتاعة فاليديشن كود القايمة + هامش — نفس 000400.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ⚠️ محروسة بمعنى «آمنة للتكرار» — `change()` على عمود واسع
        // خلاص مابيكسرش.
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('price_list', 40)->nullable()->change();
        });
    }

    public function down(): void
    {
        // ⚠️ **مفيش رجوع لـ10** — التضييق بيقصقص أي كود طويل اتكتب
        // بعد التوسيع ويحوّله لقيمة مالهاش معنى في صمت.
    }
};
