<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ═══ موديول الأونلاين (٣/٩/٢٠٢٦) — أوردرات شوبيفاي ═══
 *
 * الفلو: سينك من شوبيفاي ← تأكيد بالتليفون ← أمر تجهيز من مخزن
 * الأونلاين (FEFO حقيقي) ← فاتورة بباركود ← جاهز للشحن ← بيك اب
 * يومي بمندوب أونلاين ← تحصيل/مرتجع ← حسابات.
 *
 * ⚠️ كل خطوة محروسة — السيرفر اللايف بيترفع بالإيد مش جيت.
 * ⚠️ الأعمدة الزمنية dateTime مش timestamp (عقيدة التايم زون —
 *    فخ ON UPDATE CURRENT_TIMESTAMP الموثق في promax-system).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ═══ مناديب الأونلاين — جدول مستقل عن users عن قصد ═══
        //
        // قرار المالك ٣/٩: «مناديب أونلاين هنسميها ومتظهرش غير في
        // الصفحة دي بس». لو كانوا users برول موجود كانوا هيظهروا في
        // كل قوايم الميدان (FIELD_ROLES) والتصفيات والتتبع. جدول
        // أسماء بسيط = صفر تسريب لباقي السيستم.
        if (! Schema::hasTable('online_couriers')) {
            Schema::create('online_couriers', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120);
                $table->string('phone', 30)->nullable();
                $table->boolean('active')->default(true);
                $table->timestamps();
            });
        }

        // ═══ شيتات البيك اب اليومية ═══
        if (! Schema::hasTable('online_pickups')) {
            Schema::create('online_pickups', function (Blueprint $table) {
                $table->id();
                $table->string('number', 30)->unique();          // PU-1001
                $table->date('date');
                $table->foreignId('courier_id')->nullable()
                    ->constrained('online_couriers')->nullOnDelete();
                $table->foreignId('created_by')->nullable()
                    ->constrained('users')->nullOnDelete();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index('date');
            });
        }

        // ═══ الأوردرات نفسها — مرآة أوردر شوبيفاي + حالته عندنا ═══
        if (! Schema::hasTable('online_orders')) {
            Schema::create('online_orders', function (Blueprint $table) {
                $table->id();
                // id بتاع شوبيفاي — مفتاح السينك: الموجود مايتجابش تاني
                $table->unsignedBigInteger('shopify_id')->unique();
                // order_number بتاع شوبيفاي (1234) — الباركود = pro1234
                $table->string('number', 30);
                $table->string('customer_name', 190)->nullable();
                $table->string('phone', 40)->nullable();
                $table->text('address')->nullable();
                $table->string('area', 150)->nullable();          // المنطقة/المدينة
                $table->unsignedInteger('items_count')->default(0);
                $table->decimal('subtotal', 14, 2)->default(0);   // قيمة البضاعة
                $table->decimal('shipping', 10, 2)->default(0);   // الشحن من شوبيفاي
                $table->decimal('total', 14, 2)->default(0);      // الإجمالي
                // تكلفة البضاعة — سنابشوت وقت التجهيز (لصفحة الحسابات)
                $table->decimal('cost_total', 14, 2)->default(0);
                // اللي اتحصّل فعلاً — بيقلل باقي البيك اب لحد التصفية
                $table->decimal('collected_total', 14, 2)->default(0);

                // new / postponed / preparing / ready / shipped /
                // returned / completed / cancelled
                $table->string('status', 20)->default('new');
                $table->date('postponed_to')->nullable();
                $table->string('cancel_reason', 250)->nullable();

                $table->foreignId('pick_order_id')->nullable()
                    ->constrained('pick_orders')->nullOnDelete();
                $table->foreignId('pickup_id')->nullable()
                    ->constrained('online_pickups')->nullOnDelete();
                $table->foreignId('confirmed_by')->nullable()
                    ->constrained('users')->nullOnDelete();

                // ⚠️ dateTime مش timestamp — عقيدة التايم زون
                $table->dateTime('ordered_at')->nullable();       // وقت الأوردر في شوبيفاي
                $table->dateTime('confirmed_at')->nullable();
                $table->dateTime('ready_at')->nullable();
                $table->dateTime('shipped_at')->nullable();
                $table->dateTime('collected_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index('status');
                $table->index('number');
            });
        }

        // ═══ بنود الأوردر ═══
        if (! Schema::hasTable('online_order_items')) {
            Schema::create('online_order_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('online_order_id')
                    ->constrained('online_orders')->cascadeOnDelete();
                $table->unsignedBigInteger('shopify_line_id')->nullable();
                $table->unsignedBigInteger('shopify_variant_id')->nullable();
                $table->string('sku', 100)->nullable();
                $table->string('title', 250);
                // بيتربط بمنتج السيستم عبر shopify_product_links أو الـSKU —
                // لو فاضي الأوردر مايتأكدش لحد ما الربط يتعمل
                $table->foreignId('product_id')->nullable()
                    ->constrained('products')->nullOnDelete();
                $table->unsignedInteger('qty')->default(1);
                $table->decimal('price', 10, 2)->default(0);
                $table->decimal('total', 14, 2)->default(0);
                $table->timestamps();
            });
        }

        // ═══ ربط منتجات شوبيفاي بمنتجات السيستم ═══
        if (! Schema::hasTable('shopify_product_links')) {
            Schema::create('shopify_product_links', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('shopify_variant_id')->unique();
                $table->unsignedBigInteger('shopify_product_id');
                $table->string('title', 250);
                $table->string('variant_title', 190)->nullable();
                $table->string('sku', 100)->nullable();
                $table->string('image', 500)->nullable();
                $table->foreignId('product_id')->nullable()
                    ->constrained('products')->nullOnDelete();
                // آخر مرة الـSKU اتكتب في شوبيفاي بنجاح
                $table->dateTime('sku_pushed_at')->nullable();
                $table->timestamps();

                $table->index('shopify_product_id');
                $table->index('sku');
            });
        }
    }

    public function down(): void
    {
        // الترتيب عكس الإنشاء — الـFKات الأول
        Schema::dropIfExists('shopify_product_links');
        Schema::dropIfExists('online_order_items');
        Schema::dropIfExists('online_orders');
        Schema::dropIfExists('online_pickups');
        Schema::dropIfExists('online_couriers');
    }
};
