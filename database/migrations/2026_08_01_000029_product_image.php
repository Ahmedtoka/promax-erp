<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════
 * صورة الصنف المرفوعة
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **عمود جديد جنب `image_url` مش بدله.**
 *
 * `image_url` جاي من فيد GS1 — رابط على سيرفر خارجي
 * (`gs1eg.blob.core.windows.net`)، وصنف واحد بس من 31 عنده رابط.
 * الباقي فاضي.
 *
 * الاتنين مصدرين مختلفين وبيتحدّثوا من مكانين مختلفين:
 *
 *   • `image_url` بيتكتب من `Gs1CatalogueSeeder` كل ما الفيد يتحدّث
 *   • `image_path` بيرفعه المستخدم من جهازه
 *
 * لو خزّنا الاتنين في عمود واحد، أول تشغيل للسيدر بيدوس على الصور
 * اللي المستخدم رفعها بروابط GS1 الفاضية — وكل الصور تروح في صمت.
 *
 * ⚠️ **المرفوع بيغلب.** `Product::imageSrc()` بتفضّل `image_path`
 * لأنه اللي المستخدم شافه واختاره، والرابط الخارجي ممكن يقع في أي
 * وقت من غير ما حد ياخد باله.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'image_path')) {
                // مسار نسبي جوه `storage/app/public` — مش رابط كامل
                $table->string('image_path', 300)->nullable()->after('image_url');
            }

            // ⚠️ **الوصف كان ناقص خالص.** الكتالوج فيه اسم ووزن وباركود
            // بس — ومفيش مكان تكتب فيه «بروتين 20 جم، خالي من السكر
            // المضاف». المندوب بيتسأل السؤال ده كل يوم وبيرد من دماغه.
            if (! Schema::hasColumn('products', 'description')) {
                $table->text('description')->nullable()->after('gpc_category');
            }

            if (! Schema::hasColumn('products', 'description_en')) {
                $table->text('description_en')->nullable()->after('description');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            foreach (['image_path', 'description', 'description_en'] as $col) {
                if (Schema::hasColumn('products', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
