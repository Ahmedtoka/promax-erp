<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * (١٣/١١ — أحمد) إثبات الاستلام على الفواتير — نفس أوامر التوريد:
 *   • `invoices.receipt_note` (+ `receipt_note_no`): العميل/السلسلة عملت إذن استلام بتاعها.
 *   • `invoices.paper_signed`: إمضاء / ختم العميل على ورقتنا.
 *
 * ⚠️ إضافة أعمدة بس — مفيش داتا بتتغير.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            if (! Schema::hasColumn('invoices', 'receipt_note')) {
                $t->boolean('receipt_note')->default(false)->after('paper_ref');
            }
            if (! Schema::hasColumn('invoices', 'receipt_note_no')) {
                $t->string('receipt_note_no', 40)->nullable()->after('receipt_note');
            }
            if (! Schema::hasColumn('invoices', 'paper_signed')) {
                $t->boolean('paper_signed')->default(false)->after('receipt_note_no');
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            foreach (['paper_signed', 'receipt_note_no', 'receipt_note'] as $c) {
                if (Schema::hasColumn('invoices', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
