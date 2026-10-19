<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * عقود الموردين (خطة القفل ١٩/١٠، لين ٥) — سعر ثابت لكل صنف في فترة سريان.
 * ⚠️ الاسم `supplier_contracts` مش `contracts` (دي عقود العملاء).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('supplier_contracts')) {
            Schema::create('supplier_contracts', function (Blueprint $t) {
                $t->id();
                $t->string('number')->unique();
                $t->foreignId('supplier_id')->constrained('suppliers');
                $t->date('starts_on');
                $t->date('ends_on');
                $t->string('attachment_path')->nullable();
                $t->text('notes')->nullable();
                $t->boolean('active')->default(true);
                $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                // ⚠️ dateTime مش timestamp — promax-system §4
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();

                $t->index(['supplier_id', 'starts_on', 'ends_on']);
            });
        }

        if (! Schema::hasTable('supplier_contract_items')) {
            Schema::create('supplier_contract_items', function (Blueprint $t) {
                $t->id();
                $t->foreignId('supplier_contract_id')->constrained('supplier_contracts')->cascadeOnDelete();
                $t->foreignId('product_id')->constrained('products');
                $t->decimal('unit_price', 10, 2);
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();

                $t->unique(['supplier_contract_id', 'product_id'], 'sci_contract_product_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_contract_items');
        Schema::dropIfExists('supplier_contracts');
    }
};
