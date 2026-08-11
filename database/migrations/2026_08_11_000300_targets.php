<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * التارجيت السنوي الهرمي (١١ أغسطس ٢٠٢٦) — قرار المالك:
 * الأدمن بيحط تارجيت الشركة للسنة ويوزّعه على مديري القنوات (بالنسبة
 * أو بالمبلغ)، وكل مدير بيوزّع على مناديبه، وتارجيت المندوب بيتوزّع
 * على عملائه وسلاسله. كل مستوى بينقسم ١٢ شهر، وتعديل شهر بيوازن
 * الفرق على الشهور اللي بعده بس — الماضي والإجمالي السنوي ثابتين.
 *
 * `targets`: عقدة لكل مستوى — company / manager / rep / client —
 * مربوطة بأبوها (شجرة). `target_months`: القسمة الشهرية +
 * `manual_actual` (المحقق اليدوي للشهور التاريخية — لما يتكتب بيغلب
 * المحسوب من القيود؛ العمود عام لأي عقدة والاستخدام الحالي شركة بس).
 *
 * ⚠️ غير جدول `rep_targets` (تارجت الحوافز الشهري) — الاتنين عايشين.
 * ⚠️ محروسة — السيرفر اللايف بيترفع بالإيد مش جيت.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('targets')) {
            Schema::create('targets', function (Blueprint $table) {
                $table->id();
                $table->smallInteger('year');
                // company / manager / rep / client
                $table->string('kind', 10);
                // المدير أو المندوب صاحب العقدة (للنوعين manager/rep)
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                // العميل (للنوع client بس)
                $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
                // الأب في الشجرة — الأبناء بيتمسحوا مع الأب
                // (الـFK بيعمل إندكس على parent_id لوحده تلقائياً)
                $table->foreignId('parent_id')->nullable()->constrained('targets')->cascadeOnDelete();
                $table->decimal('amount', 14, 2)->default(0);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['year', 'kind']);
            });
        }

        if (! Schema::hasTable('target_months')) {
            Schema::create('target_months', function (Blueprint $table) {
                $table->id();
                $table->foreignId('target_id')->constrained('targets')->cascadeOnDelete();
                $table->tinyInteger('month');
                $table->decimal('amount', 14, 2)->default(0);
                // المحقق اليدوي — nullable عن قصد: null = اتحسب من القيود
                $table->decimal('manual_actual', 14, 2)->nullable();
                $table->timestamps();

                $table->unique(['target_id', 'month']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('target_months');
        Schema::dropIfExists('targets');
    }
};
