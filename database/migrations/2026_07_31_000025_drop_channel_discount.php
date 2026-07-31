<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════
 * القناة مابقاش لها نسبة خصم
 * ═══════════════════════════════════════════════════════════════
 *
 * **قرار 2026-07-31:** القناة بُعد **تجميع وتقرير** — كام عميل، كام
 * بضاعة، كام مبيعات. مش مصدر تسعير.
 *
 * الخصم بقى بالترتيب ده وبس:
 *   1. العقد السارٍ (بتاع العميل أو الموروث من سلسلته)
 *   2. خصم خاص متسجّل على العميل
 *   3. خصم السلسلة
 *   4. صفر
 *
 * ⚠️ **ليه الخطوة دي مهمة مش بس شكلية:** لما كانت القناة بتدي نسبة،
 * عميل جديد اتحط في «كي أكاونت» كان بياخد 50% أوتوماتيك من غير ما
 * حد يتفاوض عليها — وأول فاتورة بتطلع بخصم محدش قرره ومحدش واخد باله.
 *
 * ⚠️ **الخصم بيتنقل للعملاء قبل ما العمود يتشال.** أي عميل كان
 * بياخد خصمه من قناته (مالوش عقد ولا خصم خاص ولا سلسلة) لازم ياخد
 * نفس النسبة كخصم خاص، وإلا كل الفواتير بعد المايجريشن دي هتطلع
 * بسعر كامل والعميل يرفض الاستلام.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('channels', 'discount')) {
            return;
        }

        $this->carryDiscountsToClients();

        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn('discount');
        });
    }

    /**
     * نقل خصم القناة لكل عميل كان معتمد عليه.
     *
     * ⚠️ الشرط الثلاثي مقصود: بنلمس العميل اللي **مالوش** مصدر خصم
     * تاني بس. اللي عنده عقد أو خصم خاص أو سلسلة بخصم، نسبته
     * ماتغيّرتش أصلاً — والكتابة فوقها بتغيّر تسعيره من غير سبب.
     */
    private function carryDiscountsToClients(): void
    {
        $rates = \Illuminate\Support\Facades\DB::table('channels')
            ->where('discount', '>', 0)
            ->pluck('discount', 'id');

        foreach ($rates as $channelId => $rate) {
            \Illuminate\Support\Facades\DB::table('clients')
                ->where('channel_id', $channelId)
                // مفيش خصم خاص
                ->where(fn ($q) => $q->whereNull('discount')->orWhere('discount', '<=', 0))
                // مفيش عقد سارٍ خاص بيه
                ->whereNotExists(fn ($q) => $q->selectRaw(1)->from('contracts')
                    ->whereColumn('contracts.client_id', 'clients.id')
                    ->where('contracts.active', true)
                    ->where('contracts.discount', '>', 0)
                    ->where(fn ($e) => $e->whereNull('contracts.ends_at')
                        ->orWhere('contracts.ends_at', '>=', now()->toDateString())))
                // ومش تابع لسلسلة بخصم
                ->whereNotExists(fn ($q) => $q->selectRaw(1)->from('client_groups')
                    ->whereColumn('client_groups.id', 'clients.group_id')
                    ->where('client_groups.uses_group_discount', true)
                    ->where('client_groups.discount', '>', 0))
                ->update([
                    'discount' => $rate,
                    'uses_channel_discount' => false,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            if (! Schema::hasColumn('channels', 'discount')) {
                $table->decimal('discount', 5, 4)->default(0)->after('name_en');
            }
        });

        // ⚠️ النِسَب اللي اتنقلت للعملاء **مابترجعش**. مفيش طريقة نعرف
        // بيها مين كان خصمه من القناة ومين كان خصمه خاص من الأصل.
    }
};
