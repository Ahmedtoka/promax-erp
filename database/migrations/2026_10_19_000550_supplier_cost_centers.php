<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مركز التكلفة على فاتورة المورد ودفعته (إغلاق ١٩/١٠، لين ٥، D9).
 * `supplier_orders.cost_center_id` جه مع طلبات الشراء (000530).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['supplier_invoices', 'supplier_payments'] as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'cost_center_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->foreignId('cost_center_id')->nullable()->constrained('cost_centers')->nullOnDelete();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['supplier_invoices', 'supplier_payments'] as $table) {
            if (Schema::hasColumn($table, 'cost_center_id')) {
                Schema::table($table, function (Blueprint $t) use ($table) {
                    $t->dropConstrainedForeignId('cost_center_id');
                });
            }
        }
    }
};
