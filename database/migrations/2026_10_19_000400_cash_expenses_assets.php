<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lane 4 (خطة القفل ١٩/١٠): بنود المصروفات، خانات الاعتماد والخزنة والمركز
 * على المصروف، الخزن على حركة النقدية، الجرد اليومي، كشف البنك وتسويته،
 * والأصول الثابتة وإهلاكها. إضافة بس — كل خطوة محروسة.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('expense_categories')) {
            Schema::create('expense_categories', function (Blueprint $t) {
                $t->id();
                $t->string('code', 30)->unique();
                $t->string('name');
                $t->string('name_en')->nullable();
                $t->string('type', 12);   // operating|asset|payroll|factory
                $t->foreignId('account_id')->nullable()->constrained('gl_accounts')->nullOnDelete();
                $t->unsignedSmallInteger('life_months')->nullable();
                $t->boolean('links_vehicle')->default(false);
                $t->boolean('active')->default(true);
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
            });
        }

        $this->addColumns('expenses', [
            'category_id' => fn (Blueprint $t) => $t->foreignId('category_id')->nullable()->constrained('expense_categories')->nullOnDelete(),
            'safe_id' => fn (Blueprint $t) => $t->foreignId('safe_id')->nullable()->constrained('safes')->nullOnDelete(),
            'cost_center_id' => fn (Blueprint $t) => $t->foreignId('cost_center_id')->nullable()->constrained('cost_centers')->nullOnDelete(),
            'rep_id' => fn (Blueprint $t) => $t->foreignId('rep_id')->nullable()->constrained('users')->nullOnDelete(),
            'vehicle_id' => fn (Blueprint $t) => $t->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete(),
            'approved_by' => fn (Blueprint $t) => $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete(),
            'approved_at' => fn (Blueprint $t) => $t->dateTime('approved_at')->nullable(),
            'rejected_by' => fn (Blueprint $t) => $t->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete(),
            'rejected_at' => fn (Blueprint $t) => $t->dateTime('rejected_at')->nullable(),
            'decision_note' => fn (Blueprint $t) => $t->string('decision_note', 250)->nullable(),
            'paid_by' => fn (Blueprint $t) => $t->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete(),
            'paid_at' => fn (Blueprint $t) => $t->dateTime('paid_at')->nullable(),
            'asset_name' => fn (Blueprint $t) => $t->string('asset_name', 150)->nullable(),
            'asset_life_months' => fn (Blueprint $t) => $t->unsignedSmallInteger('asset_life_months')->nullable(),
            'asset_start_date' => fn (Blueprint $t) => $t->date('asset_start_date')->nullable(),
        ]);

        $this->addColumns('cash_movements', [
            'from_safe_id' => fn (Blueprint $t) => $t->foreignId('from_safe_id')->nullable()->constrained('safes')->nullOnDelete(),
            'to_safe_id' => fn (Blueprint $t) => $t->foreignId('to_safe_id')->nullable()->constrained('safes')->nullOnDelete(),
            'cost_center_id' => fn (Blueprint $t) => $t->foreignId('cost_center_id')->nullable()->constrained('cost_centers')->nullOnDelete(),
        ]);

        // safe_transfer / bank_transfer = 13 حرف و kind كان string(12).
        // ⚠️ محروسة بمعنى «آمنة للتكرار» — change() على عمود أوسع مابيضرّش.
        if (Schema::hasColumn('cash_movements', 'kind')) {
            $type = (string) (collect(Schema::getColumns('cash_movements'))->firstWhere('name', 'kind')['type'] ?? '');
            $len = preg_match('/\((\d+)\)/', $type, $m) ? (int) $m[1] : 0;
            if ($len > 0 && $len < 20) {
                Schema::table('cash_movements', fn (Blueprint $t) => $t->string('kind', 20)->change());
            }
        }

        if (! Schema::hasTable('safe_counts')) {
            Schema::create('safe_counts', function (Blueprint $t) {
                $t->id();
                $t->string('number', 20)->unique();
                $t->foreignId('safe_id')->constrained('safes')->cascadeOnDelete();
                $t->date('date');
                $t->decimal('expected', 14, 2);
                $t->decimal('counted', 14, 2);
                $t->decimal('difference', 14, 2);
                $t->string('note', 250)->nullable();
                $t->string('status', 8)->default('posted');   // posted|void
                $t->dateTime('voided_at')->nullable();
                $t->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
                $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
                $t->index(['safe_id', 'date']);
            });
        }

        if (! Schema::hasTable('bank_statements')) {
            Schema::create('bank_statements', function (Blueprint $t) {
                $t->id();
                $t->string('number', 20)->unique();
                $t->foreignId('safe_id')->constrained('safes');
                $t->date('date');
                $t->date('date_from');
                $t->date('date_to');
                $t->decimal('opening_balance', 14, 2)->default(0);
                $t->decimal('closing_balance', 14, 2);
                $t->string('note', 250)->nullable();
                $t->string('status', 8)->default('open');   // open|closed
                $t->dateTime('closed_at')->nullable();
                $t->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
                $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
            });
        }

        if (! Schema::hasTable('bank_statement_marks')) {
            Schema::create('bank_statement_marks', function (Blueprint $t) {
                $t->id();
                $t->foreignId('statement_id')->constrained('bank_statements')->cascadeOnDelete();
                $t->string('line_key', 80)->unique();
                // ⚠️ من غير FK: إعادة البناء بتعيد إنشاء سطور القيود بأرقام جديدة،
                // والعلامة بتعيش على line_key (L4-D6)
                $t->unsignedBigInteger('gl_line_id')->nullable();
                $t->decimal('amount', 14, 2);   // موقّع: مدين − دائن
                $t->date('line_date');
                $t->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
                $t->dateTime('marked_at')->nullable();
            });
        }

        if (! Schema::hasTable('fixed_assets')) {
            Schema::create('fixed_assets', function (Blueprint $t) {
                $t->id();
                $t->string('code', 20)->unique();
                $t->string('name');
                $t->string('name_en')->nullable();
                $t->foreignId('category_id')->nullable()->constrained('expense_categories')->nullOnDelete();
                $t->foreignId('expense_id')->nullable()->unique()->constrained('expenses')->nullOnDelete();
                $t->decimal('cost', 14, 2);
                $t->decimal('salvage', 14, 2)->default(0);
                $t->unsignedSmallInteger('life_months');
                $t->date('start_date');
                $t->foreignId('cost_center_id')->nullable()->constrained('cost_centers')->nullOnDelete();
                $t->foreignId('asset_account_id')->nullable()->constrained('gl_accounts')->nullOnDelete();
                $t->decimal('opening_accum', 14, 2)->default(0);
                $t->date('opening_as_of')->nullable();
                $t->string('status', 10)->default('active');
                $t->dateTime('disposed_at')->nullable();
                $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
            });
        }

        if (! Schema::hasTable('fixed_asset_depreciations')) {
            Schema::create('fixed_asset_depreciations', function (Blueprint $t) {
                $t->id();
                $t->foreignId('fixed_asset_id')->constrained('fixed_assets')->cascadeOnDelete();
                $t->char('period_key', 7);   // YYYY-MM
                $t->date('date');            // آخر يوم في الشهر
                $t->decimal('amount', 14, 2);
                $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
                $t->unique(['fixed_asset_id', 'period_key']);
            });
        }
    }

    /** @param array<string, callable(Blueprint): mixed> $cols */
    private function addColumns(string $table, array $cols): void
    {
        foreach ($cols as $name => $def) {
            if (! Schema::hasColumn($table, $name)) {
                Schema::table($table, fn (Blueprint $t) => $def($t));
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_depreciations');
        Schema::dropIfExists('fixed_assets');
        Schema::dropIfExists('bank_statement_marks');
        Schema::dropIfExists('bank_statements');
        Schema::dropIfExists('safe_counts');

        foreach (['cash_movements' => ['from_safe_id', 'to_safe_id', 'cost_center_id'],
            'expenses' => ['category_id', 'safe_id', 'cost_center_id', 'rep_id', 'vehicle_id', 'approved_by', 'rejected_by', 'paid_by']] as $table => $fks) {
            foreach ($fks as $fk) {
                if (Schema::hasColumn($table, $fk)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropConstrainedForeignId($fk));
                }
            }
        }
        foreach (['approved_at', 'rejected_at', 'decision_note', 'paid_at', 'asset_name', 'asset_life_months', 'asset_start_date'] as $col) {
            if (Schema::hasColumn('expenses', $col)) {
                Schema::table('expenses', fn (Blueprint $t) => $t->dropColumn($col));
            }
        }

        Schema::dropIfExists('expense_categories');
    }
};
