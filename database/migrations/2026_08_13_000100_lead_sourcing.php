<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════
 * مصادر العملاء المحتملين — الاستيراد من الأدلة الخارجية
 * ═══════════════════════════════════════════════════════════════
 *
 * الليد الجاي من جوجل مابس أو من صفحة فيسبوك بيجيب معاه حاجات
 * الليد اليدوي مافيهوش، وكل واحدة فيهم بتخدم قرار:
 *
 *   - `external_id`   — `placeId` بتاع المكان. **مفتاح الديدوب الوحيد
 *     المضمون**: الاسم بيتغير والتليفون بيتشال، والـid ده بيفضل.
 *     من غيره كل رفعة تانية لنفس المنطقة بتكرّر نص القايمة.
 *   - `rating` + `reviews_count` — أحسن بروكسي متاح لحجم المحل.
 *     كافيه بـ٤.٥ و٣٠٠٠ ريفيو أكبر من كافيه بـ٤.٨ و١١ ريفيو.
 *   - `category_raw` — تصنيف جوجل زي ما هو («Gym», «Coffee shop»).
 *     بنشتق منه القناة، وبنحتفظ بالخام عشان لو الخريطة اتظبطت بعدين
 *     نقدر نعيد التصنيف من غير ما نرفع الداتا تاني.
 *   - `score` — ٠..١٠٠. ترتيب الشغل. المندوب مايقدرش يزور ٢٠٠٠ مكان،
 *     فالترتيب هو المنتج الحقيقي مش القايمة.
 *
 * ⚠️ **الإندكس مش unique** — عن قصد. المكان الواحد ممكن يكون ليه
 * أكتر من `placeId` (فرع اتقفل واتفتح)، وقفل يونيك على عمود جاي من
 * بره معناه رفعة كاملة بتقع على صف واحد. الديدوب بيتعمل في
 * `LeadImporter::validateAll` قبل الكتابة، والإندكس للسرعة بس.
 */
return new class extends Migration
{
    /**
     * الفهرس ده موجود على الجدول ده؟
     *
     * ⚠️ نفس الهيلبر بتاع `create_batches_and_catalogue` عن قصد —
     * ده النمط المتبع في المشروع، ومحدش محتاج يفتكر أنهي مايجريشن
     * بتحرس بطريقة مختلفة عن التانية.
     */
    private function hasIndex(string $table, string $index): bool
    {
        foreach (Schema::getIndexes($table) as $existing) {
            if (($existing['name'] ?? null) === $index) {
                return true;
            }
        }

        return false;
    }

    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            if (! Schema::hasColumn('leads', 'external_id')) {
                $table->string('external_id', 120)->nullable()->after('source');
            }

            if (! Schema::hasColumn('leads', 'website')) {
                $table->string('website', 255)->nullable()->after('external_id');
            }

            // ⚠️ decimal(3,2) — التقييم من 0.00 لـ 5.00
            if (! Schema::hasColumn('leads', 'rating')) {
                $table->decimal('rating', 3, 2)->nullable()->after('website');
            }

            if (! Schema::hasColumn('leads', 'reviews_count')) {
                $table->unsignedInteger('reviews_count')->default(0)->after('rating');
            }

            if (! Schema::hasColumn('leads', 'category_raw')) {
                $table->string('category_raw', 120)->nullable()->after('reviews_count');
            }

            // ⚠️ 0..100 — بيدخل في tinyint من غير مشاكل
            if (! Schema::hasColumn('leads', 'score')) {
                $table->unsignedTinyInteger('score')->default(0)->after('category_raw');
            }

            if (! Schema::hasColumn('leads', 'governorate')) {
                $table->string('governorate', 40)->nullable()->after('address');
            }

            // ⚠️ القسم الفرعي للكي أكاونت (سلسلة / كونفينيانس). من غيره
            // قرار «كارفور هايبر = كي أكاونت/سلسلة» بينفّذ نصّه بس:
            // الليد بياخد القناة، وبعد التحويل بيطلع عميل كي أكاونت
            // **بلا قسم** — وده الفخ اللي `Client::booted()` وشاشة
            // العملاء متعبين معاه أصلاً.
            if (! Schema::hasColumn('leads', 'sub_channel')) {
                $table->string('sub_channel', 20)->nullable()->after('channel_id');
            }
        });

        // ⚠️ الإندكسات في `Schema::table` لوحدها بعد ما الأعمدة اتعملت —
        // إضافة عمود وإندكس عليه في نفس الكلوجر بتقع على بعض
        // الإصدارات لما العمود لسه مش موجود وقت تخطيط الإندكس.
        Schema::table('leads', function (Blueprint $table) {
            if (Schema::hasColumn('leads', 'external_id') && ! $this->hasIndex('leads', 'leads_external_id_index')) {
                $table->index('external_id');
            }

            if (Schema::hasColumn('leads', 'score') && ! $this->hasIndex('leads', 'leads_score_status_index')) {
                $table->index(['score', 'status'], 'leads_score_status_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            if ($this->hasIndex('leads', 'leads_score_status_index')) {
                $table->dropIndex('leads_score_status_index');
            }

            if ($this->hasIndex('leads', 'leads_external_id_index')) {
                $table->dropIndex('leads_external_id_index');
            }
        });

        Schema::table('leads', function (Blueprint $table) {
            foreach (['external_id', 'website', 'rating', 'reviews_count', 'category_raw', 'score', 'governorate', 'sub_channel'] as $col) {
                if (Schema::hasColumn('leads', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
