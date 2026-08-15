<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════
 * شبكة أمان التكرار — عمودين مطبّعين مفهرسين (١٥ أغسطس ٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **ليه مش يونيك؟**
 *
 *   • **التليفون**: سلسلة زي Circle K عندها 40 فرع بنفس رقم
 *     الإدارة. يونيك على `phone` كان هيمنع تعريف الفرع التاني
 *     أصلاً — يعني الحارس بيكسر الشغل بدل ما يحميه.
 *   • **الاسم**: «فرع المعادي» اسم بيتكرر بين سلاسل مختلفة.
 *     يونيك عليه بيمنع Seoudi المعادي وMetro المعادي يتواجدوا.
 *
 * فالقرار: **الحكم منطقي في `App\Support\Dupes`، والسكيما بتخدمه
 * بإندكس عادي بس.** العمودين دول نسخة **مطبّعة** من الاسم والتليفون،
 * بيتكتبوا أوتوماتيك في `Client::booted()`، وبيخلّوا لقطة التكرار
 * فهرس مش مسح كامل — على 10 آلاف عميل الفرق بين ملّي ثانية وثانية.
 *
 * ⚠️ **`clients.code` يونيك من الأصل** (`000001_create_core_tables`)
 * فمش محتاج حاجة هنا — بس بنتأكد بالحارس تحت لو قاعدة قديمة اتعملت
 * قبل القيد ده.
 *
 * ⚠️ الأعمدة **مش** في `$fillable` عن قصد — قيمتها مشتقة، وأي مسار
 * بيبعتها من بره بيكسّر التطابق بينها وبين الاسم الحقيقي.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('clients')) {
            return;
        }

        Schema::table('clients', function (Blueprint $table) {
            if (! Schema::hasColumn('clients', 'dupe_key')) {
                // 190 = حد الإندكس الآمن على utf8mb4 في MySQL القديم
                $table->string('dupe_key', 190)->nullable()->index()->after('name_en');
            }
        });

        Schema::table('clients', function (Blueprint $table) {
            if (! Schema::hasColumn('clients', 'dupe_phone')) {
                $table->string('dupe_phone', 30)->nullable()->index()->after('dupe_key');
            }
        });

        $this->backfill();
    }

    /**
     * ملّي العمودين للصفوف الموجودة.
     *
     * ⚠️ **بالـchunk مش `all()`** — الاستيراد الأخير حطّ 455 عميل
     * وممكن يوصلوا للآلاف، وقراءتهم مرة واحدة في الذاكرة على سيرفر
     * مشترك بتوقّع المايجريشن في نص الطريق ويسيب نص الجدول متملي.
     *
     * ⚠️ **`DB::table` مش الموديل** — الموديل عليه هوك `saving` بيكتب
     * نفس العمودين، ومناداته هنا معناها إن كل صف بيعدّي على
     * `Client::booted()` كامل (بما فيها حارس `sub_channel`) في
     * مايجريشن — وده بيغيّر داتا مالهاش دعوة بالتكرار.
     */
    private function backfill(): void
    {
        if (! Schema::hasColumn('clients', 'dupe_key')) {
            return;
        }

        DB::table('clients')
            ->select(['id', 'name', 'phone'])
            ->orderBy('id')
            ->chunk(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('clients')->where('id', $row->id)->update([
                        'dupe_key' => \App\Support\Dupes::nameKey($row->name),
                        'dupe_phone' => \App\Support\Dupes::phoneKey($row->phone) ?: null,
                    ]);
                }
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable('clients')) {
            return;
        }

        Schema::table('clients', function (Blueprint $table) {
            foreach (['dupe_key', 'dupe_phone'] as $col) {
                if (Schema::hasColumn('clients', $col)) {
                    // ⚠️ الإندكس بيتشال مع العمود في MySQL، بس بنسمّيه
                    // صراحةً عشان لو اتعمل بإيد باسم تاني مايفضلش يتيم.
                    $table->dropColumn($col);
                }
            }
        });
    }
};
