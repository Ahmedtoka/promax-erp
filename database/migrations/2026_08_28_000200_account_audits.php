<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مراجعة حسابات العملاء والسلاسل (٢٨ أغسطس ٢٠٢٦ — طلب المالك).
 *
 * سنة ونص شغل وفيه فوضى في الحسابات: مين فيهم أصلاً له حساب عندنا؟
 * وكام رصيده **عندهم هم**؟ وفيه كشف حساب موصول ولا لأ؟ الصفحة
 * بتمشي كيان كيان وتسجّل الإجابة، والجدول ده هو دفتر المراجعة.
 *
 * ⚠️ **الجدول ده مالوش أي أثر على الأرقام الحقيقية.** `balance`
 * هنا هو **رصيدهم اللي العميل قايله**، مش رصيد السيستم — الرصيد
 * الرسمي بيتحسب من `transactions` وبس (عقيدة الأرقام). القيمتين
 * بتتعرضوا جنب بعض عشان الفرق يبان، والفرق ده هو شغل المراجعة.
 *
 * ⚠️ **مفتاح الكيان نص + رقم** (`entity_type`/`entity_id`) بدل
 * عمودين FK: السلسلة والعميل جدولين مختلفين والصف واحد لكل كيان،
 * والفهرس الفريد بيمنع صفين لنفس الكيان.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('account_audits')) {
            Schema::create('account_audits', function (Blueprint $table) {
                $table->id();

                // 'group' = سلسلة · 'client' = عميل فردي
                $table->string('entity_type', 10);
                $table->unsignedBigInteger('entity_id');

                // null = لسه ماتحددش · true = موجود · false = مش موجود
                $table->boolean('has_account')->nullable();
                // رصيده **عندهم** — للمقارنة برصيدنا، مش مصدر حقيقة
                $table->decimal('their_balance', 14, 2)->nullable();

                $table->boolean('has_statement')->nullable();
                $table->string('statement_path')->nullable();
                $table->string('statement_name', 190)->nullable();

                $table->string('note', 300)->nullable();

                $table->foreignId('reviewed_by')->nullable()
                    ->constrained('users')->nullOnDelete();
                // ⚠️ `dateTime` مش `timestamp` — فخ ON UPDATE (٢٣/٨)
                $table->dateTime('reviewed_at')->nullable();

                $table->timestamps();

                $table->unique(['entity_type', 'entity_id'], 'account_audits_entity_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('account_audits');
    }
};
