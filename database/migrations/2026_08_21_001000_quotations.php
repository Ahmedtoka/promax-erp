<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * عروض الأسعار (٢١ أغسطس ٢٠٢٦) — طلب المالك: «أشوف كل عروض الأسعار
 * اللي طلعت ومين طلعها وطلعها بكام ولمين».
 *
 * الكوتيشن كان بيتطبع stateless ويضيع — بقى مستند محفوظ بسجله:
 * مين عمله، لمين، بكام، وبنوده — ويتعاد طباعته في أي وقت.
 *
 * ⚠️ **مستند عرض مش قيد** — مفيش أي أثر على العميل ولا المخزون،
 * وclient_id اختياري (العرض ممكن يروح لعميل محتمل مش متسجل).
 * ⚠️ محروسة — السيرفر اللايف بيترفع بالإيد مش جيت.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('quotations')) {
            Schema::create('quotations', function (Blueprint $table) {
                $table->id();
                $table->string('number', 30)->unique();
                $table->string('client_name', 190);
                $table->foreignId('client_id')->nullable()
                    ->constrained('clients')->nullOnDelete();
                // ⚠️ nullOnDelete — مسح الموظف مايمسحش سجل العروض
                $table->foreignId('created_by')->nullable()
                    ->constrained('users')->nullOnDelete();
                $table->date('valid_until');
                $table->decimal('discount_pct', 5, 2)->default(0);
                $table->decimal('tax_pct', 5, 2)->default(0);
                $table->decimal('subtotal', 14, 2)->default(0);
                $table->decimal('discount', 14, 2)->default(0);
                $table->decimal('net', 14, 2)->default(0);
                $table->decimal('tax', 14, 2)->default(0);
                $table->decimal('grand', 14, 2)->default(0);
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index('created_by');
                $table->index('created_at');
            });
        }

        if (! Schema::hasTable('quotation_items')) {
            Schema::create('quotation_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('quotation_id')
                    ->constrained('quotations')->cascadeOnDelete();
                // الاسم نص مجمّد — العرض بيفضل زي ما اتطبع حتى لو
                // الصنف اتغير اسمه أو اتشال بعدين
                $table->string('name', 190);
                $table->unsignedInteger('qty');
                $table->decimal('price', 12, 2);
                $table->decimal('total', 14, 2);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_items');
        Schema::dropIfExists('quotations');
    }
};
