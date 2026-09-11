<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * دفتر الأستاذ العام (١١ سبتمبر ٢٠٢٦) — طبقة مشتقة فوق `transactions`.
 * ⚠️ مفيش أي تعديل على الجداول الموجودة. المديونية مصدرها `transactions`،
 * والشجرة بتتولد منها (شوف docs/superpowers/specs/2026-09-11-chart-of-accounts-design.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('gl_accounts')) {
            Schema::create('gl_accounts', function (Blueprint $t) {
                $t->id();
                $t->string('code', 20)->unique();
                $t->string('name', 120);
                $t->string('name_en', 120)->nullable();
                $t->foreignId('parent_id')->nullable()->constrained('gl_accounts')->nullOnDelete();
                $t->string('type', 10);          // asset liability equity revenue expense
                $t->string('normal_side', 6);    // debit credit
                $t->boolean('is_system')->default(false);
                $t->string('system_key', 40)->nullable()->unique();
                $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $t->boolean('is_postable')->default(true);
                $t->boolean('active')->default(true);
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('gl_entries')) {
            Schema::create('gl_entries', function (Blueprint $t) {
                $t->id();
                $t->string('number', 20)->unique();
                $t->date('date');
                $t->string('period_key', 7)->index();
                $t->string('memo', 250);
                $t->string('origin', 10)->index();  // auto manual reversal opening
                $t->string('source_type', 60)->nullable();
                $t->unsignedBigInteger('source_id')->nullable();
                $t->string('rule_key', 40)->nullable();
                $t->boolean('needs_review')->default(false);
                $t->foreignId('reverses_entry_id')->nullable()->constrained('gl_entries')->nullOnDelete();
                $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $t->dateTime('edited_at')->nullable();
                $t->foreignId('edited_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
                // قيد آلي واحد لكل مستند
                $t->unique(['source_type', 'source_id', 'origin'], 'gl_entries_source_unique');
                $t->index(['date', 'id']);
            });
        }
        if (! Schema::hasTable('gl_lines')) {
            Schema::create('gl_lines', function (Blueprint $t) {
                $t->id();
                $t->foreignId('entry_id')->constrained('gl_entries')->cascadeOnDelete();
                $t->foreignId('account_id')->constrained('gl_accounts');
                $t->decimal('debit', 14, 2)->default(0);
                $t->decimal('credit', 14, 2)->default(0);
                $t->string('memo', 250)->nullable();
                $t->boolean('overridden')->default(false);
                $t->foreignId('rule_account_id')->nullable()->constrained('gl_accounts');
                $t->string('slot', 20)->nullable(); // dr / cr / tax — لإعادة تطبيق التعديل بعد إعادة البناء
                $t->timestamps();
                $t->index(['account_id', 'entry_id']);
            });
        }
        if (! Schema::hasTable('gl_line_overrides')) {
            Schema::create('gl_line_overrides', function (Blueprint $t) {
                $t->id();
                $t->foreignId('line_id')->constrained('gl_lines')->cascadeOnDelete();
                $t->foreignId('from_account_id')->constrained('gl_accounts');
                $t->foreignId('to_account_id')->constrained('gl_accounts');
                $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $t->dateTime('at');
                $t->string('note', 250)->nullable();
            });
        }
        if (! Schema::hasTable('gl_posting_rules')) {
            Schema::create('gl_posting_rules', function (Blueprint $t) {
                $t->id();
                $t->string('key', 40)->unique();
                $t->string('label', 120);
                $t->string('debit_key', 40)->nullable();
                $t->string('credit_key', 40)->nullable();
                $t->string('tax_key', 40)->nullable();
                $t->boolean('active')->default(true);
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('gl_periods')) {
            Schema::create('gl_periods', function (Blueprint $t) {
                $t->id();
                $t->string('key', 7)->unique();   // YYYY-MM
                $t->string('status', 8)->default('open');
                $t->dateTime('closed_at')->nullable();
                $t->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
                $t->dateTime('reopened_at')->nullable();
                $t->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        foreach (['gl_line_overrides', 'gl_lines', 'gl_entries', 'gl_posting_rules', 'gl_periods', 'gl_accounts'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
