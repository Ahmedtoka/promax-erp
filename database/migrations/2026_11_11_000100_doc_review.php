<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مراجعة المستندات مستند مستند (١١/١١/٢٠٢٦ — طلب أحمد)
 *
 * المحاسب ماسك الورق وبيراجع كل فاتورة وكل أمر توريد على السيستم.
 *   • العلامة نفسها في `review_checks` (entity_type = invoice | po، item = doc).
 *   • `doc_review_events`: سجل مابيتمسحش ولا بيتعدّل — كل ✓ / ✗ / فتح تاني /
 *     تعديل بقيمته قبل وبعد وفرق الفلوس، والثواني من آخر مراجعة لنفس اليوزر
 *     (عشان أحمد يشوف المراجعة حقيقية ولا ✓ ✓ ✓).
 *   • `purchase_orders.paper_ref`: السيريال الورقي للأمر — زي الفاتورة.
 *
 * ⚠️ إضافة بس (جدول + عمود) — آمنة على اللايف.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('doc_review_events')) {
            Schema::create('doc_review_events', function (Blueprint $t) {
                $t->id();
                $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $t->string('doc_type', 8);              // invoice | po
                $t->unsignedBigInteger('doc_id');
                $t->string('action', 12);               // ok | issue | reopen | serial | lines | rep | date
                $t->json('before')->nullable();
                $t->json('after')->nullable();
                $t->decimal('net_delta', 14, 2)->default(0);
                $t->decimal('grand_delta', 14, 2)->default(0);
                $t->unsignedInteger('seconds_since_prev')->nullable();
                $t->unsignedInteger('open_ms')->nullable();
                $t->string('note', 500)->nullable();
                $t->timestamp('created_at')->nullable();

                $t->index(['doc_type', 'doc_id']);
                $t->index(['user_id', 'created_at']);
                $t->index(['action', 'created_at']);
            });
        }

        if (! Schema::hasColumn('purchase_orders', 'paper_ref')) {
            Schema::table('purchase_orders', function (Blueprint $t) {
                $t->string('paper_ref', 30)->nullable()->index()->after('number');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('purchase_orders', 'paper_ref')) {
            Schema::table('purchase_orders', function (Blueprint $t) {
                $t->dropIndex(['paper_ref']);
                $t->dropColumn('paper_ref');
            });
        }

        Schema::dropIfExists('doc_review_events');
    }
};
