<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════
 * السلسلة مجرد تجميعة — مالهاش خصم ولا مسؤول
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **قرار 2026-08-01:** السلسلة (Circle K، On The Run، بيت الجملة)
 * **مكان بنجمع فيه الفروع تحت اسم واحد** عشان نشوف إجمالياتها — مش
 * كيان تجاري ليه شروطه.
 *
 * كل فرع عميل مستقل: ليه عقده وخصمه ومسؤوله وكشف حسابه. خصم على
 * مستوى السلسلة كله معناه إن فرع اتفق على شروط مختلفة بيتجاهل اتفاقه
 * — والفروع فعلاً بتتفاوض كل واحد لوحده.
 *
 * ⚠️ **الخصم بيتنقل على العملاء قبل ما يتمسح.** العميل اللي كان
 * بياخد خصمه من السلسلة ومالوش خصم خاص ولا عقد، لو مسحنا العمود من
 * غير نقل بيقع على **صفر** — يعني سعر القائمة كامل، والفاتورة الجاية
 * بتطلع أعلى من المتفق عليه والعميل يرفض الاستلام.
 *
 * ده نفس اللي عملناه لخصم القناة في `000025_drop_channel_discount`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('client_groups', 'discount')) {
            return;
        }

        // ═══ 1. نقل الخصم على العملاء اللي مالهمش مصدر تاني ═══
        $groups = DB::table('client_groups')
            ->where('uses_group_discount', true)
            ->where('discount', '>', 0)
            ->get(['id', 'name', 'discount']);

        $moved = 0;

        foreach ($groups as $group) {
            // ⚠️ **العميل اللي عنده خصم خاص مابنلمسوش.** خصمه بيغلب
            // على خصم السلسلة أصلاً في `effectiveDiscount()`، فالكتابة
            // فوقه كانت هتغيّر سعره فعلياً.
            $moved += DB::table('clients')
                ->where('group_id', $group->id)
                ->where(function ($q) {
                    $q->whereNull('discount')->orWhere('discount', '<=', 0);
                })
                // ⚠️ والعميل اللي له عقد كمان: العقد بيغلب على كل حاجة،
                // ونسخ خصم السلسلة عليه بيخلّيه يعيش بعد ما العقد يخلص.
                ->whereNotExists(function ($q) {
                    $q->select(DB::raw(1))
                        ->from('contracts')
                        ->whereColumn('contracts.client_id', 'clients.id')
                        ->where('contracts.active', true)
                        ->where('contracts.discount', '>', 0);
                })
                ->update([
                    'discount' => $group->discount,
                    'uses_channel_discount' => false,
                    'updated_at' => now(),
                ]);
        }

        // ═══ 2. الأعمدة تتشال ═══
        Schema::table('client_groups', function (Blueprint $table) {
            foreach (['discount', 'uses_group_discount'] as $col) {
                if (Schema::hasColumn('client_groups', $col)) {
                    $table->dropColumn($col);
                }
            }

            // ⚠️ **«مسؤول السلسلة» بيتشال كمان.** كل فرع ليه مسؤوله
            // (`clients.manager_id`)، ومسؤول على مستوى السلسلة كان
            // بيخلّي شاشتين يقولوا اسمين مختلفين لنفس الفرع.
            foreach (['contact_name', 'contact_phone'] as $col) {
                if (Schema::hasColumn('client_groups', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('client_groups', function (Blueprint $table) {
            if (! Schema::hasColumn('client_groups', 'discount')) {
                $table->decimal('discount', 5, 4)->default(0)->after('sub_channel');
                $table->boolean('uses_group_discount')->default(false)->after('discount');
            }

            if (! Schema::hasColumn('client_groups', 'contact_name')) {
                $table->string('contact_name')->nullable();
                $table->string('contact_phone', 30)->nullable();
            }
        });
    }
};
