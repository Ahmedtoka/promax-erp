<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جداول الشغل الميداني: العهدة، الزيارات، الفواتير، أوامر التوريد، طلبات العملاء، التراكينج
 */
return new class extends Migration
{
    public function up(): void
    {
        // ===== عهدة العربية =====
        Schema::create('custodies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date')->index();
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'date']);
        });

        Schema::create('custody_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custody_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->integer('assigned')->default(0); // المحمّل
            $table->integer('sold')->default(0);     // المباع/المسلّم
            $table->integer('returned')->default(0); // المرتجع للمخزن
            $table->timestamps();
            $table->unique(['custody_id', 'product_id']);
        });

        // ===== الزيارات (تشيك إن / أوت) =====
        Schema::create('visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamp('checked_out_at')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });

        // ===== الفواتير =====
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('visit_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('payment', ['cash', 'credit'])->default('cash');
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount_pct', 5, 4)->default(0);
            $table->decimal('discount', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->integer('qty');
            $table->decimal('price', 10, 2);
            $table->decimal('total', 14, 2);
            $table->timestamps();
        });

        // ===== أوامر التوريد (POs) =====
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('source', 40)->nullable();  // جورميه / رابيت
            $table->string('address')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['pending', 'arrived', 'delivered', 'cancelled'])
                ->default('pending')->index();
            $table->string('price_mode', 20)->default('hold'); // hold / p70 / cash
            $table->decimal('total', 14, 2)->default(0);
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->date('due_date')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->integer('qty');
            $table->integer('delivered_qty')->default(0);
            $table->decimal('price', 10, 2);
            $table->decimal('total', 14, 2);
            $table->timestamps();
        });

        // ===== طلبات العملاء الجدد =====
        Schema::create('client_requests', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->string('name');
            $table->string('phone', 30)->nullable();
            $table->string('address')->nullable();
            $table->foreignId('zone_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('has_docs')->default(false);
            $table->string('photo_path')->nullable();
            $table->enum('status', ['pending', 'review', 'approved', 'rejected'])
                ->default('pending')->index();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->text('decision_note')->nullable();
            $table->timestamps();
        });

        // ===== التراكينج =====
        Schema::create('track_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // start / check_in / check_out / sale / deliver / request
            $table->string('type', 20)->index();
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->timestamp('happened_at')->index();
            $table->timestamps();
        });

        // ===== إشعارات الأبلكيشن =====
        Schema::create('app_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('body')->nullable();
            $table->boolean('is_good')->default(true);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_notifications');
        Schema::dropIfExists('track_events');
        Schema::dropIfExists('client_requests');
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('visits');
        Schema::dropIfExists('custody_items');
        Schema::dropIfExists('custodies');
    }
};
