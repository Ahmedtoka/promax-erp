<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════
 * طريقة الدفع على العميل + المرتجع من العملاء في العهدة
 * ═══════════════════════════════════════════════════════════════
 *
 * `clients.payment_terms`: كاش ولا آجل — **قرار إدارة مش قرار مندوب**
 * (قرار المالك 2026-08-03). `null` = حسب القناة: كاش فان وجملة كاش،
 * كي أكاونت وأونلاين آجل. والتصنيف `danger` كاش إجباري مهما كانت.
 *
 * `custody_items.returned_in`: المرتجع **من العملاء** — مفصول تماماً
 * عن `returned` (المرتجع للمخزن) وعن المتاح للبيع. المندوب بياخد
 * بضاعة من عميل، بتتحسب عليه كعهدة مرتجعة، ومحدش يبيع منها.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('clients', 'payment_terms')) {
            Schema::table('clients', function (Blueprint $table) {
                // null = حسب القناة — الـ455 عميل الموجودين بيتبعوا
                // قناتهم من غير باكفيل، والأدمن بيثبّت اللي يحب بس
                $table->string('payment_terms', 10)->nullable()->after('category');
            });
        }

        if (! Schema::hasColumn('custody_items', 'returned_in')) {
            Schema::table('custody_items', function (Blueprint $table) {
                $table->integer('returned_in')->default(0)->after('returned');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('clients', 'payment_terms')) {
            Schema::table('clients', fn (Blueprint $t) => $t->dropColumn('payment_terms'));
        }

        if (Schema::hasColumn('custody_items', 'returned_in')) {
            Schema::table('custody_items', fn (Blueprint $t) => $t->dropColumn('returned_in'));
        }
    }
};
