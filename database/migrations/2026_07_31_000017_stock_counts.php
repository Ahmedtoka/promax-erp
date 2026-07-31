<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════
 * الجرد الفعلي وتسوية الفروق
 * ═══════════════════════════════════════════════════════════════
 *
 * الجرد بيتعمل **على مستوى الباتش** مش الصنف، لأن ده اللي المخزون
 * متسجّل بيه فعلاً (FEFO + تواريخ صلاحية). جرد بالصنف بس بيقول
 * «ناقص 40 علبة» من غير ما يعرف مين الباتش اللي ناقص، وساعتها
 * التسوية بتخصم من باتش عشوائي وتاريخ الصلاحية بيبوظ.
 *
 * ⚠️ الأرقام المتخزّنة **تلاتة مش اتنين**:
 *   - `expected_qty` — رصيد السيستم **ساعة فتح الجرد** (ورقة العد)
 *   - `system_qty`   — رصيد السيستم **ساعة الاعتماد**
 *   - `counted_qty`  — اللي الناس عدّته فعلاً
 *
 * الفرق بين الأولانيين معناه إن بضاعة اتحركت والعد شغال. من غير
 * تسجيل الاتنين، الحركة دي بتتبلع في الفرق وبتتحسب عجز وهي مش عجز.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stock_counts')) {
            Schema::create('stock_counts', function (Blueprint $table) {
                $table->id();
                $table->string('number', 30)->unique();
                $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();

                // draft = بيتجهّز · counting = ورقة العد اتطبعت والعد شغال
                // approved = اتعتمد واتحرّك المخزون · cancelled = اتلغى
                $table->string('status', 20)->default('draft');

                $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();

                $table->date('count_date');
                $table->timestamp('approved_at')->nullable();

                // ملخص بيتحسب عند الاعتماد — عشان القوايم متبقاش بتجمّع
                // السطور في كل صف (N+1 على شاشة فيها 50 جرد)
                $table->integer('lines')->default(0);
                $table->integer('diff_lines')->default(0);
                $table->integer('qty_diff')->default(0);
                $table->decimal('value_diff', 14, 2)->default(0);

                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['warehouse_id', 'status']);
            });
        }

        if (! Schema::hasTable('stock_count_items')) {
            Schema::create('stock_count_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('stock_count_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_id')->constrained()->cascadeOnDelete();
                $table->foreignId('batch_id')->nullable()->constrained()->nullOnDelete();

                $table->integer('expected_qty')->default(0);   // ساعة الفتح
                $table->integer('system_qty')->default(0);     // ساعة الاعتماد
                $table->integer('counted_qty')->nullable();    // null = لسه مااتعدّش

                $table->integer('difference')->default(0);
                $table->decimal('cost', 10, 2)->default(0);
                $table->decimal('value_diff', 14, 2)->default(0);

                $table->string('reason', 40)->nullable();      // سبب الفرق
                $table->text('notes')->nullable();
                $table->timestamps();

                // ⚠️ الباتش ممكن يكون NULL (صنف من غير باتشات)، وMySQL
                // بيعتبر NULL مختلفة عن بعضها في الـ UNIQUE — فالمفتاح
                // ده بيمنع تكرار الباتش المحدد بس، والصنف بلا باتش
                // بيتمنع تكراره في كود المولّد.
                $table->unique(['stock_count_id', 'batch_id'], 'sc_item_batch_unique');
                $table->index(['stock_count_id', 'product_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_count_items');
        Schema::dropIfExists('stock_counts');
    }
};
