<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مراجعة إعادة التشغيل (٥/١١/٢٠٢٦) — الفريق بيراجع السيستم على الورق:
 *
 *   ١. بيانات كل عميل (الاسم · العنوان · السلسلة · الأم · السجل · القايمة)
 *   ٢. أسعار الفواتير وأوامر التوريد + مطابقة كل شهر + رصيد أول المدة
 *   ٣. تصفير المخزن والمناديب وإدخال الأرصدة بالباتش
 *
 * `review_checks`: علامة لكل بند على كل كيان — مين علّم وإمتى وملاحظته.
 * `review_stock_moves`: كل تصفير/إدخال رصيد بالباتش من شاشة المراجعة —
 * الكمية قبل التصفير فاضلة هنا للأثر (والتراجع لو لزم).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('review_checks')) {
            Schema::create('review_checks', function (Blueprint $t) {
                $t->id();
                $t->string('entity_type', 16);   // client | group | warehouse | rep
                $t->unsignedBigInteger('entity_id');
                $t->string('phase', 12);          // data | money | stock
                $t->string('item', 24);           // name | address | … | m:2026-08 | zeroed | …
                $t->string('status', 8);          // ok | issue
                $t->text('note')->nullable();
                // أرقام الورق لبنود الشهور — الفرق بيتحسب ساعة العرض
                $t->decimal('paper_net', 14, 2)->nullable();
                $t->decimal('paper_balance', 14, 2)->nullable();
                // رقم السيستم ساعة العلامة — لو اتغير بعدها الشاشة بتقول
                $t->decimal('system_net', 14, 2)->nullable();
                $t->decimal('system_balance', 14, 2)->nullable();
                $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();

                $t->unique(['entity_type', 'entity_id', 'item'], 'review_checks_entity_item');
                $t->index(['phase', 'updated_at']);
                $t->index(['user_id', 'updated_at']);
            });
        }

        if (! Schema::hasTable('review_stock_moves')) {
            Schema::create('review_stock_moves', function (Blueprint $t) {
                $t->id();
                $t->string('kind', 12);           // zero | opening
                $t->foreignId('warehouse_id')->constrained('warehouses');
                $t->foreignId('product_id')->constrained('products');
                $t->foreignId('batch_id')->nullable()->constrained('batches')->nullOnDelete();
                $t->string('batch_no', 60)->nullable();
                $t->date('expires_on')->nullable();
                $t->integer('qty');               // zero: الكمية اللي اتشالت · opening: اللي اتضافت
                $t->decimal('cost', 12, 4)->nullable();
                $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();

                $t->index(['warehouse_id', 'kind']);
            });
        }

        // رصيد أول المدة للسلسلة كرقم واحد (قرار المالك ٥/١١) — الفرع لسه يقدر
        // ياخد رصيده لوحده من صفحته (`erp.clients.opening`)
        Schema::table('client_groups', function (Blueprint $t) {
            if (! Schema::hasColumn('client_groups', 'opening_balance')) {
                $t->decimal('opening_balance', 14, 2)->nullable()->after('docs_type');
                $t->date('opening_as_of')->nullable()->after('opening_balance');
            }
        });
    }

    public function down(): void
    {
        Schema::table('client_groups', function (Blueprint $t) {
            foreach (['opening_balance', 'opening_as_of'] as $col) {
                if (Schema::hasColumn('client_groups', $col)) {
                    $t->dropColumn($col);
                }
            }
        });

        Schema::dropIfExists('review_stock_moves');
        Schema::dropIfExists('review_checks');
    }
};
