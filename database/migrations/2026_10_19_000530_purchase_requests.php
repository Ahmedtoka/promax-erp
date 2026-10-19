<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * طلبات الشراء وعروض أسعار الموردين (إغلاق ١٩/١٠، لين ٥، D11):
 * طلب واحد = صنف واحد، والمقارنة بين الموردين على السطر ده.
 * ⚠️ الاسم `purchase_requests` مش `purchase_orders_*` — الأخيرة طلبيات العملاء.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('purchase_requests')) {
            Schema::create('purchase_requests', function (Blueprint $t) {
                $t->id();
                $t->string('number', 20)->unique();
                $t->foreignId('product_id')->constrained('products');
                $t->unsignedInteger('qty');
                $t->date('needed_by')->nullable();
                $t->foreignId('requested_by')->constrained('users');
                $t->foreignId('cost_center_id')->nullable()->constrained('cost_centers')->nullOnDelete();
                $t->string('status', 20)->default('open');
                $t->text('notes')->nullable();
                $t->unsignedBigInteger('supplier_order_id')->nullable();
                $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $t->dateTime('approved_at')->nullable();
                $t->timestamps();
                $t->index('status');
            });
        }

        if (! Schema::hasTable('purchase_quotes')) {
            Schema::create('purchase_quotes', function (Blueprint $t) {
                $t->id();
                $t->foreignId('purchase_request_id')->constrained('purchase_requests')->cascadeOnDelete();
                $t->foreignId('supplier_id')->constrained('suppliers');
                $t->decimal('unit_price', 10, 2);
                $t->unsignedSmallInteger('lead_days')->nullable();
                $t->string('payment_terms', 120)->nullable();
                $t->string('attachment_path')->nullable();
                $t->text('notes')->nullable();
                $t->string('status', 20)->default('pending');
                $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
                $t->unique(['purchase_request_id', 'supplier_id']);
            });
        }

        Schema::table('supplier_orders', function (Blueprint $t) {
            if (! Schema::hasColumn('supplier_orders', 'purchase_request_id')) {
                $t->foreignId('purchase_request_id')->nullable()->constrained('purchase_requests')->nullOnDelete();
            }
            if (! Schema::hasColumn('supplier_orders', 'purchase_quote_id')) {
                $t->foreignId('purchase_quote_id')->nullable()->constrained('purchase_quotes')->nullOnDelete();
            }
            if (! Schema::hasColumn('supplier_orders', 'cost_center_id')) {
                $t->foreignId('cost_center_id')->nullable()->constrained('cost_centers')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('supplier_orders', function (Blueprint $t) {
            foreach (['purchase_request_id', 'purchase_quote_id'] as $fk) {
                if (Schema::hasColumn('supplier_orders', $fk)) {
                    $t->dropConstrainedForeignId($fk);
                }
            }
        });
        Schema::dropIfExists('purchase_quotes');
        Schema::dropIfExists('purchase_requests');
    }
};
