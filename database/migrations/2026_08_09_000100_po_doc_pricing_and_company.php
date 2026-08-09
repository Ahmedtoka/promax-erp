<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════
 * ورقة أمر التوريد: سعر القايمة والخصم على البند + بيانات الشركة
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **ليه بنخزّن الخصم على البند بدل ما نقراه من العميل وقت الطباعة؟**
 * لأن خصم العميل بيتغيّر — عقد بينتهي، نسبة بتتظبط. لو الورقة قرأت
 * `Client::effectiveDiscount()` وقت الطباعة، أمر اتسعّر بـ10% ممكن
 * يتطبع بعد شهرين بـ5%، والفرع يمضي على ورقة أرقامها مش أرقام الأمر.
 * السطر لازم يفضل شاهد على اللحظة اللي اتسعّر فيها.
 *
 * ⚠️ `price` مابيتغيرش معناه — فضل **السعر بعد الخصم** (اللي `total`
 * اتحسب منه). الجديد `list_price` = قبل الخصم، و`discount_pct` كسر
 * (0.1000 = 10%). كده مفيش رقم قديم بيتحرك.
 *
 * الصفوف القديمة: `list_price = price` و`discount_pct = 0` — ده مش
 * تخمين، ده أصدق حاجة نعرفها: الخصم وقتها مش متسجّل، فالورقة هتقول
 * «مفيش خصم» بدل ما تخترع نسبة، و`السعر بعد الخصم = السعر`.
 */
return new class extends Migration
{
    /** بيانات البنك ديمو لحد ما المالك يدخّل الحقيقية من الشاشة */
    private const SEED = [
        'company_tax_id' => '767-179-153',
        'company_cr' => '197434',
        // ⚠️ اتصححوا في 000500 — القيم هنا بقت الجديدة عشان أي داتابيز
        // جديدة تاخدها صح من أول مرة
        'company_phone' => '+201044242200',
        'company_email' => 'info@promaxfoods.com',
        'company_address' => '٢ مشروع ١٦ عمارة - تقسيم اللاسلكي - المعادي',
    ];

    public function up(): void
    {
        // ⚠️ **حراسة الجدول مش الأعمدة بس.** السيرفر مش ريبو جيت
        // والملفات بتترفع بالإيد، فملف ممكن يوصل قبل الجدول. من غير
        // الشرط ده المايجريشن بترمي بدل ما تعدّي، والباتش بيقف نصه.
        if (Schema::hasTable('purchase_order_items')) {
            Schema::table('purchase_order_items', function (Blueprint $table) {
                if (! Schema::hasColumn('purchase_order_items', 'list_price')) {
                    $table->decimal('list_price', 12, 2)->default(0)->after('price');
                }
                if (! Schema::hasColumn('purchase_order_items', 'discount_pct')) {
                    $table->decimal('discount_pct', 5, 4)->default(0)->after('list_price');
                }
            });

            // ⚠️ الشرط `= 0` مش `whereNull` — العمود default 0 مش null،
            // والتشغيل التاني للمايجريشن مايدوسش على قيم اتكتبت فعلاً.
            DB::table('purchase_order_items')
                ->where('list_price', 0)
                ->update(['list_price' => DB::raw('`price`')]);
        }

        // ═══════════ بيانات الشركة ═══════════
        // ⚠️ `insertOrIgnore` مش `updateOrInsert` — لو المالك عدّل
        // القيمة من الشاشة، إعادة تشغيل المايجريشن مالهاش حق ترجّعها.
        if (Schema::hasTable('settings')) {
            $now = now();

            foreach (self::SEED as $key => $value) {
                DB::table('settings')->insertOrIgnore([
                    'key' => $key,
                    'value' => $value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('purchase_order_items')) {
            return;
        }

        Schema::table('purchase_order_items', function (Blueprint $table) {
            foreach (['list_price', 'discount_pct'] as $c) {
                if (Schema::hasColumn('purchase_order_items', $c)) {
                    $table->dropColumn($c);
                }
            }
        });

        // ⚠️ بيانات الشركة مش بتتمسح — دي داتا المالك مش سكيما.
    }
};
