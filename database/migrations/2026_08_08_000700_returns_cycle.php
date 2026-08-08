<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════
 * سايكل المرتجعات — مستند حقيقي بدل رد JSON (٨ أغسطس ٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **المرتجع قبل كده ماكانش له مستند خالص.** القيد الدائن كان
 * بيتكتب في `transactions` والبنود بتعيش في رد الـAPI بس — تروح
 * فين البضاعة، رجعت من أنهي فاتورة، اتسعّرت بكام، سليمة ولا تالفة:
 * كل ده كان بيضيع في اللحظة اللي المندوب بيقفل فيها الشاشة.
 *
 * الجداول دي بتخلّي المرتجع مستند زي الفاتورة: رقم، بنود، سعر
 * مربوط بالفاتورة الأصلية، وحالة كل قطعة.
 *
 * ⚠️ `returns` **كلمة محجوزة في MySQL** — أي SQL خام لازم backticks.
 * ولأن `Return` كلمة محجوزة في PHP كمان، الموديل اسمه `ClientReturn`.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ═══ 1. سياسة المرتجع على العميل ═══
        //
        // ⚠️ **على العميل مش على المندوب ولا على الحركة** (قرار المالك
        // ٨/٨/٢٠٢٦). العميل ممكن يكون مسموح له بأكتر من طريقة،
        // والمندوب بيشوف المسموح بيه **بس** ويختار قبل ما يعمل
        // المرتجع. سيبها اختيار حر للمندوب كان معناه إن كل مندوب
        // يتصرف حسب علاقته بالعميل.
        Schema::table('clients', function (Blueprint $table) {
            if (! Schema::hasColumn('clients', 'return_policies')) {
                $table->json('return_policies')->nullable()->after('payment_days_from');
            }
        });

        // ═══ 2. التالف في العهدة ═══
        //
        // ⚠️ **عمود مستقل عن `returned_in`.** السليم بيرجع للبيع
        // والتالف لأ — لو الاتنين في خانة واحدة، تصفية العهدة
        // بتقول رقم واحد والمخزن بيستلم بضاعة نصها مش صالحة من
        // غير ما يعرف.
        Schema::table('custody_items', function (Blueprint $table) {
            if (! Schema::hasColumn('custody_items', 'damaged_in')) {
                $table->unsignedInteger('damaged_in')->default(0)->after('returned_in');
            }
        });

        // ═══ 3. مستند المرتجع ═══
        if (! Schema::hasTable('returns')) {
            Schema::create('returns', function (Blueprint $table) {
                $table->id();
                $table->string('number', 30)->unique();

                $table->foreignId('client_id')->constrained()->cascadeOnDelete();
                // المندوب — `null` للمرتجع اللي بيتعمل من الـERP
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('visit_id')->nullable()->constrained('visits')->nullOnDelete();
                $table->foreignId('custody_id')->nullable()->constrained('custodies')->nullOnDelete();

                // app | erp — مصدر المستند، عشان المراجعة تعرف مين كتبه
                $table->string('source', 10)->default('app');
                // cash | account | exchange | credit_next
                $table->string('policy', 20);

                // ⚠️ **عقيدة الأرقام التلاتة** زي الفاتورة بالظبط:
                // `total` صافي قبل الضريبة، و`grand_total` هو اللي
                // بيتقيّد في الليدجر.
                $table->decimal('subtotal', 14, 2)->default(0);
                $table->decimal('discount', 14, 2)->default(0);
                $table->decimal('total', 14, 2)->default(0);
                $table->decimal('tax_total', 14, 2)->default(0);
                $table->decimal('grand_total', 14, 2)->default(0);

                $table->unsignedInteger('good_units')->default(0);
                $table->unsignedInteger('damaged_units')->default(0);

                // القيود المتولدة — عشان المراجعة تمشي من المستند للقيد
                $table->foreignId('transaction_id')->nullable()
                    ->constrained('transactions')->nullOnDelete();
                $table->foreignId('refund_transaction_id')->nullable()
                    ->constrained('transactions')->nullOnDelete();

                // ⚠️ **مفتاح منع التكرار** (تدقيق ٨/٨): المرتجع ماكانش
                // عنده idempotency، فـN نداءات من أبلكيشن بشبكة ضعيفة
                // = N قيود دائنة. الأبلكيشن بيولّد المفتاح مرة واحدة
                // للشاشة، وإعادة الإرسال بترجّع نفس المستند.
                $table->string('idem_key', 64)->nullable()->unique();

                $table->text('note')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['client_id', 'created_at']);
                $table->index(['user_id', 'created_at']);
            });
        }

        // ═══ 4. بنود المرتجع ═══
        if (! Schema::hasTable('return_items')) {
            Schema::create('return_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('return_id')->constrained('returns')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained()->cascadeOnDelete();

                // ⚠️ **الربط بالفاتورة الأصلية هو اللي بيحل مشكلتين
                // مع بعض**: السعر (المرتجع بسعر يوم البيع مش سعر
                // النهارده) والسقف (مايرجّعش أكتر من اللي اشتراه).
                $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('invoice_item_id')->nullable()
                    ->constrained('invoice_items')->nullOnDelete();

                $table->unsignedInteger('qty');
                // good | damaged
                $table->string('condition', 10)->default('good');

                $table->decimal('list_price', 10, 2)->default(0);
                $table->decimal('price', 10, 2)->default(0);
                $table->decimal('total', 14, 2)->default(0);
                $table->decimal('tax_rate', 5, 4)->default(0);
                $table->decimal('tax', 14, 2)->default(0);

                $table->timestamps();

                $table->index(['product_id']);
                // اللي بيحسب «رجع كام من السطر ده» بيقرا بالإندكس ده
                $table->index(['invoice_item_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('return_items');
        Schema::dropIfExists('returns');

        Schema::table('custody_items', function (Blueprint $table) {
            if (Schema::hasColumn('custody_items', 'damaged_in')) {
                $table->dropColumn('damaged_in');
            }
        });

        Schema::table('clients', function (Blueprint $table) {
            if (Schema::hasColumn('clients', 'return_policies')) {
                $table->dropColumn('return_policies');
            }
        });
    }
};
