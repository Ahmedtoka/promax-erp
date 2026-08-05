<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تراك تعديل أوامر التوريد (قرار المالك 2026-08-05): مين أنشأ موجود
 * (created_by) ومين وافق موجود (approved_by/at) — الناقص كان مين
 * **عدّل** وإمتى. `was_edited` كانت علامة من غير صاحب.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('purchase_orders', 'edited_by')) {
                $table->foreignId('edited_by')->nullable()->after('was_edited')
                    ->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('purchase_orders', 'edited_at')) {
                $table->timestamp('edited_at')->nullable()->after('edited_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            if (Schema::hasColumn('purchase_orders', 'edited_by')) {
                $table->dropConstrainedForeignId('edited_by');
            }
            if (Schema::hasColumn('purchase_orders', 'edited_at')) {
                $table->dropColumn('edited_at');
            }
        });
    }
};
