<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * التصنيع (الإغلاق ١٩/١٠، الخط ٦ — spec §3.5): مواصفات المنتج، قوايم
 * المواد (BOM) بإصدارات، أوامر الإنتاج وسطورها، وحركات الصرف للأمر.
 *
 * ⚠️ كل الأعمدة الزمنية `dateTime()` مش `timestamp()` (promax-system §4)،
 * والفلوس `decimal(14,2)` والكميات `decimal(14,3)` وكميات الـBOM `decimal(14,4)`
 * والنسب `decimal(7,2)`. كل جدول محروس بـ`hasTable` — اللايف مش ريبو جيت.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ═══ مواصفات المنتج — إصدار لكل تعديل بعد الاعتماد ═══
        if (! Schema::hasTable('product_specs')) {
            Schema::create('product_specs', function (Blueprint $t) {
                $t->id();
                $t->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $t->unsignedInteger('version');
                $t->string('status', 20)->default('draft');   // draft|under_review|approved|archived
                $t->string('attachment_path')->nullable();
                $t->string('attachment_name')->nullable();
                $t->decimal('net_weight_g', 10, 3)->nullable();
                $t->unsignedInteger('shelf_life_days')->nullable();
                $t->json('nutrition')->nullable();   // لكل ١٠٠ جم
                $t->json('qc_limits')->nullable();   // [{param, min, max, unit}]
                $t->text('notes')->nullable();
                $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $t->dateTime('submitted_at')->nullable();
                $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $t->dateTime('approved_at')->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
                $t->unique(['product_id', 'version']);
                $t->index('status');
            });
        }

        // ═══ قايمة المواد — إصدار معتمد واحد لكل منتج ═══
        if (! Schema::hasTable('boms')) {
            Schema::create('boms', function (Blueprint $t) {
                $t->id();
                $t->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $t->unsignedInteger('version');
                $t->string('status', 20)->default('draft');   // draft|approved|archived
                $t->decimal('waste_pct', 7, 2)->default(0);
                $t->text('notes')->nullable();
                $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $t->dateTime('approved_at')->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
                $t->unique(['product_id', 'version']);
                $t->index('status');
            });
        }

        if (! Schema::hasTable('bom_lines')) {
            Schema::create('bom_lines', function (Blueprint $t) {
                $t->id();
                $t->foreignId('bom_id')->constrained('boms')->cascadeOnDelete();
                $t->foreignId('product_id')->constrained('products');
                $t->decimal('qty', 14, 4);
                $t->string('basis', 10)->default('per_unit');   // per_unit|per_batch
                $t->unsignedInteger('sort')->default(0);
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
            });
        }

        // ═══ أمر الإنتاج ═══
        if (! Schema::hasTable('production_orders')) {
            Schema::create('production_orders', function (Blueprint $t) {
                $t->id();
                $t->string('number', 30)->unique();
                $t->foreignId('product_id')->constrained('products');
                $t->foreignId('bom_id')->constrained('boms');
                $t->foreignId('line_id')->constrained('cost_centers');
                // مخزن التام (`warehouse_id`) ومخزن الخامات (`raw_warehouse_id`)
                $t->foreignId('warehouse_id')->constrained('warehouses');
                $t->foreignId('raw_warehouse_id')->constrained('warehouses');
                $t->string('batch_no', 60);
                $t->unsignedInteger('planned_qty');
                $t->unsignedInteger('output_qty')->nullable();
                $t->string('status', 20)->default('planned');   // planned|in_progress|finished|closed|cancelled
                $t->date('planned_on');
                $t->dateTime('started_at')->nullable();
                $t->dateTime('finished_at')->nullable();
                $t->dateTime('closed_at')->nullable();
                $t->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
                $t->foreignId('finished_by')->nullable()->constrained('users')->nullOnDelete();
                $t->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
                $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $t->date('produced_on')->nullable();
                $t->date('expires_on')->nullable();
                $t->string('expiry_source', 10)->nullable();   // spec|product
                $t->foreignId('fg_batch_id')->nullable()->constrained('batches')->nullOnDelete();
                $t->decimal('material_cost', 14, 2)->default(0);
                $t->decimal('std_material_cost', 14, 2)->default(0);
                $t->decimal('overhead_cost', 14, 2)->default(0);
                $t->decimal('total_cost', 14, 2)->default(0);
                $t->decimal('unit_cost', 14, 4)->default(0);
                $t->decimal('hours', 8, 2)->nullable();
                $t->string('overhead_method', 20)->nullable();
                $t->string('overhead_flag', 30)->nullable();
                $t->decimal('waste_pct', 7, 2)->nullable();
                $t->decimal('yield_pct', 7, 2)->nullable();
                $t->string('qc_status', 20)->nullable();   // quarantine|released|rejected
                $t->text('qc_note')->nullable();
                $t->json('qc_results')->nullable();
                $t->foreignId('qc_by')->nullable()->constrained('users')->nullOnDelete();
                $t->dateTime('qc_at')->nullable();
                $t->dateTime('shortage_requested_at')->nullable();
                // ⚠️ من غير FK — جدول طلبات الشراء بتاع الخط ٥
                $t->unsignedBigInteger('purchase_request_id')->nullable();
                $t->text('notes')->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
                $t->index(['line_id', 'status']);
                $t->index(['product_id', 'batch_no']);
                $t->index('planned_on');
                $t->index('qc_status');
            });
        }

        // ═══ سطور الأمر — لقطة من الـBOM وقت الإنشاء + الفعلي عند القفل ═══
        if (! Schema::hasTable('production_order_lines')) {
            Schema::create('production_order_lines', function (Blueprint $t) {
                $t->id();
                $t->foreignId('production_order_id')->constrained('production_orders')->cascadeOnDelete();
                $t->foreignId('product_id')->constrained('products');
                $t->string('basis', 10)->default('per_unit');
                $t->decimal('bom_qty', 14, 4);
                $t->decimal('std_qty', 14, 3);
                $t->decimal('std_qty_actual', 14, 3)->nullable();
                $t->decimal('base_qty_actual', 14, 3)->nullable();
                $t->decimal('actual_qty', 14, 3)->nullable();
                $t->decimal('actual_cost', 14, 2)->nullable();
                $t->decimal('std_cost', 14, 2)->nullable();
                $t->decimal('variance_qty', 14, 3)->nullable();
                $t->decimal('variance_value', 14, 2)->nullable();
                $t->decimal('waste_pct', 7, 2)->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
            });
        }

        // ═══ حركات الصرف للأمر — صف لكل باتش خامة (صرف/إضافي/مرتجع) ═══
        if (! Schema::hasTable('production_issues')) {
            Schema::create('production_issues', function (Blueprint $t) {
                $t->id();
                $t->foreignId('production_order_id')->constrained('production_orders')->cascadeOnDelete();
                $t->foreignId('product_id')->constrained('products');
                $t->foreignId('batch_id')->constrained('batches');
                $t->string('kind', 10)->default('issue');   // issue|extra|return
                $t->decimal('qty', 14, 3);   // موجب دايماً — المرتجع موجب بنوع return
                $t->decimal('unit_cost', 14, 4)->default(0);
                $t->decimal('value', 14, 2)->default(0);
                $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $t->dateTime('issued_at')->nullable();
                $t->text('note')->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
                $t->index('batch_id');
                $t->index(['production_order_id', 'product_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('production_issues');
        Schema::dropIfExists('production_order_lines');
        Schema::dropIfExists('production_orders');
        Schema::dropIfExists('bom_lines');
        Schema::dropIfExists('boms');
        Schema::dropIfExists('product_specs');
    }
};
