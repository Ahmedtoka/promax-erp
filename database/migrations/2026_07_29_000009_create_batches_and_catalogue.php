<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 1) بيانات الكتالوج الرسمية (باركود GS1، وزن، براند، صورة)
 * 2) الباتشات وتواريخ الصلاحية + الاستلام
 *
 * دوكترين الباتش:
 *   - الاستلام (goods_receipt) بيولّد باتش أو أكتر
 *   - الباتش هو مصدر الحقيقة للكمية والصلاحية في المخزن
 *   - العهدة والفاتورة بيشاوروا على الباتش عشان نقدر نرجّع أي شحنة لعميلها
 *   - الخروج بالـ FEFO: الأقرب انتهاءً يخرج الأول
 */
return new class extends Migration
{
    /** الفهرس ده موجود على الجدول ده؟ */
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
        // ---------- 1. الكتالوج ----------
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'barcode')) {
                // باركود وحدة الاستهلاك (GTIN-13)
                $table->string('barcode', 20)->nullable()->unique()->after('code');
            }
            if (! Schema::hasColumn('products', 'case_barcode')) {
                // باركود الكرتونة
                $table->string('case_barcode', 20)->nullable()->after('barcode');
            }
            if (! Schema::hasColumn('products', 'units_per_case')) {
                $table->unsignedSmallInteger('units_per_case')->nullable()->after('case_barcode');
            }
            if (! Schema::hasColumn('products', 'net_content')) {
                $table->decimal('net_content', 8, 2)->nullable()->after('unit_en');
            }
            if (! Schema::hasColumn('products', 'net_uom')) {
                // g / ml / piece
                $table->string('net_uom', 10)->nullable()->after('net_content');
            }
            if (! Schema::hasColumn('products', 'brand')) {
                $table->string('brand', 40)->nullable()->after('family');
            }
            if (! Schema::hasColumn('products', 'image_url')) {
                $table->string('image_url', 500)->nullable()->after('brand');
            }
            if (! Schema::hasColumn('products', 'gpc_category')) {
                $table->string('gpc_category', 200)->nullable()->after('image_url');
            }
            if (! Schema::hasColumn('products', 'shelf_life_months')) {
                // مدة الصلاحية بالشهور — منها بنحسب تاريخ الانتهاء من تاريخ الإنتاج
                $table->unsignedSmallInteger('shelf_life_months')->nullable()->after('gpc_category');
            }
        });

        // ---------- 2. إذون الاستلام ----------
        if (! Schema::hasTable('goods_receipts')) {
            Schema::create('goods_receipts', function (Blueprint $table) {
                $table->id();
                $table->string('number', 30)->unique();       // GRN-1001
                $table->date('received_on');
                $table->string('supplier')->nullable();        // المورّد / خط الإنتاج
                $table->string('reference', 80)->nullable();   // رقم إذن المورّد
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        // ---------- 3. الباتشات ----------
        if (! Schema::hasTable('batches')) {
            Schema::create('batches', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_id')->constrained()->cascadeOnDelete();
                $table->foreignId('goods_receipt_id')->nullable()
                    ->constrained('goods_receipts')->nullOnDelete();

                $table->string('batch_no', 60);
                $table->date('produced_on')->nullable();
                $table->date('expires_on');                    // إجباري — أساس الـ FEFO

                $table->integer('qty_received')->default(0);   // اللي دخل المخزن
                $table->integer('qty_remaining')->default(0);  // المتبقي في المخزن (بره العهد)
                $table->integer('qty_issued')->default(0);     // اللي طلع للعربيات
                $table->integer('qty_damaged')->default(0);    // تالف / مسحوب

                $table->decimal('cost', 10, 2)->nullable();    // تكلفة الوحدة وقت الاستلام
                $table->boolean('blocked')->default(false);    // موقوف يدوياً (recall مثلاً)
                $table->text('notes')->nullable();
                $table->timestamps();

                // نفس رقم الباتش ممكن يتكرر لمنتجات مختلفة، بس مش لنفس المنتج
                $table->unique(['product_id', 'batch_no']);
                // الفهرس ده هو اللي بيخلي الـ FEFO سريع
                $table->index(['product_id', 'expires_on']);
            });
        }

        // ---------- 4. ربط الحركة بالباتش ----------
        // العربية ممكن تشيل نفس الصنف من باتشين، فالمفتاح الفريد لازم يوسّع.
        //
        // ⚠️ MySQL بيستخدم الـ unique القديم (custody_id, product_id) كفهرس
        // للـ foreign key بتاع custody_id، فبيرفض نمسحه (error 1553) طول ما
        // مفيش فهرس بديل. فالترتيب هنا مهم وكل خطوة في ALTER لوحدها:
        //   1) الكولوم    2) فهرس بديل    3) نمسح القديم    4) الفريد الجديد

        if (! Schema::hasColumn('custody_items', 'batch_id')) {
            Schema::table('custody_items', function (Blueprint $table) {
                $table->foreignId('batch_id')->nullable()->after('product_id')
                    ->constrained('batches')->nullOnDelete();
            });
        }

        if (! $this->hasIndex('custody_items', 'custody_items_custody_id_index')) {
            Schema::table('custody_items', function (Blueprint $table) {
                $table->index('custody_id', 'custody_items_custody_id_index');
            });
        }

        if ($this->hasIndex('custody_items', 'custody_items_custody_id_product_id_unique')) {
            Schema::table('custody_items', function (Blueprint $table) {
                $table->dropUnique('custody_items_custody_id_product_id_unique');
            });
        }

        if (! $this->hasIndex('custody_items', 'custody_items_line_unique')) {
            Schema::table('custody_items', function (Blueprint $table) {
                $table->unique(['custody_id', 'product_id', 'batch_id'], 'custody_items_line_unique');
            });
        }

        Schema::table('invoice_items', function (Blueprint $table) {
            if (! Schema::hasColumn('invoice_items', 'batch_id')) {
                $table->foreignId('batch_id')->nullable()->after('product_id')
                    ->constrained('batches')->nullOnDelete();
            }
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('purchase_order_items', 'batch_id')) {
                $table->foreignId('batch_id')->nullable()->after('product_id')
                    ->constrained('batches')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        // بنعكس بنفس الترتيب: الفريد القديم يرجع الأول، وبعدين نمسح البديل
        if ($this->hasIndex('custody_items', 'custody_items_line_unique')) {
            Schema::table('custody_items', function (Blueprint $table) {
                $table->dropUnique('custody_items_line_unique');
            });
        }

        if (Schema::hasColumn('custody_items', 'batch_id')) {
            Schema::table('custody_items', function (Blueprint $table) {
                $table->dropConstrainedForeignId('batch_id');
            });
        }

        if (! $this->hasIndex('custody_items', 'custody_items_custody_id_product_id_unique')) {
            Schema::table('custody_items', function (Blueprint $table) {
                $table->unique(['custody_id', 'product_id']);
            });
        }

        if ($this->hasIndex('custody_items', 'custody_items_custody_id_index')) {
            Schema::table('custody_items', function (Blueprint $table) {
                $table->dropIndex('custody_items_custody_id_index');
            });
        }

        foreach (['invoice_items', 'purchase_order_items'] as $tableName) {
            if (Schema::hasColumn($tableName, 'batch_id')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropConstrainedForeignId('batch_id');
                });
            }
        }

        Schema::dropIfExists('batches');
        Schema::dropIfExists('goods_receipts');

        Schema::table('products', function (Blueprint $table) {
            foreach ([
                'barcode', 'case_barcode', 'units_per_case', 'net_content', 'net_uom',
                'brand', 'image_url', 'gpc_category', 'shelf_life_months',
            ] as $column) {
                if (Schema::hasColumn('products', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
