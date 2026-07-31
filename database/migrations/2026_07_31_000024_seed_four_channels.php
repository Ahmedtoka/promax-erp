<?php

use App\Models\Channel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * القنوات الأربعة — بتتعمل مع المايجريشن مش مع السيدر
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **القنوات الأربعة ثابت في السيستم مش داتا اختيارية.** كل عميل
 * لازم يقع في واحدة منهم، والتسعير كله معلّق عليهم. لما كانوا
 * بيتعملوا في `ModernTradeSeeder`، اللي بيشغّل `migrate` بس كان
 * بيلاقي قناة واحدة (أو مفيش) — وفورم العميل بيفتح بقايمة قنوات
 * فيها اختيار وحيد، فالمستخدم مش لاقي «كاش فان» وبيسيب الخانة
 * فاضية، والعميل بيتحفظ من غير قناة ومن غير خصم.
 *
 * ⚠️ **مابيكتبش فوق الموجود.** الاسم بيتعدّل من `/erp/channels`.
 * المايجريشن بتضيف الناقص بس.
 *
 * ⚠️ استعلام خام مش الموديل — المايجريشن لازم تفضل شغّالة حتى لو
 * الموديل اتغيّر بعدين. `Channel::DEFAULTS` ثابت آمن نقرا منه.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (Channel::DEFAULTS as $code => [$name, $nameEn, $color]) {
            $existing = DB::table('channels')->where('code', $code)->first();

            if ($existing === null) {
                DB::table('channels')->insert([
                    'code' => $code,
                    'name' => $name,
                    'name_en' => $nameEn,
                    'color' => $color,
                    'active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                continue;
            }

            // القناة موجودة — بنملّى الناقص بس ومابنلمسش النسبة ولا الاسم
            $fill = [];

            if (blank($existing->name_en)) {
                $fill['name_en'] = $nameEn;
            }

            if (blank($existing->color)) {
                $fill['color'] = $color;
            }

            if ($fill !== []) {
                DB::table('channels')->where('id', $existing->id)
                    ->update($fill + ['updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        // ⚠️ **مابنمسحش.** القنوات دي عليها عملاء وفواتير، ومسحها
        // بيقطع كل الروابط. الرجوع للخلف هنا مالوش معنى آمن.
    }
};
