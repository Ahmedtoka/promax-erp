<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * موافقة الأدمن على أوامر الشراء (خطة القفل ١٩/١٠، لين ٥، D2-D5):
 * سعر فوق العقد / عقد منتهي / مورد أو صنف جديد.
 * ⚠️ `approval_status` فاضي = مفيش موافقة مطلوبة (الأوامر القديمة).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_orders', function (Blueprint $t) {
            if (! Schema::hasColumn('supplier_orders', 'approval_status')) {
                $t->string('approval_status', 20)->nullable();
            }
            if (! Schema::hasColumn('supplier_orders', 'approval_reasons')) {
                $t->json('approval_reasons')->nullable();
            }
            if (! Schema::hasColumn('supplier_orders', 'approved_by')) {
                $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('supplier_orders', 'approved_at')) {
                $t->dateTime('approved_at')->nullable();
            }
            if (! Schema::hasColumn('supplier_orders', 'supplier_contract_id')) {
                $t->foreignId('supplier_contract_id')->nullable()->constrained('supplier_contracts')->nullOnDelete();
            }
        });

        Schema::table('supplier_order_items', function (Blueprint $t) {
            if (! Schema::hasColumn('supplier_order_items', 'contract_price')) {
                $t->decimal('contract_price', 10, 2)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('supplier_order_items', function (Blueprint $t) {
            if (Schema::hasColumn('supplier_order_items', 'contract_price')) {
                $t->dropColumn('contract_price');
            }
        });
        Schema::table('supplier_orders', function (Blueprint $t) {
            foreach (['approved_by', 'supplier_contract_id'] as $fk) {
                if (Schema::hasColumn('supplier_orders', $fk)) {
                    $t->dropConstrainedForeignId($fk);
                }
            }
            foreach (['approval_status', 'approval_reasons', 'approved_at'] as $c) {
                if (Schema::hasColumn('supplier_orders', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
