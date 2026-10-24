<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سجل تعديلات الأسعار (Lane 13، ٢٤/١٠). التعديل بيتحفظ مسودة (run)،
 * بيتعاين بأثره المالي، وعند التأكيد بيتطبق على القايمة وعلى فواتير
 * وأوامر التوريد المؤهلة — وكل مستند اتلمس أو اتخطى بيتسجل في docs.
 *
 * ⚠️ كل أعمدة الوقت dateTime (مش timestamp) بما فيها created_at/updated_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('price_change_runs')) {
            Schema::create('price_change_runs', function (Blueprint $t) {
                $t->id();
                $t->string('number', 20)->unique();
                $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $t->string('trigger', 16);   // list|chain|branch|client|invoice
                $t->string('status', 16);    // draft|applied|master_only|cancelled
                $t->foreignId('price_list_id')->nullable()->constrained('price_lists')->nullOnDelete();
                $t->foreignId('group_id')->nullable()->constrained('client_groups')->nullOnDelete();
                $t->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
                $t->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
                $t->string('scope', 16);     // list|chain|branch|client|invoice
                $t->date('since')->nullable();
                $t->json('payload');
                $t->json('item_changes')->nullable();
                $t->json('preview')->nullable();
                $t->json('summary')->nullable();
                $t->unsignedInteger('clients_count')->default(0);
                $t->unsignedInteger('invoices_changed')->default(0);
                $t->unsignedInteger('pos_changed')->default(0);
                $t->unsignedInteger('skipped_count')->default(0);
                $t->unsignedInteger('unchanged_count')->default(0);
                $t->decimal('before_net', 14, 2)->default(0);
                $t->decimal('before_tax', 14, 2)->default(0);
                $t->decimal('before_grand', 14, 2)->default(0);
                $t->decimal('after_net', 14, 2)->default(0);
                $t->decimal('after_tax', 14, 2)->default(0);
                $t->decimal('after_grand', 14, 2)->default(0);
                $t->decimal('ledger_diff', 14, 2)->default(0);
                $t->string('error', 250)->nullable();
                $t->dateTime('applied_at')->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
                $t->index(['status', 'created_at']);
            });
        }

        if (! Schema::hasTable('price_change_docs')) {
            Schema::create('price_change_docs', function (Blueprint $t) {
                $t->id();
                $t->foreignId('run_id')->constrained('price_change_runs')->cascadeOnDelete();
                $t->string('doc_type', 8);   // invoice|po
                $t->unsignedBigInteger('doc_id');
                $t->string('number', 40);
                $t->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
                $t->date('doc_date')->nullable();
                $t->string('payment', 10)->nullable();
                $t->string('outcome', 10);   // changed|skipped
                $t->string('reason', 30)->nullable();
                $t->string('reason_detail', 190)->nullable();
                $t->decimal('before_net', 14, 2)->nullable();
                $t->decimal('before_tax', 14, 2)->nullable();
                $t->decimal('before_grand', 14, 2)->nullable();
                $t->decimal('after_net', 14, 2)->nullable();
                $t->decimal('after_tax', 14, 2)->nullable();
                $t->decimal('after_grand', 14, 2)->nullable();
                $t->json('lines')->nullable();
                $t->dateTime('created_at')->nullable();
                $t->index(['doc_type', 'doc_id']);
                $t->index(['run_id', 'outcome']);
            });
        }

        if (! Schema::hasColumn('invoices', 'price_change_run_id')) {
            Schema::table('invoices', function (Blueprint $t) {
                if (! Schema::hasColumn('invoices', 'repriced_at')) {
                    $t->dateTime('repriced_at')->nullable();
                }
                if (! Schema::hasColumn('invoices', 'repriced_by')) {
                    $t->foreignId('repriced_by')->nullable()->constrained('users')->nullOnDelete();
                }
                $t->foreignId('price_change_run_id')->nullable()->constrained('price_change_runs')->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('purchase_orders', 'price_change_run_id')) {
            Schema::table('purchase_orders', function (Blueprint $t) {
                $t->foreignId('price_change_run_id')->nullable()->constrained('price_change_runs')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('purchase_orders', 'price_change_run_id')) {
            Schema::table('purchase_orders', function (Blueprint $t) {
                $t->dropConstrainedForeignId('price_change_run_id');
            });
        }

        if (Schema::hasColumn('invoices', 'price_change_run_id')) {
            Schema::table('invoices', function (Blueprint $t) {
                $t->dropConstrainedForeignId('price_change_run_id');
                $t->dropConstrainedForeignId('repriced_by');
                $t->dropColumn('repriced_at');
            });
        }

        Schema::dropIfExists('price_change_docs');
        Schema::dropIfExists('price_change_runs');
    }
};
