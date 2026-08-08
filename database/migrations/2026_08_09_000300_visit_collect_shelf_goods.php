<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════
 * ٣ أوبشنات الزيارة الجديدة (٩ أغسطس ٢٠٢٦)
 * تحصيل من العميل · ترتيب الرف بالصور · طلب بضاعة من عند العميل
 * ═══════════════════════════════════════════════════════════════
 */
return new class extends Migration
{
    public function up(): void
    {
        // ═══════════ ١. التحصيل الميداني ═══════════
        //
        // ⚠️ **صورة الإثبات على القيد نفسه.** الشيك والتحويل وسكرين
        // المحفظة لازم يتصوروا لحظة الاستلام — المحاسب في التصفية
        // بيطابق القيد على الصورة، ومن غيرها «تحويل 5000» كلمة من
        // غير سند. الكاش مالوش صورة.
        if (Schema::hasTable('transactions')) {
            Schema::table('transactions', function (Blueprint $table) {
                if (! Schema::hasColumn('transactions', 'proof_path')) {
                    $table->string('proof_path', 190)->nullable()->after('cheque_due');
                }
            });
        }

        // ═══════════ ٢. صور ترتيب الرف على الزيارة ═══════════
        //
        // ⚠️ **جدول منفصل مش عمودين على `visits`** — عمود واحد لكل
        // مرحلة كان هيقفل العدد على صورة واحدة، والمطلوب صراحةً
        // «يقدر يصور اكتر من صورة قبل وبعد». وجدول البروموتر
        // (`merch_visits.photo_before/after`) مالوش دعوة — ده فلو
        // السيلز إيجينت جوه زيارته العادية.
        if (! Schema::hasTable('visit_photos')) {
            Schema::create('visit_photos', function (Blueprint $table) {
                $table->id();
                $table->foreignId('visit_id')->constrained()->cascadeOnDelete();
                $table->string('stage', 10);          // before | after
                $table->string('path', 190);
                $table->timestamps();
                $table->index(['visit_id', 'stage']);
            });
        }

        // ═══════════ ٢ب. لقطة التحصيلات على التصفية ═══════════
        //
        // ⚠️ **الورقة مستند بيتمضي** — نفس مبدأ `goods_json`: لو قرينا
        // التحصيلات من القيود الحية وقت الطباعة، فتح الورقة بعد
        // أسبوع بيوري أرقام تانية غير اللي المندوب مضى عليها.
        if (Schema::hasTable('rep_settlements')) {
            Schema::table('rep_settlements', function (Blueprint $table) {
                if (! Schema::hasColumn('rep_settlements', 'cash_collections')) {
                    $table->decimal('cash_collections', 14, 2)->default(0)->after('cash_refunds');
                }
                if (! Schema::hasColumn('rep_settlements', 'collections_json')) {
                    $table->json('collections_json')->nullable()->after('goods_json');
                }
            });
        }

        // ═══════════ ٣. طلب بضاعة من المندوب عند العميل ═══════════
        //
        // ⚠️ **بنعيد استخدام `replenishment_requests` مش جدول جديد.**
        // الطلب من عند العميل هو نفس الكيان بالظبط: بنود + موافقة
        // مدير + تحويل لأمر توريد + تجهيز + تسليم. جدول موازي كان
        // معناه فلو موافقات تاني وشاشة تانية ونفس الباجات مرتين.
        // الفرق الوحيد: المرساة زيارة سيلز (`visit_id`) بدل زيارة
        // بروموتر (`merch_visit_id`) — والاتنين nullable ومتنافيين.
        if (Schema::hasTable('replenishment_requests')) {
            Schema::table('replenishment_requests', function (Blueprint $table) {
                if (! Schema::hasColumn('replenishment_requests', 'visit_id')) {
                    $table->foreignId('visit_id')->nullable()
                        ->after('merch_visit_id')
                        ->constrained('visits')->nullOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('replenishment_requests')
            && Schema::hasColumn('replenishment_requests', 'visit_id')) {
            Schema::table('replenishment_requests', function (Blueprint $table) {
                $table->dropConstrainedForeignId('visit_id');
            });
        }

        Schema::dropIfExists('visit_photos');

        if (Schema::hasTable('transactions') && Schema::hasColumn('transactions', 'proof_path')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->dropColumn('proof_path');
            });
        }
    }
};
