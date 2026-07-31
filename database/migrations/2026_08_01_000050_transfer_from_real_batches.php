<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════
 * التحويل بقى بيطلّع بضاعة حقيقية من باتش حقيقي
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **التحويل كان بيخلق بضاعة من العدم.** `storeTransfer` كانت
 * بتعمل صفوف التحويل وبس — مافيش أي خصم من المخزن المرسل — و
 * `receive()` بتزوّد باتشات في المخزن المستقبِل. يعني تحويل 50
 * كرتونة من العاشر للمعادي كان بيخلّي إجمالي الشركة يزيد 50،
 * والبانر على الشاشة بيقول «البضاعة بتخرج من المخزن المرسل فوراً»
 * وهي مش بتخرج. أي جرد كان هيكشفها عجز في العاشر محدش يعرف سببه.
 *
 * العمودين دول بيربطوا بند التحويل بالباتش اللي البضاعة طلعت منه
 * فعلاً، عشان:
 *   • الخصم وقت الإرسال يبقى من باتش بعينه (تكلفته وصلاحيته معروفين)
 *   • العجز وقت الاستلام يترد على نفس الباتش كتوالف مش كصرف
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stock_transfer_items')) {
            return;
        }

        Schema::table('stock_transfer_items', function (Blueprint $table) {
            if (! Schema::hasColumn('stock_transfer_items', 'source_batch_id')) {
                // ⚠️ `nullable` مقصود: التحويلات القديمة اللي اتعملت
                // قبل المايجريشن دي مالهاش باتش مصدر أصلاً، ومنعها
                // كان هيوقّف المايجريشن على داتا موجودة.
                //
                // ⚠️ `nullOnDelete` مش `cascade`: مسح الباتش مايصحّش
                // يمسح سجل إن الشحنة دي اتبعتت — الورقة الممضية
                // بتفضل موجودة في الدرج.
                $table->foreignId('source_batch_id')->nullable()->after('product_id')
                    ->constrained('batches')->nullOnDelete();
            }

            if (! Schema::hasColumn('stock_transfer_items', 'qty_short')) {
                // الفرق المسجّل عجز على المخزن المرسل وقت الاستلام.
                // متخزن صراحةً مش محسوب، عشان الورقة الممضية تفضل
                // مطابقة حتى لو حد عدّل الكميات بعدين.
                $table->integer('qty_short')->default(0)->after('qty_received');
            }
        });

        Schema::table('stock_transfers', function (Blueprint $table) {
            // مين وقّع بالاستلام على الورق (السواق/المندوب اللي شال).
            // اسم نصي مش FK — ساعات اللي بيشيل مش يوزر في السيستم.
            if (! Schema::hasColumn('stock_transfers', 'carrier_name')) {
                $table->string('carrier_name', 120)->nullable()->after('received_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('stock_transfer_items', function (Blueprint $table) {
            if (Schema::hasColumn('stock_transfer_items', 'source_batch_id')) {
                $table->dropConstrainedForeignId('source_batch_id');
            }

            if (Schema::hasColumn('stock_transfer_items', 'qty_short')) {
                $table->dropColumn('qty_short');
            }
        });

        Schema::table('stock_transfers', function (Blueprint $table) {
            if (Schema::hasColumn('stock_transfers', 'carrier_name')) {
                $table->dropColumn('carrier_name');
            }
        });
    }
};
