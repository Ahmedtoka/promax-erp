<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════
 * تحويل البضاعة من العربيات — ١٤ أغسطس ٢٠٢٦
 * ═══════════════════════════════════════════════════════════════
 *
 * طلب المالك: «التحويل من المخازن موجود؛ عاوز معاه تحويل من المندوب
 * للمخزن، ومن مندوب لمندوب، بسبب مكتوب، وبضاعة موجودة فعلاً — وعاوز
 * أعرف البضاعة اللي اتسحبت دي مصدرها إيه: عهدة عادية ولا أمر توريد
 * ولا تحويل سابق».
 *
 * ═══ ١. الفجوة الحقيقية: مصدر بند العهدة ═══
 *
 * `PickOrder::handOver` كان بيدمج بنود العهدة بمفتاح (منتج + باتش)
 * بس. يعني بضاعة اتحمّلت لأمر توريد معيّن (`pick_orders.purchase_order_id`)
 * كانت بتقع في **نفس الصف** بتاع بضاعة العهدة العادية لو الصنف
 * والباتش واحد — فمافيش أي طريقة نقول «القطع دي بتاعة أمر التوريد».
 * الأثر الوحيد كان على أمر التجهيز، وهو مالوش توزيع على القطع.
 *
 * الحل: عمودين على `custody_items`:
 *   `source`         custody | purchase_order | transfer | legacy
 *   `source_ref_id`  رقم أمر التوريد / رقم التحويل (0 لو مفيش)
 * والمفتاح الفريد بيتوسّع ليشملهم، فبضاعة أمر التوريد بتعمل **صف
 * مستقل** ومابتندمجش مع العهدة العادية.
 *
 * ⚠️ `source_ref_id` **NOT NULL default 0** مش nullable: MySQL بيعتبر
 * الـNULL مميّز في الـunique، فصفين بنفس (عهدة+صنف+باتش) و`NULL`
 * كانوا هيعدّوا الاتنين — والدمج الطبيعي بتاع العهدة العادية بيتكسر.
 *
 * ⚠️ الصفوف القديمة بتتعلّم `legacy` — مش `custody`. مانعرفش مصدرها،
 * والكذب عليها بـ«عهدة عادية» أسوأ من «مصدر غير محدد».
 *
 * ═══ ٢. عمود `transferred_out` ═══
 *
 * تحويل من مندوب لمندوب بيطلّع بضاعة من عربية من غير ما ترجع مخزن،
 * فمعادلة التصفية (المحمَّل = مباع + أوامر + هدايا + مرجع + الباقي)
 * كانت هتفضل ناقصة الحد ده للأبد. تحويل المندوب **للمخزن** بينزل
 * `returned` (خانة «مرجع للمخزن» الموجودة أصلاً في المعادلة وماكانش
 * فيه أي مسار بيكتب فيها).
 *
 * ═══ ٣. مستند التحويل — نفس الجدول مش جدول موازي ═══
 *
 * `stock_transfers` بياخد `kind` + طرفين اختياريين (`from_user_id` /
 * `to_user_id`) + `reason` + `created_by`. مستند واحد بترقيم واحد
 * (TRF-) وشاشة واحدة وورقة واحدة — جدول تاني كان معناه إن كل تعديل
 * على الورقة أو الصلاحيات يتعمل مرتين.
 *
 * ⚠️ `from_warehouse_id` و`to_warehouse_id` فضلوا NOT NULL عن قصد:
 * تغيير nullability لعمود عليه FK على سيرفر لايف مش ريبو جيت مخاطرة
 * مالهاش لزوم. التحويلات الميدانية بتترسي على مخزن العهدة (أو مخزن
 * الباتش لو العهدة مالهاش مخزن) — وده رقم حقيقي مفيد مش حشو.
 */
