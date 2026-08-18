<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * علامة «اتراجعت» لشاشتي إعداد السلاسل والعملاء  ·  ١٨ أغسطس ٢٠٢٦
 *
 * طلب المالك: «كل ما أخلص سلسلة وأراجعها أعلّم عليها تبقى خلاص —
 * مش هراجعها تاني، لحد ما كله يبقى أخضر. وهكذا في العملاء».
 *
 * timestamp مش boolean عن قصد — بيسجل امتى اتراجعت، فلو رجعنا نسأل
 * «المراجعة دي قبل ولا بعد تعديل الأسعار الفلاني» الإجابة موجودة.
 *
 * ⚠️ محروسة — السيرفر مش ريبو جيت والمالك بيرفع بإيده.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_groups', function (Blueprint $table) {
            if (! Schema::hasColumn('client_groups', 'reviewed_at')) {
                $table->timestamp('reviewed_at')->nullable()->after('active');
            }
        });

        Schema::table('clients', function (Blueprint $table) {
            if (! Schema::hasColumn('clients', 'setup_reviewed_at')) {
                $table->timestamp('setup_reviewed_at')->nullable()->after('division');
            }
        });
    }

    public function down(): void
    {
        Schema::table('client_groups', function (Blueprint $table) {
            if (Schema::hasColumn('client_groups', 'reviewed_at')) {
                $table->dropColumn('reviewed_at');
            }
        });

        Schema::table('clients', function (Blueprint $table) {
            if (Schema::hasColumn('clients', 'setup_reviewed_at')) {
                $table->dropColumn('setup_reviewed_at');
            }
        });
    }
};
