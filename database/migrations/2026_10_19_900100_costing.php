<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * التكاليف (الإغلاق ١٩/١٠، الخط ٩): طبقة التكلفة المشتقة (قيود وسطور)، إقفال
 * شهر التكاليف، أسعار التحويل الداخلي، وأعمدة ربط الإنتاج وطلبات الشراء.
 *
 * ⚠️ كل الأعمدة الزمنية `dateTime()` مش `timestamp()`، والفلوس `decimal(14,2)`
 * وتكلفة الوحدة `decimal(14,4)` والكميات `decimal(14,3)` والنسب `decimal(7,2)`.
 * كل جدول وعمود محروس بـ`hasTable` / `hasColumn`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cost_entries')) {
            Schema::create('cost_entries', function (Blueprint $t) {
                $t->id();
                $t->string('kind', 20);   // sale|return|transfer|production|variance
                $t->string('origin_type', 120);
                $t->unsignedBigInteger('origin_id');
                $t->date('date');
                $t->foreignId('cost_center_id')->nullable()->constrained('cost_centers')->nullOnDelete();
                $t->foreignId('other_center_id')->nullable()->constrained('cost_centers')->nullOnDelete();
                $t->decimal('qty', 14, 3)->default(0);
                $t->decimal('revenue', 14, 2)->default(0);
                $t->decimal('amount_batch', 14, 2)->default(0);
                $t->decimal('amount_wavg', 14, 2)->default(0);
                $t->decimal('amount_transfer', 14, 2)->nullable();
                $t->decimal('materials', 14, 2)->default(0);
                $t->decimal('overhead', 14, 2)->default(0);
                $t->string('flags', 120)->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
                $t->unique(['kind', 'origin_type', 'origin_id']);
                $t->index('date');
            });
        }

        if (! Schema::hasTable('cost_lines')) {
            Schema::create('cost_lines', function (Blueprint $t) {
                $t->id();
                $t->foreignId('cost_entry_id')->constrained('cost_entries')->cascadeOnDelete();
                $t->foreignId('product_id')->constrained('products');
                $t->unsignedBigInteger('batch_id')->nullable();
                $t->decimal('qty', 14, 3);
                $t->decimal('revenue', 14, 2)->default(0);
                $t->decimal('unit_batch', 14, 4)->default(0);
                $t->decimal('unit_wavg', 14, 4)->default(0);
                $t->decimal('unit_transfer', 14, 4)->nullable();
                $t->decimal('amount_batch', 14, 2)->default(0);
                $t->decimal('amount_wavg', 14, 2)->default(0);
                $t->decimal('amount_transfer', 14, 2)->nullable();
                $t->boolean('zero_cost')->default(false);
                $t->index('product_id');
            });
        }

        if (! Schema::hasTable('cost_months')) {
            Schema::create('cost_months', function (Blueprint $t) {
                $t->id();
                $t->char('period', 7)->unique();
                $t->string('status', 20)->default('closed');
                $t->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
                $t->dateTime('closed_at')->nullable();
                $t->json('summary')->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
            });
        }

        if (! Schema::hasTable('cost_month_lines')) {
            Schema::create('cost_month_lines', function (Blueprint $t) {
                $t->id();
                $t->foreignId('cost_month_id')->constrained('cost_months')->cascadeOnDelete();
                $t->foreignId('line_id')->constrained('cost_centers');
                $t->decimal('direct', 14, 2)->default(0);
                $t->decimal('shared', 14, 2)->default(0);
                $t->decimal('payroll', 14, 2)->default(0);
                $t->decimal('depreciation', 14, 2)->default(0);
                $t->decimal('pool', 14, 2)->default(0);
                $t->decimal('applied', 14, 2)->default(0);
                $t->decimal('variance', 14, 2)->default(0);
                $t->decimal('hours', 10, 2)->default(0);
                $t->decimal('output_kg', 14, 3)->default(0);
                $t->decimal('rate', 14, 4)->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
                $t->unique(['cost_month_id', 'line_id']);
            });
        }

        if (! Schema::hasTable('product_transfer_prices')) {
            Schema::create('product_transfer_prices', function (Blueprint $t) {
                $t->id();
                $t->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $t->decimal('price', 10, 2);
                $t->decimal('unit_cost', 14, 4);
                $t->decimal('markup_pct', 7, 2)->nullable();
                $t->date('effective_from');
                $t->foreignId('approved_by')->constrained('users');
                $t->dateTime('approved_at');
                $t->string('note', 255)->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
                $t->index(['product_id', 'effective_from']);
            });
        }

        if (Schema::hasTable('production_orders')) {
            Schema::table('production_orders', function (Blueprint $t) {
                if (! Schema::hasColumn('production_orders', 'overhead_rate')) {
                    $t->decimal('overhead_rate', 14, 4)->nullable();
                }
                if (! Schema::hasColumn('production_orders', 'overhead_kg')) {
                    $t->decimal('overhead_kg', 14, 3)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('production_orders')) {
            foreach (['overhead_rate', 'overhead_kg'] as $col) {
                if (Schema::hasColumn('production_orders', $col)) {
                    Schema::table('production_orders', fn (Blueprint $t) => $t->dropColumn($col));
                }
            }
        }
        Schema::dropIfExists('product_transfer_prices');
        Schema::dropIfExists('cost_month_lines');
        Schema::dropIfExists('cost_months');
        Schema::dropIfExists('cost_lines');
        Schema::dropIfExists('cost_entries');
    }
};
