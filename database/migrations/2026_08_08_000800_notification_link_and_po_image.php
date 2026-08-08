<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════
 * وجهة الإشعار + صورة أمر التوريد الأصلي (٨ أغسطس ٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_notifications', function (Blueprint $table) {
            // ⚠️ **الإشعار كان بيفتح الشاشة الرئيسية وبس.** السيرفر
            // بيبعت `data` صح ومحدش بيقراها، ومفيش `link` أصلاً —
            // فالمندوب بياخد «أمر توريد PO-1042 جاهز» وبيدوس وبيلاقي
            // نفسه على الرئيسية بيدوّر عليه بإيده.
            //
            // ⚠️ **نص قصير مش URL**: `po:12` · `pick:7` · `request:3`
            // · `replenishment:9` · `custody` · `home`. الأبلكيشن هو
            // اللي بيترجمه لشاشة — URL كامل كان هيربط الإشعارات
            // بمسارات الويب وهي مالهاش وجود في الموبايل.
            if (! Schema::hasColumn('app_notifications', 'link')) {
                $table->string('link', 60)->nullable()->after('body');
            }
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            // ⚠️ **صورة الـPO الحقيقي بتاع الشركة** (طلب المالك ٨/٨):
            // السلسلة بتبعت ورقة أو صورة أمر الشراء بتاعها، والمندوب
            // بيحتاج يفتحها عند العميل عشان يطابق. `sheet_path` ده
            // شيت الإكسيل بتاع الرفع الجماعي — حاجة تانية خالص.
            if (! Schema::hasColumn('purchase_orders', 'image_path')) {
                $table->string('image_path', 300)->nullable()->after('sheet_name');
            }

            // ═══ دورة التجهيز — «ابدأ» إجباري (قرار المالك ٨/٨) ═══
            // بيتسجّل هنا عشان المدة تتحسب وتتعرض.
            if (! Schema::hasColumn('purchase_orders', 'prep_started_at')) {
                $table->timestamp('prep_started_at')->nullable()->after('image_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('app_notifications', function (Blueprint $table) {
            if (Schema::hasColumn('app_notifications', 'link')) {
                $table->dropColumn('link');
            }
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            foreach (['image_path', 'prep_started_at'] as $col) {
                if (Schema::hasColumn('purchase_orders', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
