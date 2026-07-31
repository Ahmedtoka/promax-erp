<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * النسخة الإنجليزية من العقود.
 *
 * ⚠️ القاعدة: **ممنوع كلمة عربي تظهر في الواجهة الإنجليزية ولا العكس.**
 * العقود كلها محرّرة بالعربي، فأي نص جاي منها لازم يبقى له مقابل إنجليزي
 * مخزّن — مش ترجمة وقت العرض.
 *
 * النصوص الحرة الطويلة (شروط السداد، الإنهاء، التجديد، الملاحظات، بنود
 * العقد النصية) **مش** بتتخزن بالإنجليزي: بدل ما نعرض ترجمة آلية ركيكة
 * أو نص عربي في شاشة إنجليزية، الصفحة الإنجليزية بتوجّه المستخدم لأصل
 * العقد. اللي بيتعرض في اللغتين هو الداتا المنظّمة بس.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            if (! Schema::hasColumn('contracts', 'chain_en')) {
                $table->string('chain_en', 190)->nullable()->after('chain');
            }
            if (! Schema::hasColumn('contracts', 'type_key')) {
                // مفتاح ثابت بدل النص العربي — بيتترجم من ملفات اللغة
                $table->string('type_key', 30)->nullable()->after('type');
            }
        });

        Schema::table('contract_clauses', function (Blueprint $table) {
            // ⚠️ label_en اتعرّف جوه حارس hasTable في مايجريشن 000012. أي
            // داتابيز شغّلت 000012 قبل ما نضيفه هيفضل من غير العمود، والسيدر
            // هيقع بـ Unknown column. الحارس ده بيغطي الحالة دي.
            if (! Schema::hasColumn('contract_clauses', 'label_en')) {
                $table->string('label_en', 400)->nullable()->after('label');
            }
            if (! Schema::hasColumn('contract_clauses', 'raw_amount_en')) {
                $table->string('raw_amount_en', 190)->nullable()->after('raw_amount');
            }
            if (! Schema::hasColumn('contract_clauses', 'note_en')) {
                $table->text('note_en')->nullable()->after('note');
            }
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            foreach (['chain_en', 'type_key'] as $column) {
                if (Schema::hasColumn('contracts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('contract_clauses', function (Blueprint $table) {
            foreach (['raw_amount_en', 'note_en'] as $column) {
                if (Schema::hasColumn('contract_clauses', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
