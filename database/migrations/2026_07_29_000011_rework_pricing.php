<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * إعادة بناء التسعير.
 * Pricing rework.
 *
 * ═══ الدوكترين الجديد ═══
 *  المنتج ليه تلاتة:
 *      cost       التكلفة (للربحية — مش سعر بيع)
 *      price_old  سعر البيع القديم
 *      price_new  سعر البيع الجديد
 *
 *  العميل بيتحاسب على **واحد منهم** حسب clients.price_list (old|new).
 *  وبعدين بينطبق عليه الخصم بالترتيب:
 *      العقد → خصم خاص للعميل → خصم السلسلة → خصم القناة
 *
 *  الفاتورة بتخزّن السعر والخصم **والتكلفة** لحظة البيع،
 *  عشان الربحية التاريخية ما تتأثرش بأي تعديل سعر بعد كده.
 *
 * ═══ الترحيل ═══
 *  price_cash → price_old   (سعر البيع اللي كان شغّال)
 *  price_hold → cost        (الـ 50% hold كان أقرب حاجة للتكلفة)
 *  price_new  = price_cash  (نفس القديم لحد ما تحدّده)
 *  الأعمدة التلاتة القديمة بتتشال بعد الترحيل.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------- 1. أعمدة التسعير الجديدة ----------
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'cost')) {
                $table->decimal('cost', 10, 2)->default(0)->after('unit_en');
            }
            if (! Schema::hasColumn('products', 'price_old')) {
                $table->decimal('price_old', 10, 2)->default(0)->after('cost');
            }
            if (! Schema::hasColumn('products', 'price_new')) {
                $table->decimal('price_new', 10, 2)->default(0)->after('price_old');
            }
            if (! Schema::hasColumn('products', 'price_changed_at')) {
                // إمتى السعر الجديد اتحدّد — للتقارير
                $table->date('price_changed_at')->nullable()->after('price_new');
            }
        });

        // ---------- 2. ترحيل الأسعار القديمة ----------
        if (Schema::hasColumn('products', 'price_cash')) {
            DB::statement('UPDATE products SET
                price_old = price_cash,
                price_new = price_cash,
                cost      = price_hold
                WHERE price_old = 0 AND price_new = 0');
        }

        // ---------- 3. شيل الأعمدة القديمة ----------
        foreach (['price_hold', 'price_70', 'price_cash'] as $column) {
            if (Schema::hasColumn('products', $column)) {
                Schema::table('products', fn (Blueprint $t) => $t->dropColumn($column));
            }
        }

        // ---------- 4. العميل: قائمة السعر والضريبة ----------
        Schema::table('clients', function (Blueprint $table) {
            if (! Schema::hasColumn('clients', 'price_list')) {
                // old = بيتحاسب بالسعر القديم، new = بالجديد
                $table->string('price_list', 10)->default('new')->after('uses_channel_discount');
            }
            // خانات الضريبة — متوقفة دلوقتي، جاهزة لما تتفعّل
            if (! Schema::hasColumn('clients', 'taxable')) {
                $table->boolean('taxable')->default(false)->after('price_list');
            }
            if (! Schema::hasColumn('clients', 'tax_rate')) {
                $table->decimal('tax_rate', 5, 4)->default(0)->after('taxable');
            }
            if (! Schema::hasColumn('clients', 'tax_id')) {
                $table->string('tax_id', 40)->nullable()->after('tax_rate');
            }
        });

        // ---------- 5. العقد: بنود أوضح ----------
        Schema::table('contracts', function (Blueprint $table) {
            if (! Schema::hasColumn('contracts', 'number')) {
                $table->string('number', 30)->nullable()->after('id');
            }
            if (! Schema::hasColumn('contracts', 'starts_at')) {
                $table->date('starts_at')->nullable()->after('terms');
            }
            if (! Schema::hasColumn('contracts', 'price_list')) {
                // العقد يقدر يثبّت قائمة سعر معيّنة؛ لو فاضي بناخد اللي على العميل
                $table->string('price_list', 10)->nullable()->after('discount');
            }
            if (! Schema::hasColumn('contracts', 'payment_days')) {
                // نفس معنى terms بس رقم عشان نحسب بيه أعمار الديون
                $table->unsignedSmallInteger('payment_days')->nullable()->after('terms');
            }
            if (! Schema::hasColumn('contracts', 'clauses')) {
                // بنود العقد — سطر لكل بند
                $table->json('clauses')->nullable()->after('note');
            }
            if (! Schema::hasColumn('contracts', 'active')) {
                $table->boolean('active')->default(true)->after('clauses');
            }
        });

        // ---------- 6. الفاتورة: لقطة التكلفة وقائمة السعر ----------
        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'price_list')) {
                $table->string('price_list', 10)->nullable()->after('payment');
            }
            if (! Schema::hasColumn('invoices', 'cost_total')) {
                // مجموع تكلفة البنود — الربح = total - cost_total
                $table->decimal('cost_total', 12, 2)->default(0)->after('total');
            }
            if (! Schema::hasColumn('invoices', 'discount_source')) {
                // مصدر الخصم لحظة البيع: عقد / خاص / سلسلة / قناة.
                // بيتخزن مش بيتحسب، عشان الفاتورة المطبوعة تفضل صح
                // حتى لو العميل اتغير عقده بعد كده.
                $table->string('discount_source', 30)->nullable()->after('discount_pct');
            }
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            if (! Schema::hasColumn('invoice_items', 'unit_cost')) {
                // تكلفة الوحدة لحظة البيع — من الباتش لو موجود، وإلا من المنتج
                $table->decimal('unit_cost', 10, 2)->default(0)->after('price');
            }
            if (! Schema::hasColumn('invoice_items', 'list_price')) {
                // السعر قبل الخصم — عشان الفاتورة توري الخصم على السطر
                $table->decimal('list_price', 10, 2)->default(0)->after('unit_cost');
            }
        });

        // ---------- 7. أوامر التوريد: نظام التسعير بقى old/new ----------
        if (Schema::hasColumn('purchase_orders', 'price_mode')) {
            DB::statement("UPDATE purchase_orders
                SET price_mode = CASE WHEN price_mode = 'cash' THEN 'new' ELSE 'old' END
                WHERE price_mode IN ('hold','p70','cash')");

            // ⚠️ الديفولت القديم كان 'hold' وهي قيمة مابقالهاش معنى.
            // لو صف اتعمل من غير price_mode كان هيبوظ سعره، فبنغيّر الديفولت.
            DB::statement("ALTER TABLE purchase_orders
                MODIFY price_mode VARCHAR(20) NOT NULL DEFAULT 'new'");
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            foreach (['price_hold', 'price_70', 'price_cash'] as $column) {
                if (! Schema::hasColumn('products', $column)) {
                    $table->decimal($column, 10, 2)->default(0);
                }
            }
        });

        // نرجّع الأسعار القديمة من الجديدة قبل ما نمسحها
        DB::statement('UPDATE products SET price_cash = price_old, price_hold = cost');

        Schema::table('products', function (Blueprint $table) {
            foreach (['cost', 'price_old', 'price_new', 'price_changed_at'] as $column) {
                if (Schema::hasColumn('products', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('clients', function (Blueprint $table) {
            foreach (['price_list', 'taxable', 'tax_rate', 'tax_id'] as $column) {
                if (Schema::hasColumn('clients', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('contracts', function (Blueprint $table) {
            foreach (['number', 'starts_at', 'price_list', 'payment_days', 'clauses', 'active'] as $column) {
                if (Schema::hasColumn('contracts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('invoices', function (Blueprint $table) {
            foreach (['price_list', 'cost_total', 'discount_source'] as $column) {
                if (Schema::hasColumn('invoices', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            foreach (['unit_cost', 'list_price'] as $column) {
                if (Schema::hasColumn('invoice_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
