<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * رقم السجل التجاري (٣/١١/٢٠٢٦) — بيتكتب بالإيد على العميل وعلى السلسلة،
 * واللي على السلسلة بيتختم على فروعها وعلى أي فرع يتضم.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('clients', 'commercial_register')) {
            Schema::table('clients', function (Blueprint $t) {
                $t->string('commercial_register', 60)->nullable()->after('tax_id');
            });
        }

        if (! Schema::hasColumn('client_groups', 'commercial_register')) {
            Schema::table('client_groups', function (Blueprint $t) {
                $t->string('commercial_register', 60)->nullable()->after('taxable');
            });
        }
    }

    public function down(): void
    {
        foreach (['clients', 'client_groups'] as $table) {
            if (Schema::hasColumn($table, 'commercial_register')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn('commercial_register'));
            }
        }
    }
};
