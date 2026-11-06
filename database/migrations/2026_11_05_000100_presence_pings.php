<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * نبضات المتصفح — شاشة «وقت الشغل الفعلي» (٥ نوفمبر ٢٠٢٦، جاد بس).
 *
 * السيرفر بيشوف فتح الصفحة والحفظ بس — صفحة مفتوحة ساعة من غير ما حد
 * يلمسها كانت شبه واحد بيقرا أو بيكتب. المتصفح بيبعت نبضة كل دقيقة:
 * أنهي صفحة، وفي الدقيقة دي كان فيه ماوس/كيبورد (`active`) ولا الصفحة
 * قدامه ومحدش لمسها (`idle`) ولا مستخبية ورا برنامج تاني (`hidden`).
 *
 * ⚠️ صف لكل تاب في الدقيقة — ~٥٠٠ صف لليوزر في اليوم. التنضيف في
 * `promax:prune-activity --pings`. إضافة بس، ومحمية لو اتشغلت مرتين.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('presence_pings')) {
            return;
        }

        Schema::create('presence_pings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('tab', 16);                    // تاب المتصفح — يوزر ممكن يفتح كذا تاب
            $table->string('state', 8);                   // active | idle | hidden
            $table->string('route', 120)->nullable();
            $table->string('url', 300)->nullable();
            $table->timestamp('at');                      // الدقيقة (ثواني = صفر)

            $table->index(['user_id', 'at']);
            $table->index('at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('presence_pings');
    }
};
