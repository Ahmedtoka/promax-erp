<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * (١٢/١١ — أحمد) المرتجعات في المراجعة مستند مستند — نوع تالت جنب الفواتير وأوامر التوريد.
 *
 *   • `returns.paper_ref`: رقم إذن المرتجع الورقي — شرط ✓ زي الفاتورة.
 *     دفتر لوحده: المكرر بيتقارن بين المرتجعات وبعض بس.
 *   • `returns.paper_signed`: إمضاء / ختم العميل على إذن المرتجع — إثبات إن
 *     البضاعة رجعت فعلاً قبل ما نخصم من مديونيته.
 *
 * ⚠️ إضافة أعمدة بس — مفيش داتا بتتغير. `returns` كلمة محجوزة: Schema بيحط backticks.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('returns', 'paper_ref')) {
            Schema::table('returns', function (Blueprint $t) {
                $t->string('paper_ref', 30)->nullable()->index()->after('number');
            });
        }
        if (! Schema::hasColumn('returns', 'paper_signed')) {
            Schema::table('returns', function (Blueprint $t) {
                $t->boolean('paper_signed')->default(false)->after('paper_ref');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('returns', 'paper_signed')) {
            Schema::table('returns', fn (Blueprint $t) => $t->dropColumn('paper_signed'));
        }
        if (Schema::hasColumn('returns', 'paper_ref')) {
            Schema::table('returns', function (Blueprint $t) {
                $t->dropIndex(['paper_ref']);
                $t->dropColumn('paper_ref');
            });
        }
    }
};
