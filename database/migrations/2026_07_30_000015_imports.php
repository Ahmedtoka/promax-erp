<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سجل الاستيراد — كل شيت اترفع، مين رفعه، وإيه اللي حصل.
 *
 * ⚠️ السجل ده مش رفاهية. استيراد داتا تأسيسية بيغيّر أرقام كل الشاشات،
 * ولما رقم يطلع غريب بعد شهر لازم نعرف: ده جه من أنهي شيت، اترفع إمتى،
 * وكام صف اتقبل وكام اترفض. من غير السجل ده الاستيراد صندوق أسود.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('imports')) {
            Schema::create('imports', function (Blueprint $table) {
                $table->id();
                $table->string('kind', 30);              // products / clients / team / stock
                $table->string('file_name', 190);
                $table->string('file_path', 190)->nullable();
                // اسم الورقة جوه الملف — الشيتات الحقيقية بتيجي بأكتر من ورقة
                $table->string('sheet', 190)->nullable();

                // pending = اترفع ومستني تأكيد | applied = اتنفذ | failed = وقع
                $table->string('status', 12)->default('pending');

                $table->unsignedInteger('rows_total')->default(0);
                $table->unsignedInteger('rows_ok')->default(0);
                $table->unsignedInteger('rows_failed')->default(0);

                // أول الأخطاء — مش كلها، عشان مانملاش الجدول
                $table->json('errors')->nullable();
                $table->json('summary')->nullable();

                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->timestamp('applied_at')->nullable();
                $table->timestamps();

                $table->index(['kind', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('imports');
    }
};
