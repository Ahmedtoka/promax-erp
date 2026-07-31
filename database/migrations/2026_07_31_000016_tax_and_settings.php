<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════
 * الضريبة + إعدادات الشركة
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **معنى `total` مابيتغيرش.** كان وهيفضل **صافي المبيعات بعد الخصم
 * وقبل الضريبة**. كل تقرير في السيستم بيجمع `invoices.total` وبيقصد بيه
 * المبيعات — لو حطينا الضريبة جواه، كل رقم مبيعات وكل هامش ربح وكل
 * عمولة عقد هتتحرك من غير ما حد يغيّر سطر في التقارير دي.
 *
 * اللي العميل بيدفعه اسمه `grand_total` = `total` + `tax_total`، وهو
 * **الرقم الوحيد** اللي بيتقيّد في كشف الحساب.
 *
 * الفواتير القديمة: `grand_total = total` و `tax_total = 0` — صح تماماً،
 * لأنها اتعملت فعلاً من غير ضريبة.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ═══════════ إعدادات الشركة ═══════════
        // مفتاح/قيمة بدل ما نحط بيانات الشركة في .env — البيانات دي
        // (الرقم الضريبي، كود النشاط، العنوان) بتظهر على كل فاتورة وفي
        // ملف مصلحة الضرائب، ولازم اليوزر يقدر يعدّلها من شاشة.
        if (! Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $table) {
                $table->id();
                $table->string('key', 80)->unique();
                $table->text('value')->nullable();
                $table->timestamps();
            });
        }

        // ═══════════ الفواتير ═══════════
        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'tax_total')) {
                $table->decimal('tax_total', 14, 2)->default(0)->after('total');
            }
            if (! Schema::hasColumn('invoices', 'grand_total')) {
                $table->decimal('grand_total', 14, 2)->default(0)->after('tax_total');
            }
            // حالة الرفع لمصلحة الضرائب
            if (! Schema::hasColumn('invoices', 'eta_status')) {
                $table->string('eta_status', 20)->default('none')->after('grand_total');
            }
            if (! Schema::hasColumn('invoices', 'eta_uuid')) {
                $table->string('eta_uuid', 80)->nullable()->after('eta_status');
            }
            if (! Schema::hasColumn('invoices', 'eta_submitted_at')) {
                $table->timestamp('eta_submitted_at')->nullable()->after('eta_uuid');
            }
        });

        // ⚠️ الفواتير القديمة اتعملت من غير ضريبة فعلاً، فالمستحق =
        // الصافي. من غير النسخة دي كل فاتورة قديمة هتبان بإجمالي صفر.
        \Illuminate\Support\Facades\DB::table('invoices')
            ->where('grand_total', 0)
            ->update(['grand_total' => \Illuminate\Support\Facades\DB::raw('`total`')]);

        Schema::table('invoice_items', function (Blueprint $table) {
            if (! Schema::hasColumn('invoice_items', 'tax_rate')) {
                $table->decimal('tax_rate', 5, 4)->default(0)->after('total');
            }
            if (! Schema::hasColumn('invoice_items', 'tax')) {
                $table->decimal('tax', 14, 2)->default(0)->after('tax_rate');
            }
        });

        // ═══════════ الليدجر: نصيب الضريبة من القيد ═══════════
        // ⚠️ القيد بقى بالإجمالي شامل الضريبة، وده صح للمديونية.
        // بس **عمولات العقود** بتتحسب من مشتريات العميل، ولو الأساس
        // شامل الضريبة، عمولة 10% بتطلع 11.4% — فلوس بتخرج فعلاً كل
        // تسوية. بنخزّن نصيب الضريبة على القيد نفسه عشان أي حساب
        // يحتاج «الصافي» يلاقيه من غير ما يرجع للمستندات.
        Schema::table('transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('transactions', 'tax')) {
                $table->decimal('tax', 14, 2)->default(0)->after('credit');
            }
        });

        // ═══════════ أوامر التوريد ═══════════
        Schema::table('purchase_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('purchase_orders', 'tax_total')) {
                $table->decimal('tax_total', 14, 2)->default(0)->after('total');
            }
            if (! Schema::hasColumn('purchase_orders', 'grand_total')) {
                $table->decimal('grand_total', 14, 2)->default(0)->after('tax_total');
            }
        });

        \Illuminate\Support\Facades\DB::table('purchase_orders')
            ->where('grand_total', 0)
            ->update(['grand_total' => \Illuminate\Support\Facades\DB::raw('`total`')]);

        Schema::table('purchase_order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('purchase_order_items', 'tax_rate')) {
                $table->decimal('tax_rate', 5, 4)->default(0)->after('total');
            }
            if (! Schema::hasColumn('purchase_order_items', 'tax')) {
                $table->decimal('tax', 14, 2)->default(0)->after('tax_rate');
            }
        });

        // ═══════════ العميل: نوعه عند مصلحة الضرائب ═══════════
        // ⚠️ `clients.taxable` و `tax_rate` و `tax_id` **موجودين من قبل**
        // (مايجريشن 000011) — مانلمسهمش. الناقص بس نوع المستلم.
        Schema::table('clients', function (Blueprint $table) {
            if (! Schema::hasColumn('clients', 'eta_type')) {
                $table->string('eta_type', 1)->default('B')->after('tax_id');  // B شركة / P فرد
            }
        });

        // ═══════════ المنتج: الأصناف المعفاة ═══════════
        // بعض السلع الغذائية معفاة. من غير الخانة دي هنحسب ضريبة على
        // صنف معفى ونطلع فاتورة غلط قانوناً.
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'taxable')) {
                $table->boolean('taxable')->default(true)->after('active');
            }
            if (! Schema::hasColumn('products', 'tax_rate')) {
                // صفر = استخدم النسبة العامة. القيمة كسر (0.1400 = 14%)
                $table->decimal('tax_rate', 5, 4)->default(0)->after('taxable');
            }
            if (! Schema::hasColumn('products', 'eta_code')) {
                $table->string('eta_code', 30)->nullable()->after('barcode');
            }
        });
    }

    public function down(): void
    {
        // ⚠️ `tax_id` مش بتاعنا — اتعمل في مايجريشن 000011. دروبه هنا
        // معناه إن رجوع المايجريشن ده بيمسح عمود مايجريشن تانية.
        Schema::table('products', function (Blueprint $table) {
            foreach (['taxable', 'tax_rate', 'eta_code'] as $c) {
                if (Schema::hasColumn('products', $c)) {
                    $table->dropColumn($c);
                }
            }
        });

        Schema::table('clients', function (Blueprint $table) {
            if (Schema::hasColumn('clients', 'eta_type')) {
                $table->dropColumn('eta_type');
            }
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            foreach (['tax_rate', 'tax'] as $c) {
                if (Schema::hasColumn('purchase_order_items', $c)) {
                    $table->dropColumn($c);
                }
            }
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            foreach (['tax_total', 'grand_total'] as $c) {
                if (Schema::hasColumn('purchase_orders', $c)) {
                    $table->dropColumn($c);
                }
            }
        });

        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'tax')) {
                $table->dropColumn('tax');
            }
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            foreach (['tax_rate', 'tax'] as $c) {
                if (Schema::hasColumn('invoice_items', $c)) {
                    $table->dropColumn($c);
                }
            }
        });

        Schema::table('invoices', function (Blueprint $table) {
            foreach (['tax_total', 'grand_total', 'eta_status', 'eta_uuid', 'eta_submitted_at'] as $c) {
                if (Schema::hasColumn('invoices', $c)) {
                    $table->dropColumn($c);
                }
            }
        });

        Schema::dropIfExists('settings');
    }
};
