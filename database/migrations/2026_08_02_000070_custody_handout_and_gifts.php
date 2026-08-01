<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════
 * تسليم العهدة والهدايا
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **الهدية بضاعة حقيقية بتخرج من المخزن.** قرار المالك: بتتخصم
 * زي أي صنف، وبتفضل عهدة على المندوب لحد ما يقول اداها لمين. يعني
 * مش خصم على الفاتورة ولا سطر بصفر — دي كمية منفصلة ليها رصيد
 * وليها مصير لازم يتسجّل.
 *
 * ⚠️ **لو حطّيناها في نفس خانة البيع** (`assigned`) كان المندوب
 * هيقفل عهدته و«يبيع» عينات مجانية، والفرق بين اللي اتباع واللي
 * اتوزّع هدايا كان هيضيع للأبد — وده أهم رقم في ميزانية التسويق.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ═══════════ 1. الهدية في أمر التجهيز ═══════════
        if (Schema::hasTable('pick_order_items')) {
            Schema::table('pick_order_items', function (Blueprint $table) {
                if (! Schema::hasColumn('pick_order_items', 'gift_qty')) {
                    // ⚠️ **منفصلة عن `qty_requested`.** الاتنين بيخرجوا
                    // من نفس الباتش وبنفس الـFEFO، بس مصيرهم مختلف:
                    // دي بتتباع ودي بتتوزّع.
                    $table->integer('gift_qty')->default(0)->after('qty_received');
                }
            });
        }

        // ═══════════ 2. الهدية في العهدة ═══════════
        if (Schema::hasTable('custody_items')) {
            Schema::table('custody_items', function (Blueprint $table) {
                if (! Schema::hasColumn('custody_items', 'gift_assigned')) {
                    $table->integer('gift_assigned')->default(0)->after('assigned');
                }
                if (! Schema::hasColumn('custody_items', 'gift_given')) {
                    // اللي اتوزّع فعلاً — الباقي (`assigned - given`) لسه
                    // في العربية ولازم يرجع أو يتوزّع.
                    $table->integer('gift_given')->default(0)->after('gift_assigned');
                }
            });
        }

        // ═══════════ 3. مصير كل هدية ═══════════
        //
        // ⚠️ **من غير الجدول ده، «صرفنا 200 عينة» رقم مالوش تفصيل.**
        // السؤال اللي بيتسأل بعد الحملة هو «اداها لمين» — والإجابة
        // لازم تكون صف لكل توزيعة، مش عداد.
        if (! Schema::hasTable('gift_handouts')) {
            Schema::create('gift_handouts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('custody_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_id')->constrained()->cascadeOnDelete();

                // ⚠️ **العميل اختياري.** العينة ممكن تتوزّع في معرض أو
                // على المارّة؛ إجبار العميل كان هيخلّي المندوب يختار
                // أي عميل عشان يعدّي الشاشة، والرقم يبقى كدب.
                $table->foreignId('client_id')->nullable()
                    ->constrained()->nullOnDelete();
                $table->foreignId('visit_id')->nullable()
                    ->constrained()->nullOnDelete();
                $table->foreignId('batch_id')->nullable()
                    ->constrained()->nullOnDelete();

                $table->integer('qty');
                $table->string('reason', 40)->nullable();   // sampling / opening / complaint
                $table->text('note')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'created_at']);
                $table->index('client_id');
            });
        }

        // ═══════════ 4. حالة «بانتظار الاستلام» ═══════════
        if (Schema::hasTable('pick_orders')) {
            Schema::table('pick_orders', function (Blueprint $table) {
                if (! Schema::hasColumn('pick_orders', 'issued_at')) {
                    // ⚠️ **وقت خروج البضاعة من المخزن.** التسليم المباشر
                    // بيخرّج البضاعة فوراً وبيسيب الأمر «جاهز» لحد ما
                    // المندوب يستلم من الأبلكيشن؛ الوقت ده هو اللي
                    // بيقول البضاعة بقالها قد إيه بره المخزن ومحدش
                    // استلمها.
                    $table->timestamp('issued_at')->nullable()->after('ready_at');
                }
                if (! Schema::hasColumn('pick_orders', 'carrier_note')) {
                    $table->string('carrier_note', 190)->nullable()->after('issued_at');
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_handouts');

        foreach ([
            'pick_order_items' => ['gift_qty'],
            'custody_items' => ['gift_assigned', 'gift_given'],
            'pick_orders' => ['issued_at', 'carrier_note'],
        ] as $t => $cols) {
            if (! Schema::hasTable($t)) {
                continue;
            }

            Schema::table($t, function (Blueprint $table) use ($t, $cols) {
                foreach ($cols as $c) {
                    if (Schema::hasColumn($t, $c)) {
                        $table->dropColumn($c);
                    }
                }
            });
        }
    }
};
