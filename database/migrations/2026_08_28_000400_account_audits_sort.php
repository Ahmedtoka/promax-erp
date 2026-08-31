<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ترتيب يدوي لليستة المراجعة (٢٨ أغسطس ٢٠٢٦ — طلب المالك).
 *
 * «عاوز أرتب الليست دي بمزاجي، همشي عليهم واحد واحد وأكتب ١ ٢ ٣
 * وأعمل سبمت من تحت كله يترتب ويفضل متسجل».
 *
 * ⚠️ الرقم **مش فريد ومش متسلسل بالضرورة** — المالك ممكن يكتب
 * ١ و٥ و١٠ ويسيب الباقي فاضي. اللي ليه رقم بيطلع فوق بالترتيب،
 * واللي مالوش بيكمّل بالترتيب الافتراضي (أكبر عدد فروع / أكبر
 * رصيد). أي محاولة نفرض تسلسل هنا هتخلي الشاشة تعيد ترقيم شغله.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('account_audits')) {
            return;
        }

        if (! Schema::hasColumn('account_audits', 'sort')) {
            Schema::table('account_audits', function (Blueprint $table) {
                $table->unsignedInteger('sort')->nullable()->after('entity_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('account_audits') && Schema::hasColumn('account_audits', 'sort')) {
            Schema::table('account_audits', function (Blueprint $table) {
                $table->dropColumn('sort');
            });
        }
    }
};
