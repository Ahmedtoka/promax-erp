<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** سندات المصروف وحركة النقدية (١١/٩/٢٠٢٦) — كل سند بيولّد قيد في gl_entries. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('expenses')) {
            Schema::create('expenses', function (Blueprint $t) {
                $t->id();
                $t->string('number', 20)->unique();
                $t->date('date');
                $t->foreignId('account_id')->constrained('gl_accounts');
                $t->decimal('amount', 14, 2);
                $t->string('paid_from', 10);                 // cash_main bank rep_cash
                $t->foreignId('paid_from_user_id')->nullable()->constrained('users')->nullOnDelete();
                $t->string('payee_type', 10)->default('other'); // supplier employee other
                $t->foreignId('payee_supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
                $t->foreignId('payee_user_id')->nullable()->constrained('users')->nullOnDelete();
                $t->string('payee_name', 120)->nullable();
                $t->string('reference', 80)->nullable();
                $t->string('note', 250)->nullable();
                $t->string('attachment_path')->nullable();
                $t->string('status', 8)->default('posted');  // posted void
                $t->dateTime('voided_at')->nullable();
                $t->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
                $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
                $t->index(['date', 'status']);
            });
        }
        if (! Schema::hasTable('cash_movements')) {
            Schema::create('cash_movements', function (Blueprint $t) {
                $t->id();
                $t->string('number', 20)->unique();
                $t->date('date');
                $t->string('kind', 12);                      // deposit withdraw rep_advance rep_return
                $t->decimal('amount', 14, 2);
                $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $t->string('reference', 80)->nullable();
                $t->string('note', 250)->nullable();
                $t->string('attachment_path')->nullable();
                $t->string('status', 8)->default('posted');
                $t->dateTime('voided_at')->nullable();
                $t->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
                $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
                $t->index(['date', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('expenses');
    }
};