return new class extends Migration
{
    private function hasIndex(string $table, string $index): bool
    {
        foreach (Schema::getIndexes($table) as $existing) {
            if (($existing['name'] ?? null) === $index) {
                return true;
            }
        }

        return false;
    }

    public function up(): void
    {
        // ═══════════ ١. بنود العهدة: المصدر + المحوَّل ═══════════

        $sourceIsNew = Schema::hasTable('custody_items')
            && ! Schema::hasColumn('custody_items', 'source');

        if (Schema::hasTable('custody_items')) {
            Schema::table('custody_items', function (Blueprint $table) {
                if (! Schema::hasColumn('custody_items', 'source')) {
                    // custody = عهدة عادية · purchase_order = بضاعة أمر توريد
                    // transfer = جت بتحويل · legacy = بضاعة قديمة مصدرها مش متسجّل
                    $table->string('source', 20)->default('custody')->after('batch_id');
                }
                if (! Schema::hasColumn('custody_items', 'source_ref_id')) {
                    // رقم أمر التوريد أو التحويل — 0 يعني مفيش مرجع
                    $table->unsignedBigInteger('source_ref_id')->default(0)->after('source');
                }
                if (! Schema::hasColumn('custody_items', 'transferred_out')) {
                    // اتحوّل لمندوب تاني — بره «مرجع للمخزن» وبره «مباع»
                    $table->integer('transferred_out')->default(0)->after('returned');
                }
            });
        }

        // كل الصفوف الموجودة لحظة إضافة العمود مصدرها مش معروف
        if ($sourceIsNew) {
            DB::table('custody_items')->update(['source' => 'legacy']);
        }

        // ═══ الرقصة الرباعية للمفتاح الفريد (فخ 1553 موثّق) ═══
        // ١) الأعمدة فوق · ٢) فهرس بديل على عمود الـFK لوحده ·
        // ٣) دروب الفريد القديم · ٤) الفريد الجديد

        if (Schema::hasTable('custody_items')
            && ! $this->hasIndex('custody_items', 'custody_items_custody_id_index')) {
            Schema::table('custody_items', function (Blueprint $table) {
                $table->index('custody_id', 'custody_items_custody_id_index');
            });
        }

        if (Schema::hasTable('custody_items')
            && $this->hasIndex('custody_items', 'custody_items_line_unique')) {
            Schema::table('custody_items', function (Blueprint $table) {
                $table->dropUnique('custody_items_line_unique');
            });
        }

        if (Schema::hasTable('custody_items')
            && Schema::hasColumn('custody_items', 'source')
            && ! $this->hasIndex('custody_items', 'custody_items_line_src_unique')) {
            Schema::table('custody_items', function (Blueprint $table) {
                $table->unique(
                    ['custody_id', 'product_id', 'batch_id', 'source', 'source_ref_id'],
                    'custody_items_line_src_unique',
                );
            });
        }

        // ═══════════ ٢. مستند التحويل ═══════════

        if (Schema::hasTable('stock_transfers')) {
            Schema::table('stock_transfers', function (Blueprint $table) {
                if (! Schema::hasColumn('stock_transfers', 'kind')) {
                    // wh_wh = مخزن لمخزن · rep_wh = مندوب لمخزن · rep_rep = مندوب لمندوب
                    $table->string('kind', 20)->default('wh_wh')->after('number');
                }
                if (! Schema::hasColumn('stock_transfers', 'from_user_id')) {
                    $table->foreignId('from_user_id')->nullable()->after('from_warehouse_id')
                        ->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn('stock_transfers', 'to_user_id')) {
                    $table->foreignId('to_user_id')->nullable()->after('to_warehouse_id')
                        ->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn('stock_transfers', 'reason')) {
                    // ⚠️ nullable في السكيما عشان الشحنات القديمة، و**إجباري
                    // في الفاليديشن** لكل تحويل جديد أياً كان نوعه.
                    $table->text('reason')->nullable()->after('notes');
                }
                if (! Schema::hasColumn('stock_transfers', 'created_by')) {
                    $table->foreignId('created_by')->nullable()->after('received_by')
                        ->constrained('users')->nullOnDelete();
                }
            });
        }

        if (Schema::hasTable('stock_transfers')
            && ! $this->hasIndex('stock_transfers', 'stock_transfers_kind_index')) {
            Schema::table('stock_transfers', function (Blueprint $table) {
                $table->index('kind', 'stock_transfers_kind_index');
            });
        }

        // الشحنات القديمة: اللي بعتها هو اللي عملها
        if (Schema::hasTable('stock_transfers') && Schema::hasColumn('stock_transfers', 'created_by')) {
            DB::table('stock_transfers')->whereNull('created_by')
                ->update(['created_by' => DB::raw('sent_by')]);
        }

        // ═══════════ ٣. بند التحويل: البند الأصلي ومصدره ═══════════

        if (Schema::hasTable('stock_transfer_items')) {
            Schema::table('stock_transfer_items', function (Blueprint $table) {
                if (! Schema::hasColumn('stock_transfer_items', 'custody_item_id')) {
                    $table->foreignId('custody_item_id')->nullable()->after('source_batch_id')
                        ->constrained('custody_items')->nullOnDelete();
                }
                if (! Schema::hasColumn('stock_transfer_items', 'source')) {
                    // نسخة مجمّدة من مصدر بند العهدة لحظة التحويل
                    $table->string('source', 20)->nullable()->after('custody_item_id');
                }
                if (! Schema::hasColumn('stock_transfer_items', 'source_ref_id')) {
                    $table->unsignedBigInteger('source_ref_id')->nullable()->after('source');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('stock_transfer_items')) {
            if (Schema::hasColumn('stock_transfer_items', 'custody_item_id')) {
                Schema::table('stock_transfer_items', function (Blueprint $table) {
                    $table->dropConstrainedForeignId('custody_item_id');
                });
            }

            Schema::table('stock_transfer_items', function (Blueprint $table) {
                foreach (['source', 'source_ref_id'] as $column) {
                    if (Schema::hasColumn('stock_transfer_items', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('stock_transfers')) {
            if ($this->hasIndex('stock_transfers', 'stock_transfers_kind_index')) {
                Schema::table('stock_transfers', function (Blueprint $table) {
                    $table->dropIndex('stock_transfers_kind_index');
                });
            }

            foreach (['from_user_id', 'to_user_id', 'created_by'] as $column) {
                if (Schema::hasColumn('stock_transfers', $column)) {
                    Schema::table('stock_transfers', function (Blueprint $table) use ($column) {
                        $table->dropConstrainedForeignId($column);
                    });
                }
            }

            Schema::table('stock_transfers', function (Blueprint $table) {
                foreach (['kind', 'reason'] as $column) {
                    if (Schema::hasColumn('stock_transfers', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        // العكس بنفس الترتيب: الفريد الجديد يتشال، القديم يرجع، وبعدين الأعمدة
        if (Schema::hasTable('custody_items')) {
            if ($this->hasIndex('custody_items', 'custody_items_line_src_unique')) {
                Schema::table('custody_items', function (Blueprint $table) {
                    $table->dropUnique('custody_items_line_src_unique');
                });
            }

            if (! $this->hasIndex('custody_items', 'custody_items_line_unique')) {
                Schema::table('custody_items', function (Blueprint $table) {
                    $table->unique(['custody_id', 'product_id', 'batch_id'], 'custody_items_line_unique');
                });
            }

            Schema::table('custody_items', function (Blueprint $table) {
                foreach (['source', 'source_ref_id', 'transferred_out'] as $column) {
                    if (Schema::hasColumn('custody_items', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
