<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تطوير الكوتيشن (٢٣ أغسطس ٢٠٢٦) — طلب المالك: الورقة بالبراندينج
 * وصور كبيرة وأسعار الوحدات (القطعة/العلبة/الكرتونة)، والفورم بيبدأ
 * بالعميل وقايمة الأسعار.
 *
 * الإضافات كلها **تجميد لقطة**: العرض المطبوع لازم يفضل زي ما هو
 * حتى لو القايمة اتعدلت أو الصنف اتغيرت وحداته بعدين.
 *
 * ⚠️ محروسة — السيرفر اللايف بيترفع بالإيد مش جيت.
 * ⚠️ dateTime مش timestamp لأي عمود زمني (عقيدة ٢٣/٨).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('quotations') && ! Schema::hasColumn('quotations', 'price_list_id')) {
            Schema::table('quotations', function (Blueprint $table) {
                // القايمة اللي العرض اتسعّر بيها — والاسم مجمّد نص
                // عشان لو القايمة اتمسحت السجل يفضل بيحكي
                $table->foreignId('price_list_id')->nullable()->after('client_id')
                    ->constrained('price_lists')->nullOnDelete();
                $table->string('price_list_name', 120)->nullable()->after('price_list_id');
            });
        }

        if (Schema::hasTable('quotation_items') && ! Schema::hasColumn('quotation_items', 'product_id')) {
            Schema::table('quotation_items', function (Blueprint $table) {
                // مرساة الصنف — للصورة في الطباعة. nullOnDelete:
                // مسح الصنف مايمسحش بند العرض (الاسم والكود مجمّدين)
                $table->foreignId('product_id')->nullable()->after('quotation_id')
                    ->constrained('products')->nullOnDelete();
                $table->string('code', 60)->nullable()->after('product_id');
                // لقطة الوحدات وقت الإصدار:
                // [{key: piece|box|case, factor: 12, price: 144.00}, ...]
                $table->json('units')->nullable()->after('total');
            });
        }
    }

    public function down(): void
    {
        // مفيش رجوع — أعمدة إضافية مش بتكسر القديم
    }
};
