<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════
 * الموردين والمشتريات — supplier_*
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **`purchase_orders` الموجود مالوش أي دعوة بالموردين.** ده
 * طلبيات العملاء اللي السواقين بيوصّلوها — والقرار المسجّل في سكيل
 * promax-suppliers من 2026-07-29: الموردين ياخدوا جداول `supplier_*`
 * بأسماء واضحة، وممنوع نلمس جداول التوزيع.
 *
 * ⚠️ **كشف حساب الموردين جدول منفصل عن `transactions`.** حسابات
 * العملاء شغّالة ومتوازنة — خلط طرفين في جدول واحد بعمود `party_type`
 * كان معناه إن كل كويري قديمة نسيت الفلتر تجمع فلوس الموردين على
 * مديونيات العملاء.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('suppliers')) {
            Schema::create('suppliers', function (Blueprint $table) {
                $table->id();
                $table->string('code', 20)->unique();          // SUP-001
                $table->string('name', 190);
                $table->string('name_en', 190)->nullable();
                $table->string('phone', 30)->nullable();
                $table->string('contact_person', 190)->nullable();
                $table->string('address', 190)->nullable();
                $table->string('tax_id', 40)->nullable();
                // شروط السداد المتفق عليها — بتتورث على الفواتير
                $table->unsignedSmallInteger('payment_days')->nullable();
                $table->text('notes')->nullable();
                // ⚠️ الرصيد تجميعة من دفتره — بيتعاد حسابه في
                // Supplier::recalculate() بعد كل قيد. موجب = علينا له.
                $table->decimal('balance', 14, 2)->default(0);
                $table->boolean('active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('supplier_orders')) {
            Schema::create('supplier_orders', function (Blueprint $table) {
                $table->id();
                $table->string('number', 30)->unique();        // SPO-1001
                $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
                // المخزن اللي البضاعة جاية له
                $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
                // ⚠️ مفيش موافقات (قرار المالك 2026-08-02) — الأمر بيتفتح
                // «مفتوح» على طول: open → received → closed / cancelled
                $table->string('status', 15)->default('open');
                $table->date('ordered_on');
                $table->date('expected_on')->nullable();
                $table->decimal('total', 14, 2)->default(0);
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->timestamps();

                $table->index(['supplier_id', 'status']);
            });
        }

        if (! Schema::hasTable('supplier_order_items')) {
            Schema::create('supplier_order_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('supplier_order_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_id')->constrained()->restrictOnDelete();
                $table->unsignedInteger('qty');
                // ⚠️ تكلفة الأمر ده — مش `products.cost`. التكلفة القياسية
                // ثابتة يدوي (قرار 2026-07-31)، وسعر الشراء الفعلي بيتفاوض
                // كل مرة. الفرق بينهم هو اللي بيقول المورد غلّى ولا رخّص.
                $table->decimal('unit_cost', 10, 2)->default(0);
                $table->unsignedInteger('received_qty')->default(0);
                $table->timestamps();

                $table->unique(['supplier_order_id', 'product_id']);
            });
        }

        if (! Schema::hasTable('supplier_invoices')) {
            Schema::create('supplier_invoices', function (Blueprint $table) {
                $table->id();
                $table->string('number', 30)->unique();        // SINV-1001
                $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
                $table->foreignId('supplier_order_id')->nullable()
                    ->constrained()->nullOnDelete();
                // رقم فاتورة المورد نفسه — الورقة اللي جت مع البضاعة
                $table->string('supplier_ref', 60)->nullable();
                $table->date('invoice_date');
                $table->date('due_on')->nullable();
                $table->decimal('subtotal', 14, 2)->default(0);
                $table->decimal('tax', 14, 2)->default(0);
                $table->decimal('total', 14, 2)->default(0);
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->timestamps();

                $table->index(['supplier_id', 'invoice_date']);
            });
        }

        if (! Schema::hasTable('supplier_payments')) {
            Schema::create('supplier_payments', function (Blueprint $table) {
                $table->id();
                $table->string('number', 30)->unique();        // SPAY-1001
                $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
                $table->date('paid_on');
                $table->decimal('amount', 14, 2);
                // cash / transfer / cheque
                $table->string('method', 20)->default('cash');
                $table->string('reference', 80)->nullable();
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('supplier_transactions')) {
            Schema::create('supplier_transactions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
                $table->date('date');
                // invoice / payment / opening / adjust
                $table->string('kind', 15);
                // ⚠️ **دائن = علينا له، مدين = دفعناه.** عكس دفتر العملاء
                // بالظبط — الفاتورة بتزوّد اللي علينا والدفعة بتقلله.
                $table->decimal('debit', 14, 2)->default(0);
                $table->decimal('credit', 14, 2)->default(0);
                $table->string('memo', 190)->nullable();
                // القيد مربوط بمستنده — فاتورة أو دفعة
                $table->nullableMorphs('source');
                $table->timestamps();

                $table->index(['supplier_id', 'date']);
            });
        }

        // ═══ ربط إذن الاستلام الموجود بالمورد وأمر الشراء ═══
        // ⚠️ **مش جدول استلام جديد.** `goods_receipts` هو اللي بيعمل
        // الباتشات ويغذي المخزون من يوم ما اتبنى — استلام المورد بيمرّ
        // من نفس الباب، بس بقى معلّم بمصدره.
        Schema::table('goods_receipts', function (Blueprint $table) {
            if (! Schema::hasColumn('goods_receipts', 'supplier_id')) {
                $table->foreignId('supplier_id')->nullable()
                    ->after('warehouse_id')->constrained()->nullOnDelete();
            }
            if (! Schema::hasColumn('goods_receipts', 'supplier_order_id')) {
                $table->foreignId('supplier_order_id')->nullable()
                    ->after('supplier_id')->constrained()->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipts', function (Blueprint $table) {
            if (Schema::hasColumn('goods_receipts', 'supplier_order_id')) {
                $table->dropConstrainedForeignId('supplier_order_id');
            }
            if (Schema::hasColumn('goods_receipts', 'supplier_id')) {
                $table->dropConstrainedForeignId('supplier_id');
            }
        });

        Schema::dropIfExists('supplier_transactions');
        Schema::dropIfExists('supplier_payments');
        Schema::dropIfExists('supplier_invoices');
        Schema::dropIfExists('supplier_order_items');
        Schema::dropIfExists('supplier_orders');
        Schema::dropIfExists('suppliers');
    }
};
