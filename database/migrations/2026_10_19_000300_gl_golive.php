<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * معالج تشغيل الدفتر (خطة القفل ١٩/١٠ Lane 3):
 *  - `gl_accounts.coa_draft_id` — الحساب المعتمد جاي من أنهي صف في المسودة
 *  - `gl_posting_rules.*_account_id` — تثبيت خانة في القاعدة على حساب بعينه
 *  - `coa_check_accepts` — قبول التحذيرات الصفرا (لكل فحص × صف)
 *  - `gl_opening_lines` — الأرصدة الافتتاحية اليدوية لكل حساب
 *  - `gl_rebuild_runs` — معاينة / تنفيذ إعادة البناء بالفروق
 *  - `gl_golive_steps` — حالة كل خطوة في المعالج
 * إضافة بس، و`down()` بيشيل اللي اتعمل هنا بس.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('gl_accounts') && ! Schema::hasColumn('gl_accounts', 'coa_draft_id')) {
            Schema::table('gl_accounts', function (Blueprint $t) {
                $t->unsignedBigInteger('coa_draft_id')->nullable()->index();
            });
        }

        if (Schema::hasTable('gl_posting_rules')) {
            foreach (['debit_account_id', 'credit_account_id', 'tax_account_id'] as $col) {
                if (! Schema::hasColumn('gl_posting_rules', $col)) {
                    Schema::table('gl_posting_rules', function (Blueprint $t) use ($col) {
                        $t->foreignId($col)->nullable()->constrained('gl_accounts')->nullOnDelete();
                    });
                }
            }
        }

        if (! Schema::hasTable('coa_check_accepts')) {
            Schema::create('coa_check_accepts', function (Blueprint $t) {
                $t->id();
                $t->string('check', 30);
                $t->unsignedBigInteger('draft_account_id')->nullable();
                $t->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
                $t->dateTime('accepted_at');
                $t->unique(['check', 'draft_account_id']);
            });
        }

        if (! Schema::hasTable('gl_opening_lines')) {
            Schema::create('gl_opening_lines', function (Blueprint $t) {
                $t->id();
                $t->foreignId('account_id')->constrained('gl_accounts');
                $t->foreignId('cost_center_id')->nullable()->constrained('cost_centers')->nullOnDelete();
                $t->decimal('debit', 14, 2)->default(0);
                $t->decimal('credit', 14, 2)->default(0);
                $t->string('note', 250)->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
            });
        }

        if (! Schema::hasTable('gl_rebuild_runs')) {
            Schema::create('gl_rebuild_runs', function (Blueprint $t) {
                $t->id();
                $t->string('mode', 10);            // preview | execute
                $t->date('from_date');
                $t->string('status', 10);          // ok | failed | blocked
                $t->longText('summary')->nullable();
                $t->longText('rows')->nullable();
                $t->longText('checks')->nullable();
                $t->string('error', 500)->nullable();
                $t->unsignedBigInteger('preview_id')->nullable()->index();
                $t->dateTime('approved_at')->nullable();
                $t->unsignedBigInteger('approved_by')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
            });
        }

        if (! Schema::hasTable('gl_golive_steps')) {
            Schema::create('gl_golive_steps', function (Blueprint $t) {
                $t->id();
                $t->string('key', 30)->unique();
                $t->string('status', 10)->default('todo');
                $t->longText('data')->nullable();
                $t->dateTime('done_at')->nullable();
                $t->unsignedBigInteger('done_by')->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('gl_golive_steps');
        Schema::dropIfExists('gl_rebuild_runs');
        Schema::dropIfExists('gl_opening_lines');
        Schema::dropIfExists('coa_check_accepts');

        foreach (['tax_account_id', 'credit_account_id', 'debit_account_id'] as $col) {
            if (Schema::hasColumn('gl_posting_rules', $col)) {
                Schema::table('gl_posting_rules', fn (Blueprint $t) => $t->dropConstrainedForeignId($col));
            }
        }
        if (Schema::hasColumn('gl_accounts', 'coa_draft_id')) {
            Schema::table('gl_accounts', function (Blueprint $t) {
                $t->dropIndex(['coa_draft_id']);
                $t->dropColumn('coa_draft_id');
            });
        }
    }
};
