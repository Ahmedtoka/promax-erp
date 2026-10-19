<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سجل صرف الخامات والتعبئة (خطة القفل ١٩/١٠، لين ٥) — كل صف = كمية خرجت
 * من باتش معيّن لمستند معيّن (أمر تصنيع مثلاً، عن طريق for_type/for_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('raw_stock_moves')) {
            return;
        }

        Schema::create('raw_stock_moves', function (Blueprint $t) {
            $t->id();
            $t->foreignId('warehouse_id')->constrained('warehouses');
            $t->foreignId('product_id')->constrained('products');
            $t->foreignId('batch_id')->constrained('batches');
            $t->integer('qty');
            $t->decimal('unit_cost', 12, 4)->default(0);
            $t->string('for_type')->nullable();
            $t->unsignedBigInteger('for_id')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // ⚠️ dateTime مش timestamp — promax-system §4
            $t->dateTime('moved_at');
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();

            $t->index(['for_type', 'for_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raw_stock_moves');
    }
};
