<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * بيانات السلسلة (٣/١١/٢٠٢٦) — قايمة السعر والخصم وشامل/خاضع والمستندات
 * (السجل التجاري والبطاقة) على السلسلة نفسها.
 *
 * ⚠️ الفاضي = «السلسلة ماحددتش»: الفروع بتفضل على بياناتها. اللي بيتحط
 * هنا بيتختم على الفروع اللي في السلسلة، وعلى أي فرع يتضم بعدين.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_groups', function (Blueprint $t) {
            if (! Schema::hasColumn('client_groups', 'price_list_id')) {
                $t->foreignId('price_list_id')->nullable()->after('sub_channel')
                    ->constrained('price_lists')->nullOnDelete();
            }
            if (! Schema::hasColumn('client_groups', 'default_discount')) {
                $t->decimal('default_discount', 6, 4)->nullable()->after('price_list_id');
            }
            if (! Schema::hasColumn('client_groups', 'taxable')) {
                $t->boolean('taxable')->nullable()->after('default_discount');
            }
            if (! Schema::hasColumn('client_groups', 'docs_path')) {
                $t->string('docs_path')->nullable()->after('taxable');
                $t->string('docs_type', 10)->nullable()->after('docs_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('client_groups', function (Blueprint $t) {
            if (Schema::hasColumn('client_groups', 'price_list_id')) {
                $t->dropConstrainedForeignId('price_list_id');
            }
            foreach (['default_discount', 'taxable', 'docs_path', 'docs_type'] as $col) {
                if (Schema::hasColumn('client_groups', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }
};
