<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تاريخ أول زيارة ووقتها على خطة السير (طلب المالك ١٣ أغسطس ٢٠٢٦):
 * «خلي الاختيار بالتاريخ بتاع اليوم والوقت كمان».
 *
 * ⚠️ **الخطة فضلت نمط أسبوعي.** العمودين دول **مرساة** مش جدول
 * تواريخ: `starts_on` بيقول «النمط ده يبدأ من اليوم ده» (ومنه بيتشتق
 * `weekday` في الشاشة)، و`visit_at` وقت الزيارة المتفق عليه. توليد
 * صف لكل زيارة كان هيعمل مصدر تاني لخط السير — والقرار ده اتاخد قبل
 * كده وبقى دوكترين (`Journeys`: الزيارات بتتحسب وقت الطلب).
 *
 * ⚠️ **الاتنين `nullable` عن قصد** — كل الخطط القديمة بتفضل شغّالة
 * بالظبط زي ما هي: `dueOn()` بتتخطى فحص البداية لما `starts_on = null`،
 * ومرساة التردد بتفضل `epoch()` الثابتة.
 *
 * ⚠️ محروسة — السيرفر اللايف بيترفع بالإيد مش جيت.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('journey_plans')) {
            return;
        }

        Schema::table('journey_plans', function (Blueprint $table) {
            if (! Schema::hasColumn('journey_plans', 'starts_on')) {
                $table->date('starts_on')->nullable()->after('every_weeks');
            }

            if (! Schema::hasColumn('journey_plans', 'visit_at')) {
                $table->time('visit_at')->nullable()->after('starts_on');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('journey_plans')) {
            return;
        }

        Schema::table('journey_plans', function (Blueprint $table) {
            if (Schema::hasColumn('journey_plans', 'visit_at')) {
                $table->dropColumn('visit_at');
            }

            if (Schema::hasColumn('journey_plans', 'starts_on')) {
                $table->dropColumn('starts_on');
            }
        });
    }
};
