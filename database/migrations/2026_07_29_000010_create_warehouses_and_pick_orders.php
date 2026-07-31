<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المخازن والأرفف وحركة البضاعة.
 * Warehouses, shelf locations, transfers and picking orders.
 *
 * ═══ دوكترين حركة المخزون ═══
 *  batch_locations = مصدر الحقيقة الوحيد للكمية في المخزن
 *      (منتج + باتش + رف) → كمية
 *  batches.qty_remaining = مجموع batch_locations لنفس الباتش (بيتحسب، مش بيتكتب يدوي)
 *  stocks = رصيد إجمالي للمنتج للعرض السريع، بيتحدّث من الباتشات
 *
 * ═══ الفلو ═══
 *  المصنع → أمر تحويل (stock_transfers) → المعادي تستلم
 *  استلام (goods_receipts) → باتشات → ترصيف على أرفف (batch_locations)
 *  المدير يطلب → أمر تجهيز (pick_orders) بأرقام الأرفف
 *  المخزن يجهّز → "جاهز" برقم → المندوب يستلم ويعدّل → ينزل عهدة
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------- 1. المخازن ----------
        if (! Schema::hasTable('warehouses')) {
            Schema::create('warehouses', function (Blueprint $table) {
                $table->id();
                $table->string('code', 20)->unique();          // FAC / MAADI
                $table->string('name');
                $table->string('name_en')->nullable();
                // factory = المصنع (بيورّد)، branch = فرع بيوزّع منه المناديب
                $table->string('type', 20)->default('branch');
                $table->string('address')->nullable();
                $table->decimal('lat', 10, 7)->nullable();
                $table->decimal('lng', 10, 7)->nullable();
                $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
                $table->boolean('active')->default(true);
                $table->timestamps();
            });
        }

        // ---------- 2. الأرفف ----------
        // الكود بالشكل A03 = ستاند A، الرف الثالث
        if (! Schema::hasTable('locations')) {
            Schema::create('locations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
                $table->string('code', 20);                    // A03
                $table->string('stand', 5);                    // A
                $table->unsignedSmallInteger('level');         // 3
                // الرف اللي بيتحط فيه الأقرب انتهاءً — بيطلع الأول في التجهيز
                $table->boolean('is_pick_face')->default(false);
                $table->unsignedInteger('capacity')->nullable();
                $table->text('notes')->nullable();
                $table->boolean('active')->default(true);
                $table->timestamps();

                $table->unique(['warehouse_id', 'code']);
                $table->index(['warehouse_id', 'stand', 'level']);
            });
        }

        // ---------- 3. رصيد كل باتش على كل رف ----------
        if (! Schema::hasTable('batch_locations')) {
            Schema::create('batch_locations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('batch_id')->constrained()->cascadeOnDelete();
                $table->foreignId('location_id')->constrained()->cascadeOnDelete();
                // بنكرّر product_id هنا عشان الاستعلامات والتقارير تبقى سريعة
                $table->foreignId('product_id')->constrained()->cascadeOnDelete();
                $table->integer('qty')->default(0);
                $table->timestamps();

                $table->unique(['batch_id', 'location_id']);
                $table->index(['location_id', 'product_id']);
                $table->index('product_id');
            });
        }

        // ---------- 4. الباتش بقى تابع لمخزن ----------
        Schema::table('batches', function (Blueprint $table) {
            if (! Schema::hasColumn('batches', 'warehouse_id')) {
                $table->foreignId('warehouse_id')->nullable()->after('product_id')
                    ->constrained()->nullOnDelete();
            }
        });

        // نفس رقم الباتش ممكن يبقى موجود في المصنع وفي المعادي — صفين مستقلين.
        // فالمفتاح الفريد لازم يوسّع من (منتج + باتش) لـ (منتج + باتش + مخزن).
        $batchIndexes = collect(Schema::getIndexes('batches'))->pluck('name');

        if ($batchIndexes->contains('batches_product_id_batch_no_unique')) {
            Schema::table('batches', fn (Blueprint $t) => $t
                ->dropUnique('batches_product_id_batch_no_unique'));
        }
        if (! $batchIndexes->contains('batches_line_unique')) {
            Schema::table('batches', fn (Blueprint $t) => $t
                ->unique(['product_id', 'batch_no', 'warehouse_id'], 'batches_line_unique'));
        }

        Schema::table('goods_receipts', function (Blueprint $table) {
            if (! Schema::hasColumn('goods_receipts', 'warehouse_id')) {
                $table->foreignId('warehouse_id')->nullable()->after('number')
                    ->constrained()->nullOnDelete();
            }
            if (! Schema::hasColumn('goods_receipts', 'source_warehouse_id')) {
                // لو الاستلام جاي من تحويل من مخزن تاني
                $table->foreignId('source_warehouse_id')->nullable()->after('warehouse_id')
                    ->constrained('warehouses')->nullOnDelete();
            }
            if (! Schema::hasColumn('goods_receipts', 'status')) {
                // draft = لسه بيتسجل، posted = دخل المخزن خلاص
                $table->string('status', 20)->default('posted')->after('received_on');
            }
        });

        // ---------- 5. أوامر التحويل بين المخازن ----------
        if (! Schema::hasTable('stock_transfers')) {
            Schema::create('stock_transfers', function (Blueprint $table) {
                $table->id();
                $table->string('number', 30)->unique();        // TRF-1001
                $table->foreignId('from_warehouse_id')->constrained('warehouses')->cascadeOnDelete();
                $table->foreignId('to_warehouse_id')->constrained('warehouses')->cascadeOnDelete();
                // sent = طلع من المصنع، received = وصل واتأكد، cancelled
                $table->string('status', 20)->default('sent');
                $table->date('sent_on');
                $table->date('received_on')->nullable();
                $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
                // إذن الاستلام اللي اتولد لما اتأكد الوصول
                $table->foreignId('goods_receipt_id')->nullable()
                    ->constrained('goods_receipts')->nullOnDelete();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['to_warehouse_id', 'status']);
            });

        }

        if (! Schema::hasTable('stock_transfer_items')) {
            Schema::create('stock_transfer_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('stock_transfer_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_id')->constrained()->cascadeOnDelete();
                $table->string('batch_no', 60);                // رقم الباتش من المصنع
                $table->date('produced_on')->nullable();
                $table->date('expires_on')->nullable();
                $table->integer('qty_sent');
                $table->integer('qty_received')->nullable();   // بيتملي عند الاستلام
                $table->decimal('cost', 10, 2)->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        // ---------- 6. أوامر التجهيز ----------
        if (! Schema::hasTable('pick_orders')) {
            Schema::create('pick_orders', function (Blueprint $table) {
                $table->id();
                $table->string('number', 30)->unique();        // PCK-1001
                $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
                // المندوب اللي هيستلم
                $table->foreignId('assigned_to')->constrained('users')->cascadeOnDelete();
                $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('picked_by')->nullable()->constrained('users')->nullOnDelete();

                // van_load  = تحميل عهدة عادي
                // customer_po = أمر توريد لعميل/سلسلة
                // replenishment = طلب ريفيل من بروموتر
                $table->string('purpose', 20)->default('van_load');

                // requested → picking → ready → handed → cancelled
                $table->string('status', 20)->default('requested');

                // مصدر الطلب لو جاي من PO أو طلب ريفيل
                $table->foreignId('purchase_order_id')->nullable()
                    ->constrained('purchase_orders')->nullOnDelete();
                $table->foreignId('replenishment_request_id')->nullable()
                    ->constrained('replenishment_requests')->nullOnDelete();
                // العهدة اللي البضاعة نزلت عليها بعد التسليم
                $table->foreignId('custody_id')->nullable()
                    ->constrained('custodies')->nullOnDelete();

                $table->date('needed_on')->nullable();         // مطلوب يجهز بتاريخ
                $table->timestamp('ready_at')->nullable();
                $table->timestamp('handed_at')->nullable();
                // فيه فرق بين المجهّز واللي المندوب استلمه؟
                $table->boolean('has_variance')->default(false);
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['warehouse_id', 'status']);
                $table->index(['assigned_to', 'status']);
            });

        }

        if (! Schema::hasTable('pick_order_items')) {
            Schema::create('pick_order_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('pick_order_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_id')->constrained()->cascadeOnDelete();
                // الباتش والرف اللي الـ FEFO اقترحهم (أو اللي المخزن غيّرهم فعلاً)
                $table->foreignId('batch_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();

                $table->integer('qty_requested');
                $table->integer('qty_picked')->nullable();     // اللي المخزن جهّزه
                $table->integer('qty_received')->nullable();   // اللي المندوب عدّه واستلمه
                $table->text('variance_note')->nullable();     // سبب الفرق
                $table->timestamps();

                $table->index(['pick_order_id', 'product_id']);
            });
        }

        // ---------- 7. العهدة بقت تابعة لمخزن ----------
        Schema::table('custodies', function (Blueprint $table) {
            if (! Schema::hasColumn('custodies', 'warehouse_id')) {
                $table->foreignId('warehouse_id')->nullable()->after('user_id')
                    ->constrained()->nullOnDelete();
            }
        });

        // ---------- 8. تخصيص المناطق والعملاء ----------
        // العميل ليه مندوب واحد مسئول عنه (حصري)
        Schema::table('clients', function (Blueprint $table) {
            if (! Schema::hasColumn('clients', 'rep_id')) {
                $table->foreignId('rep_id')->nullable()->after('zone_id')
                    ->constrained('users')->nullOnDelete();
                $table->index('rep_id');
            }
        });

        // المندوب ممكن يكون مسئول عن أكتر من زون
        if (! Schema::hasTable('zone_user')) {
            Schema::create('zone_user', function (Blueprint $table) {
                $table->id();
                $table->foreignId('zone_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                // يوم الزيارة الأسبوعي للزون ده لهذا المندوب
                $table->string('visit_day', 20)->nullable();
                $table->timestamps();

                $table->unique(['zone_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('zone_user');

        if (Schema::hasColumn('clients', 'rep_id')) {
            Schema::table('clients', fn (Blueprint $t) => $t->dropConstrainedForeignId('rep_id'));
        }
        if (Schema::hasColumn('custodies', 'warehouse_id')) {
            Schema::table('custodies', fn (Blueprint $t) => $t->dropConstrainedForeignId('warehouse_id'));
        }

        Schema::dropIfExists('pick_order_items');
        Schema::dropIfExists('pick_orders');
        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');

        foreach (['status', 'source_warehouse_id', 'warehouse_id'] as $column) {
            if (! Schema::hasColumn('goods_receipts', $column)) {
                continue;
            }
            Schema::table('goods_receipts', function (Blueprint $table) use ($column) {
                $column === 'status'
                    ? $table->dropColumn($column)
                    : $table->dropConstrainedForeignId($column);
            });
        }

        // نرجّع المفتاح الفريد القديم قبل ما نشيل عمود المخزن
        $batchIndexes = collect(Schema::getIndexes('batches'))->pluck('name');

        if ($batchIndexes->contains('batches_line_unique')) {
            Schema::table('batches', fn (Blueprint $t) => $t->dropUnique('batches_line_unique'));
        }
        if (! $batchIndexes->contains('batches_product_id_batch_no_unique')) {
            Schema::table('batches', fn (Blueprint $t) => $t->unique(['product_id', 'batch_no']));
        }
        if (Schema::hasColumn('batches', 'warehouse_id')) {
            Schema::table('batches', fn (Blueprint $t) => $t->dropConstrainedForeignId('warehouse_id'));
        }

        Schema::dropIfExists('batch_locations');
        Schema::dropIfExists('locations');
        Schema::dropIfExists('warehouses');
    }
};
