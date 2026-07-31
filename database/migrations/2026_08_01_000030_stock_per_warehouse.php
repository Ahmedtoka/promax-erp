<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════
 * المخزون يبقى لكل مخزن مش للشركة كلها
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **`stocks` كان صف واحد لكل صنف.** الرقم اللي فيه معناه «الشركة
 * كلها عندها كام» — من غير أي فكرة عن مكان البضاعة.
 *
 * ده كان ماشي وقت ما كان فيه مخزن واحد. مع مخزن العاشر ومخزن المعادي
 * بقى السؤال اليومي «الصنف ده عندي منه كام **في العاشر**؟» مالوش
 * إجابة — والمخزن بيطلب بضاعة موجودة عنده، أو بيقول إنه فاضي وهو
 * مليان والرقم بتاع المخزن التاني.
 *
 * ⚠️ **الباتشات فيها `warehouse_id` من الأول.** يعني كان فيه مصدرين
 * للحقيقة: `stocks` إجمالي بلا مكان، و`batches` بمكان. الاتنين
 * مابيتفقوش لأن الأصناف القديمة اتسجّل مخزونها إجمالي من الشيت قبل
 * ما نظام الباتشات يشتغل.
 *
 * الحل: `stocks` تبقى صف لكل (صنف، مخزن). الإجمالي بيتجمع، والمكان
 * بيبقى معروف، والتعديل اليدوي بيحصل على صف مخزن محدد.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('stocks', 'warehouse_id')) {
            return;
        }

        // ⚠️ **لازم يكون فيه مخزن قبل النقل.** الأرصدة الحالية مالهاش
        // مكان، فبنحطها على المخزن الافتراضي — ولو مفيش مخازن خالص
        // بنعمل واحد بدل ما نسيب الصفوف بـ`warehouse_id = null`
        // وتختفي من كل شاشة.
        $default = DB::table('warehouses')->where('active', true)->orderBy('id')->value('id');

        if ($default === null) {
            $default = DB::table('warehouses')->insertGetId([
                'code' => 'MAIN',
                'name' => 'المخزن الرئيسي',
                'name_en' => 'Main warehouse',
                'type' => 'factory',
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // ═══ 1. العمود ═══
        Schema::table('stocks', function (Blueprint $table) use ($default) {
            $table->foreignId('warehouse_id')->default($default)->after('product_id')
                ->constrained()->cascadeOnDelete();
        });

        // ⚠️ **الديفولت بيتشال بعد النقل.** سيبه معناه إن أي صف جديد
        // بيتكتب من غير مخزن بيقع على الافتراضي في صمت — والبضاعة
        // بتظهر في مخزن محدش حطها فيه.
        DB::statement('ALTER TABLE stocks ALTER COLUMN warehouse_id DROP DEFAULT');

        // ═══ 2. صنف + مخزن = صف واحد ═══
        // ⚠️ من غير القيد ده، صفين لنفس الصنف في نفس المخزن بيخلّوا
        // `updateOrCreate` تحدّث واحد وتسيب التاني — والإجمالي بيبقى
        // فيه كمية مالهاش أصل.
        Schema::table('stocks', function (Blueprint $table) {
            $table->unique(['product_id', 'warehouse_id'], 'stocks_product_warehouse_unique');
        });
    }

    public function down(): void
    {
        Schema::table('stocks', function (Blueprint $table) {
            if (Schema::hasColumn('stocks', 'warehouse_id')) {
                $table->dropUnique('stocks_product_warehouse_unique');
                $table->dropConstrainedForeignId('warehouse_id');
            }
        });
    }
};
