<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الجداول الأساسية: الزونز، المنتجات، المخزون، العملاء، العقود، كشف الحساب
 */
return new class extends Migration
{
    public function up(): void
    {
        // ===== الزونز =====
        Schema::create('zones', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();      // Z1
            $table->string('name');                     // مدينة نصر والتجمع
            $table->string('day_label', 40)->nullable(); // يوم الزيارة
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // ===== المنتجات =====
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();       // 1001
            $table->string('name');
            $table->string('unit', 40);                 // برطمان / بار 70جم
            $table->string('family', 40)->index();      // spreads / promax_bar ...
            $table->decimal('price_hold', 10, 2);       // 50% hold
            $table->decimal('price_70', 10, 2);         // 70% cash van
            $table->decimal('price_cash', 10, 2);       // cash van (سعر البيع)
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // ===== مخزون المصنع/المخزن =====
        Schema::create('stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->integer('qty')->default(0);
            $table->integer('hold_qty')->default(0);
            $table->integer('good_qty')->default(0);
            $table->date('counted_at')->nullable();
            $table->timestamps();
        });

        // ===== العملاء =====
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name')->index();
            $table->string('phone', 30)->nullable();
            $table->string('address')->nullable();
            $table->foreignId('zone_id')->nullable()->constrained()->nullOnDelete();

            // تصنيف تجاري: danger / watch / grow / ok / idle / credit / internal
            $table->string('category', 20)->default('ok')->index();
            // حالة العميل في السيستم
            $table->enum('status', ['active', 'pending', 'rejected'])->default('active')->index();

            $table->decimal('discount', 5, 4)->default(0);   // خصم العقد 0.18
            $table->boolean('is_new')->default(false);        // اتضاف من الأبلكيشن
            $table->string('photo_path')->nullable();
            $table->boolean('has_docs')->default(false);

            // أرصدة تراكمية (بتتحدّث مع كل حركة)
            $table->decimal('purchases', 14, 2)->default(0);
            $table->decimal('collections', 14, 2)->default(0);
            $table->decimal('returns', 14, 2)->default(0);
            $table->decimal('rebates', 14, 2)->default(0);
            $table->decimal('settlements', 14, 2)->default(0);
            $table->decimal('balance', 14, 2)->default(0);

            $table->date('first_activity_at')->nullable();
            $table->date('last_activity_at')->nullable();
            $table->date('last_payment_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // ===== العقود =====
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('chain')->nullable();        // السلسلة
            $table->string('type', 60)->nullable();     // اتفاق / عقد
            $table->decimal('discount', 5, 4)->default(0);
            $table->string('terms', 100)->nullable();   // 21 يوم
            $table->date('ends_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });

        // ===== كشف الحساب (الحركات التاريخية والجديدة) =====
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->date('date')->index();
            $table->text('memo')->nullable();
            $table->decimal('debit', 14, 2)->default(0);
            $table->decimal('credit', 14, 2)->default(0);
            // sale / collection / return / rebate / settlement / transfer / taxded
            $table->string('kind', 20)->index();
            $table->nullableMorphs('source'); // ربط بالفاتورة أو الـ PO لو موجود
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('contracts');
        Schema::dropIfExists('clients');
        Schema::dropIfExists('stocks');
        Schema::dropIfExists('products');
        Schema::dropIfExists('zones');
    }
};
