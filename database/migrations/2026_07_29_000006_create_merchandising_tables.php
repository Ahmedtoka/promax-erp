<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * شغل البروموتر (المرشندايزنج):
 * زيارة الفرع → صورة الرف قبل → ريفيل من مخزن الفرع للرف →
 * تسجيل النواقص → طلب ريفيل لو مفيش استوك → صورة الرف بعد → تشيك أوت
 */
return new class extends Migration
{
    public function up(): void
    {
        // ===== زيارة البروموتر =====
        Schema::create('merch_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamp('checked_out_at')->nullable();
            $table->string('photo_before')->nullable();  // صورة الرف قبل
            $table->string('photo_after')->nullable();   // صورة الرف بعد
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });

        // ===== بنود الريفيل: نقل من مخزن الفرع للرف =====
        Schema::create('shelf_refills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merch_visit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->integer('shelf_before')->default(0);  // الكمية على الرف قبل
            $table->integer('store_qty')->default(0);     // اللي كان في مخزن الفرع
            $table->integer('moved_qty')->default(0);     // اللي اتنقل للرف
            $table->boolean('out_of_stock')->default(false); // مفيش لا على الرف ولا في المخزن
            $table->timestamps();
            $table->unique(['merch_visit_id', 'product_id']);
        });

        // ===== طلب ريفيل (توريد للفرع) =====
        Schema::create('replenishment_requests', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merch_visit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            // pending: مستني الموافقة/التوزيع — assigned: اتنزّل على مندوب — delivered — cancelled
            $table->enum('status', ['pending', 'assigned', 'delivered', 'cancelled'])
                ->default('pending')->index();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create('replenishment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('replenishment_request_id')->constrained()
                ->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->integer('qty');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('replenishment_items');
        Schema::dropIfExists('replenishment_requests');
        Schema::dropIfExists('shelf_refills');
        Schema::dropIfExists('merch_visits');
    }
};
