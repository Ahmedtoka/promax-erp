<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════
 * الحوافز والأداء — الأبديت الكبير (2026-08-06)
 * ═══════════════════════════════════════════════════════════════
 *
 * - `rep_targets`: تارجت شهري لكل مندوب (فلوس/عملاء جداد/زيارات/قطع).
 * - `rep_points`: النقاط اليدوية (منح/خصم بسبب) — الأوتوماتيك بتتحسب
 *   مشتقة من النشاط في RepKpis مش بصفوف.
 * - `commission_tiers`: شرايح العمولة حسب نسبة تحقيق تارجت الفلوس.
 * - `day_closes`: قفل اليوم — سنابشوت أرقام اليومية بعد تصفيات المناديب.
 * - `lead_pings`: أليرتات العملاء المحتملين — مين اتعرض له إيه وقَبل ولا رفض.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rep_targets')) {
            Schema::create('rep_targets', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->date('month');                                   // أول يوم في الشهر
                $table->decimal('money_target', 14, 2)->default(0);      // صافي مبيعات مستهدف
                $table->unsignedInteger('new_clients_target')->default(0);
                $table->unsignedInteger('visits_target')->default(0);    // إجمالي الشهر
                $table->unsignedInteger('pieces_target')->default(0);
                $table->timestamps();

                $table->unique(['user_id', 'month']);
            });
        }

        if (! Schema::hasTable('rep_points')) {
            Schema::create('rep_points', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->date('date');
                $table->integer('points');                               // موجب أو سالب
                $table->string('reason', 190);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['user_id', 'date']);
            });
        }

        if (! Schema::hasTable('commission_tiers')) {
            Schema::create('commission_tiers', function (Blueprint $table) {
                $table->id();
                // «من نسبة تحقيق كذا %» ياخد «نسبة عمولة كذا» من صافي مبيعاته
                $table->decimal('min_pct', 6, 2);                        // 80 = %80 من التارجت
                $table->decimal('rate', 6, 4);                          // 0.0150 = %1.5 عمولة
                $table->timestamps();
            });

            // شرايح افتراضية — بتتعدل من شاشة الإعدادات
            DB::table('commission_tiers')->insert([
                ['min_pct' => 80, 'rate' => 0.0100, 'created_at' => now(), 'updated_at' => now()],
                ['min_pct' => 100, 'rate' => 0.0150, 'created_at' => now(), 'updated_at' => now()],
                ['min_pct' => 120, 'rate' => 0.0200, 'created_at' => now(), 'updated_at' => now()],
            ]);
        }

        if (! Schema::hasTable('day_closes')) {
            Schema::create('day_closes', function (Blueprint $table) {
                $table->id();
                $table->date('date')->unique();
                $table->unsignedInteger('invoices_count')->default(0);
                $table->unsignedInteger('clients_count')->default(0);      // عملاء اتزاروا/اتباعلهم
                $table->decimal('sales_cash', 14, 2)->default(0);          // بالإجمالي (المدفوع)
                $table->decimal('sales_credit', 14, 2)->default(0);
                $table->decimal('sales_net', 14, 2)->default(0);           // صافي المبيعات (total)
                $table->decimal('returns_total', 14, 2)->default(0);
                $table->decimal('collections_total', 14, 2)->default(0);   // تحصيلات (غير الكاش الفوري)
                $table->unsignedInteger('pos_delivered_count')->default(0);
                $table->decimal('pos_delivered_value', 14, 2)->default(0);
                $table->unsignedInteger('settlements_count')->default(0);
                $table->decimal('settlements_received', 14, 2)->default(0);
                $table->decimal('settlements_balance', 14, 2)->default(0); // مترحّل على المناديب
                $table->text('notes')->nullable();
                $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('lead_pings')) {
            Schema::create('lead_pings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                // shown = ظهر له الأليرت · accepted = قبل · rejected = رفض
                $table->string('action', 10)->index();
                $table->timestamps();

                $table->index(['lead_id', 'user_id']);
                $table->index(['user_id', 'action']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_pings');
        Schema::dropIfExists('day_closes');
        Schema::dropIfExists('commission_tiers');
        Schema::dropIfExists('rep_points');
        Schema::dropIfExists('rep_targets');
    }
};
