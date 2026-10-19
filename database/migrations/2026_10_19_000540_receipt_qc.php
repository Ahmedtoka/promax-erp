<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * فحص الجودة السريع عند الاستلام + مرتجعات الموردين (إغلاق ١٩/١٠، لين ٥، D6).
 * السطر الراسب مش بيبقى `received_qty` ومش بيتفوتر، وبيتسجل على مرتجع واحد للإذن.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('supplier_order_items', 'rejected_qty')) {
            Schema::table('supplier_order_items', function (Blueprint $t) {
                $t->integer('rejected_qty')->default(0);
            });
        }

        if (! Schema::hasTable('supplier_returns')) {
            Schema::create('supplier_returns', function (Blueprint $t) {
                $t->id();
                $t->string('number', 20)->unique();
                $t->foreignId('supplier_id')->constrained('suppliers');
                $t->foreignId('supplier_order_id')->constrained('supplier_orders');
                $t->foreignId('goods_receipt_id')->constrained('goods_receipts');
                $t->date('returned_on');
                $t->decimal('total', 14, 2)->default(0);
                $t->text('notes')->nullable();
                $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
                $t->index('returned_on');
            });
        }

        if (! Schema::hasTable('supplier_return_items')) {
            Schema::create('supplier_return_items', function (Blueprint $t) {
                $t->id();
                $t->foreignId('supplier_return_id')->constrained('supplier_returns')->cascadeOnDelete();
                $t->foreignId('supplier_order_item_id')->constrained('supplier_order_items');
                $t->foreignId('product_id')->constrained('products');
                $t->integer('qty');
                $t->decimal('unit_cost', 10, 2);
                $t->string('note', 500)->nullable();
                $t->string('photo_path')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('receipt_inspections')) {
            Schema::create('receipt_inspections', function (Blueprint $t) {
                $t->id();
                $t->foreignId('goods_receipt_id')->constrained('goods_receipts')->cascadeOnDelete();
                $t->foreignId('supplier_order_item_id')->constrained('supplier_order_items');
                $t->foreignId('product_id')->constrained('products');
                $t->integer('qty');
                $t->string('result', 10);
                $t->string('note', 500)->nullable();
                $t->string('photo_path')->nullable();
                $t->foreignId('batch_id')->nullable()->constrained('batches')->nullOnDelete();
                $t->foreignId('supplier_return_id')->nullable()->constrained('supplier_returns')->nullOnDelete();
                $t->foreignId('inspected_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_inspections');
        Schema::dropIfExists('supplier_return_items');
        Schema::dropIfExists('supplier_returns');
        if (Schema::hasColumn('supplier_order_items', 'rejected_qty')) {
            Schema::table('supplier_order_items', fn (Blueprint $t) => $t->dropColumn('rejected_qty'));
        }
    }
};
