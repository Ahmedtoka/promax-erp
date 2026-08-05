<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تصفية المناديب (2026-08-06) — قفلة الحسابات اليومية:
 *
 * المحاسب بيقفل مع كل مندوب: فواتيره الكاش (النقدية اللي معاه) ناقص
 * مرتجعات الكاش اللي ردّها = **المتوقع**. بيستلم منه مبلغ، والفرق
 * بيترحّل كرصيد: موجب = المندوب عليه فلوس (مدين)، سالب = ليه (دائن).
 * كل تصفية بتغطي الفترة من آخر تصفية لحد لحظتها — مفيش فاتورة
 * بتتحسب مرتين ومفيش فاتورة بتضيع.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rep_settlements')) {
            return;
        }

        Schema::create('rep_settlements', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();                       // RS-1001
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();   // المندوب
            // نافذة الفترة — من آخر تصفية (أو البداية) لحد لحظة القفل
            $table->timestamp('from_at')->nullable();
            $table->timestamp('to_at');
            $table->unsignedInteger('invoices_count')->default(0);
            $table->decimal('cash_sales', 14, 2)->default(0);     // فواتير كاش (بالإجمالي)
            $table->decimal('credit_sales', 14, 2)->default(0);   // آجل — للعرض والمطابقة بس
            $table->decimal('cash_refunds', 14, 2)->default(0);   // مرتجعات كاش ردّها نقدي
            $table->decimal('expected', 14, 2)->default(0);       // النقدية المفروض يسلّمها
            $table->decimal('prev_balance', 14, 2)->default(0);   // رصيد مترحّل من اللي قبله
            $table->decimal('received', 14, 2)->default(0);       // اللي المحاسب استلمه فعلاً
            // موجب = عليه (مدين) · سالب = ليه (دائن) — بيترحّل للتصفية الجاية
            $table->decimal('balance', 14, 2)->default(0);
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'to_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rep_settlements');
    }
};
