<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * إثبات استلام أوامر التوريد (١٢/١١/٢٠٢٦ — طلب أحمد)
 *
 * السلسلة مابتدفعش أمر توريد غير بإثبات إنه اتسلّم: يا إذن استلام بتاعها،
 * يا إمضاء/ختم على ورقة التسليم بتاعتنا. المحاسب بيعلّم ده وهو بيراجع
 * الورق في شاشة «المراجعة مستند مستند».
 *
 *   • `receipt_note`     — يوجد إذن استلام من العميل
 *   • `receipt_note_no`  — رقم الإذن (اختياري)
 *   • `paper_signed`     — إمضاء أو ختم العميل على ورقتنا
 *
 * ⚠️ إضافة أعمدة بس بقيم افتراضية — آمنة على اللايف، مفيش تعديل داتا.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $t) {
            if (! Schema::hasColumn('purchase_orders', 'receipt_note')) {
                $t->boolean('receipt_note')->default(false)->after('paper_ref');
            }
            if (! Schema::hasColumn('purchase_orders', 'receipt_note_no')) {
                $t->string('receipt_note_no', 40)->nullable()->after('receipt_note');
            }
            if (! Schema::hasColumn('purchase_orders', 'paper_signed')) {
                $t->boolean('paper_signed')->default(false)->after('receipt_note_no');
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $t) {
            foreach (['paper_signed', 'receipt_note_no', 'receipt_note'] as $c) {
                if (Schema::hasColumn('purchase_orders', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
