<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تدقيق إلغاء أمر التوريد (٢١ أغسطس ٢٠٢٦) — «مين لغى وإمتى».
 *
 * بلاغ المالك بعد أول إلغاء حقيقي: «منزلش في الهيستوري بتاعته إنه
 * اتلغى والسبب ومين اللاغي». السبب كان بيتسجل في `abort_reason`،
 * بس الفاعل والوقت ماكانش ليهم مكان — فخط سير الأمر كان بيقف عند
 * «التجهيز» وكأن الإلغاء حصل لوحده.
 *
 * ⚠️ محروسة — السيرفر اللايف بيترفع بالإيد مش جيت.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('purchase_orders')) {
            return;
        }

        Schema::table('purchase_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('purchase_orders', 'cancelled_by')) {
                // ⚠️ nullOnDelete — مسح الموظف مايمسحش تاريخ الأمر
                $table->foreignId('cancelled_by')->nullable()
                    ->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('purchase_orders', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('purchase_orders')) {
            return;
        }

        Schema::table('purchase_orders', function (Blueprint $table) {
            if (Schema::hasColumn('purchase_orders', 'cancelled_by')) {
                $table->dropConstrainedForeignId('cancelled_by');
            }

            if (Schema::hasColumn('purchase_orders', 'cancelled_at')) {
                $table->dropColumn('cancelled_at');
            }
        });
    }
};
