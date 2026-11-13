<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * (١٣/١١ — أحمد: «عاوزين حل للتكرار مرة واحدة») قيد فريد في الداتابيز:
 * لكل فاتورة / أمر توريد / مرتجع **صف واحد بالكتير** من كل نوع مربوط —
 * بيع، أمانة، تحصيل نقدي مع المستند، مرتجع (دائن)، رد كاش.
 *
 * `doc_link` عمود محسوب مخزّن: «kind|source_type|source_id» للأنواع والمصادر
 * دي بس، وNULL لأي حاجة تانية (التحصيل الميداني على الزيارة، المكتبي،
 * الافتتاحي…) — والـUNIQUE بيسمح بـNULL كتير. فالتحصيل الميداني مايتأثرش.
 *
 * ⚠️ لو فيه تكرار موجود الميجريشن بيرفض ويطبع المكرر — شغّل
 * `php artisan review:audit --fix --apply` الأول وبعدين الميجريشن تاني.
 * ⚠️ إضافة عمود + فهرس بس — مفيش صف بيتغير.
 */
return new class extends Migration
{
    private const SOURCES = ['App\\Models\\Invoice', 'App\\Models\\PurchaseOrder', 'App\\Models\\ClientReturn'];

    private const KINDS = ['sale', 'consignment', 'collection', 'return', 'refund'];

    public function up(): void
    {
        if (Schema::hasColumn('transactions', 'doc_link')) {
            return;
        }

        $dups = DB::table('transactions')->whereIn('source_type', self::SOURCES)->whereIn('kind', self::KINDS)
            ->groupBy('kind', 'source_type', 'source_id')->havingRaw('COUNT(*) > 1')
            ->selectRaw('kind, source_type, source_id, COUNT(*) AS n, GROUP_CONCAT(id) AS ids')->get();
        if ($dups->isNotEmpty()) {
            throw new RuntimeException("Duplicate document-linked ledger rows exist — run `php artisan review:audit --fix --apply` first:\n"
                .$dups->map(fn ($d) => "  {$d->kind} {$d->source_type}#{$d->source_id} rows {$d->ids}")->implode("\n"));
        }

        $q = fn (array $l) => implode(',', array_map(fn ($v) => DB::getPdo()->quote($v), $l));
        DB::statement('ALTER TABLE `transactions` ADD COLUMN `doc_link` VARCHAR(120) GENERATED ALWAYS AS ('
            .'CASE WHEN `source_type` IN ('.$q(self::SOURCES).') AND `kind` IN ('.$q(self::KINDS).') '
            ."THEN CONCAT(`kind`, '|', `source_type`, '|', `source_id`) ELSE NULL END) STORED");
        DB::statement('ALTER TABLE `transactions` ADD UNIQUE INDEX `transactions_doc_link_unique` (`doc_link`)');
    }

    public function down(): void
    {
        if (Schema::hasColumn('transactions', 'doc_link')) {
            DB::statement('ALTER TABLE `transactions` DROP INDEX `transactions_doc_link_unique`');
            DB::statement('ALTER TABLE `transactions` DROP COLUMN `doc_link`');
        }
    }
};
