<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════
 * زيارات المخزن (2026-08-08)
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **جدول مستقل مش `visits`** (قرار المالك 2026-08-08).
 * `visits.client_id` عمود إجباري بـFK — والمخزن مش عميل. حشره
 * جوّاها كان معناه إما عميل وهمي اسمه «مخزن المعادي» (وساعتها كل
 * تقارير الزيارات ومعدل التغطية ومتوسط الزيارة بتعدّه كعميل)، أو
 * تفليت الـFK وفتح الباب لزيارات بلا هدف.
 *
 * **ليه أصلاً؟** الاستلام من المخزن كان بيحصل من أي مكان — المندوب
 * يدوس «استلمت» وهو في الشارع. دلوقتي لازم يسجّل دخوله المخزن
 * الأول، واللوكيشن بيتخزن لحظة الدخول عشان نقدر نتحقق بعدين إنه
 * كان فعلاً واقف في فرع المعادي مش على بعد 20 كيلو.
 *
 * ⚠️ **اللوكيشن بيتخزن ومابيتفحصش دلوقتي** (قرار صريح). فرق
 * المسافة محتاج معايرة على أرض الواقع الأول — GPS جوّه مخزن
 * مسقوف بيضرب، وقفل الاستلام على رقم مخمّن كان هيوقف الشغل.
 * العمود موجود والشاشة بتعرض المسافة، والقرار يتاخد على داتا حقيقية.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('warehouse_visits')) {
            return;
        }

        Schema::create('warehouse_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();

            $table->timestamp('checked_in_at');
            $table->timestamp('checked_out_at')->nullable();

            // ⚠️ **لوكيشن الدخول والخروج الاتنين.** الدخول بيثبت إنه
            // وصل، والخروج بيثبت إنه فضل هناك — من غير التاني، اللي
            // بيسجّل دخول ويمشي بيفضل «في المخزن» على الورق ساعات.
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->decimal('out_lat', 10, 7)->nullable();
            $table->decimal('out_lng', 10, 7)->nullable();

            // ⚠️ **الدقايق محسوبة ومخزّنة عند القفل.** الحساب وقت
            // العرض بيتغيّر لو التوقيت الصيفي اتحرك أو التاريخ اتعدّل،
            // والزيارة المقفولة حقيقة تاريخية لازم تفضل ثابتة.
            // الزيارة المفتوحة بتتحسب لحظياً في الموديل.
            $table->unsignedSmallInteger('minutes')->nullable();

            // انقفلت لوحدها (انصراف أو بعد منتصف الليل) — مش بإيد المندوب
            $table->boolean('auto_closed')->default(false);

            $table->string('note', 300)->nullable();
            $table->timestamps();

            // ⚠️ **الإندكس ده هو اللي بيخدم الحارس.** كل ريكوست استلام
            // بيسأل «فيه زيارة مفتوحة لليوزر ده؟» — من غيره الحارس
            // بيعمل full scan على كل زيارات السيستم في كل استلام.
            $table->index(['user_id', 'checked_out_at']);
            $table->index(['warehouse_id', 'checked_in_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_visits');
    }
};
