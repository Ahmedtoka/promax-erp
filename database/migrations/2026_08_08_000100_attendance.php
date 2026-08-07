<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════
 * الحضور والانصراف — أول موديول في نظام الـHR (2026-08-08)
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **ده مش تشيك إن العميل.** جدول `visits` بيسجّل دخول المندوب
 * **محل عميل**؛ الجدولين دول بيسجّلوا بداية ونهاية **يوم الشغل**
 * نفسه — مالهمش علاقة ببعض خالص. الأسماء متفرقة عن قصد (`shift`
 * مقابل `visit`) عشان محدش يخلط بينهم في كويري ولا في شاشة.
 *
 * **ليه جدولين؟**
 *   • `attendance_punches` = الحقيقة الخام. كل ضغطة زرار سطر
 *     بوقتها ومكانها، ومابيتعدلش أبداً. ده اللي بيرد على «هو قال
 *     إنه حضر 8، السيستم بيقول 10».
 *   • `attendance_days` = المحصلة المحسوبة + قرار المدير. الساعات
 *     بتتحسب من البانشات وبتتخزن هنا عشان الشاشات والتقارير
 *     مايعيدوش الحساب على آلاف الصفوف.
 *
 * ⚠️ **الحالة مش عمود — بتتحسب من آخر بانش** (`in`/`back` = شغال،
 * `break` = بريك، `out` = خلص). عمود حالة منفصل كان هيتعارض مع
 * السجل أول مرة ريكوست يقع في النص، والسجل هو الحقيقة.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('attendance_days')) {
            Schema::create('attendance_days', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->date('date');

                $table->timestamp('first_in_at')->nullable();
                $table->timestamp('last_out_at')->nullable();

                // بالدقايق — الحساب بالدقيقة والعرض بالساعة
                $table->unsignedInteger('worked_minutes')->default(0);
                $table->unsignedInteger('break_minutes')->default(0);

                // عدد مرات الحضور في اليوم (ممكن يحضر وينصرف ويرجع)
                $table->unsignedSmallInteger('sessions')->default(0);

                // open = لسه شغال أو في بريك · closed = قفل بنفسه
                // auto = السيستم قفله بعد منتصف الليل
                $table->string('status', 10)->default('open')->index();

                // ⚠️ **الاعتماد منفصل عن المحسوب** (قرار المالك
                // 2026-08-08): اللي نسي يقفل، المدير بيراجع ويقرر
                // الساعات الحقيقية. `worked_minutes` بيفضل زي ما هو
                // كدليل، و`approved_minutes` هو اللي بيتحاسب عليه.
                $table->unsignedInteger('approved_minutes')->nullable();
                $table->foreignId('approved_by')->nullable()
                    ->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->string('note', 300)->nullable();

                $table->timestamps();

                // ⚠️ يوم واحد لكل موظف — كل الحضور والانصراف في نفس
                // اليوم بيتجمّعوا على نفس الصف
                $table->unique(['user_id', 'date']);
                $table->index(['date', 'status']);
            });
        }

        if (! Schema::hasTable('attendance_punches')) {
            Schema::create('attendance_punches', function (Blueprint $table) {
                $table->id();
                $table->foreignId('attendance_day_id')->constrained()->cascadeOnDelete();
                // ⚠️ متكرر عن قصد — كل الكويريات بتفلتر على الموظف،
                // والـJOIN على `attendance_days` في كل مرة مالوش لازمة
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();

                // in · break · back · out
                $table->string('type', 8)->index();
                $table->timestamp('at');

                // ⚠️ المكان **وقت الضغط** — «حضر من فين» سؤال بيتسأل
                $table->decimal('lat', 10, 7)->nullable();
                $table->decimal('lng', 10, 7)->nullable();

                // السيستم هو اللي حطّها (قفل تلقائي) مش الموظف
                $table->boolean('auto')->default(false);

                $table->timestamps();

                $table->index(['user_id', 'at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_punches');
        Schema::dropIfExists('attendance_days');
    }
};
