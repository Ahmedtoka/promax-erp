<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ═══ أسعار غير شاملة بـ٤ أرقام عشرية (قرار المالك ١٠/١١/٢٠٢٦) ═══
 *
 * السعر المتخزّن غير شامل دايماً، والورق بيتحسب بالشامل (٦٣، ١٠١٫٢٥…).
 * ٦٣ ÷ 1.14 = 55.2632 — بالرقمين كان بيبقى 55.26 والفاتورة تبعد عن الورق.
 * سعر الوحدة (القايمة والصنف وسطر الفاتورة وأمر التوريد) بقى decimal(14,4).
 * إجماليات السطور والضريبة فاضلة بالقرش (decimal(14,2)) — دي فلوس.
 *
 * ⚠️ توسيع بس: مفيش رقم موجود بيتغيّر (2 → 4 أرقام بيزوّد أصفار).
 */
return new class extends Migration
{
    private const COLS = [
        'price_list_items' => ['price'],
        'products' => ['price_old', 'price_new'],
        'invoice_items' => ['price', 'list_price'],
        'purchase_order_items' => ['price', 'list_price'],
    ];

    private function noDefault(string $table, string $col): bool
    {
        return in_array($table, ['invoice_items', 'purchase_order_items'], true) && $col === 'price';
    }

    public function up(): void
    {
        foreach (self::COLS as $table => $cols) {
            Schema::table($table, function (Blueprint $t) use ($table, $cols) {
                foreach ($cols as $c) {
                    if (Schema::hasColumn($table, $c)) {
                        // نفس الـnull/default بالظبط: سعر السطر من غير default، والباقي 0
                        $col = $t->decimal($c, 14, 4);
                        $this->noDefault($table, $c) ? $col->change() : $col->default(0)->change();
                    }
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::COLS as $table => $cols) {
            Schema::table($table, function (Blueprint $t) use ($table, $cols) {
                foreach ($cols as $c) {
                    if (Schema::hasColumn($table, $c)) {
                        $col = $t->decimal($c, $table === 'purchase_order_items' && $c === 'list_price' ? 12 : 10, 2);
                        $this->noDefault($table, $c) ? $col->change() : $col->default(0)->change();
                    }
                }
            });
        }
    }
};
