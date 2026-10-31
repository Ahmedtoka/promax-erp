<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الشركة الأم (٣١/١٠/٢٠٢٦) — طبقة تجميع فوق السلسلة:
 * Mother Company ← السلسلة ← العميل، زي شيت أرصدة العملاء.
 *
 * ⚠️ عرض وتجميع بس: مالهاش عقد ولا خصم ولا حساب. الأسعار والأرصدة
 * فاضلة على مستوى الفرع والسلسلة زي ما هي.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mother_companies')) {
            Schema::create('mother_companies', function (Blueprint $t) {
                $t->id();
                $t->string('code', 40)->unique();
                $t->string('name');
                $t->string('name_en')->nullable();
                $t->text('notes')->nullable();
                $t->boolean('active')->default(true);
                $t->timestamps();
            });
        }

        if (! Schema::hasColumn('client_groups', 'mother_company_id')) {
            Schema::table('client_groups', function (Blueprint $t) {
                // مسح الشركة مايمسحش سلاسلها — بترجع من غير شركة أم
                $t->foreignId('mother_company_id')->nullable()->after('channel_id')
                    ->constrained('mother_companies')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('client_groups', 'mother_company_id')) {
            Schema::table('client_groups', function (Blueprint $t) {
                $t->dropConstrainedForeignId('mother_company_id');
            });
        }

        Schema::dropIfExists('mother_companies');
    }
};
