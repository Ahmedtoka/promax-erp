<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مراكز التكلفة (خطة القفل ١٩/١٠، التصميم §٤.١) — شجرة واحدة:
 * الشركة ← المصنع ← خطوط الإنتاج، والشركة ← الفروع.
 *
 * `warehouses.cost_center_id`: المصنع مربوط بكل مخازن نوع `factory`،
 * و`cost_centers.warehouse_id` عمود واحد — فالمخزن بيشاور على مركزه.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cost_centers')) {
            Schema::create('cost_centers', function (Blueprint $t) {
                $t->id();
                $t->string('code', 20)->unique();
                $t->string('name');
                $t->string('name_en')->nullable();
                $t->foreignId('parent_id')->nullable()->constrained('cost_centers')->nullOnDelete();
                $t->string('kind', 20);   // company|factory|line|branch
                $t->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $t->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
                $t->boolean('active')->default(true);
                // ⚠️ dateTime مش timestamp — promax-system §4
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
                $t->index('kind');
            });
        }

        if (Schema::hasTable('warehouses') && ! Schema::hasColumn('warehouses', 'cost_center_id')) {
            Schema::table('warehouses', function (Blueprint $t) {
                $t->foreignId('cost_center_id')->nullable()->constrained('cost_centers')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('warehouses', 'cost_center_id')) {
            Schema::table('warehouses', fn (Blueprint $t) => $t->dropConstrainedForeignId('cost_center_id'));
        }
        Schema::dropIfExists('cost_centers');
    }
};
