<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════
 * قوايم أسعار مسمّاة — بدل عمودين ثابتين
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **`price_old` و`price_new` كانوا سقف.** عمودين على `products`
 * معناهم إن السيستم يقدر يمسك تسعيرتين وبس، ولو احتجنا تالتة
 * (سلسلة كبيرة بسعر خاص، أو أسعار موسم) الحل الوحيد كان عمود جديد
 * ومايجريشن وتعديل كل شاشة بتقرا سعر. دلوقتي القايمة صف في جدول
 * والسعر صف في جدول تاني — عدد القوايم مالوش حد.
 *
 * ⚠️ **الأعمدة القديمة بتفضل مكانها.** الشغل التاريخي كله (فواتير،
 * تقارير، هامش ربح) بيقرا منها، ومسحها دلوقتي بيحوّل كل رقم قديم
 * لصفر. بتتنقل لقايمتين وبتفضل موجودة كمصدر للقراية القديمة لحد ما
 * كل حاجة تتحوّل.
 *
 * ⚠️ **القايمة مابتتفعّلش إلا لما كل الأصناف تتسعّر** — قرار المالك.
 * السبب: عميل على «قايمة 2» وصنف مالوش سعر فيها كان هيتباع بصفر
 * أو بسعر قايمة تانية، والاتنين غلط محدش بيكتشفه غير في آخر الشهر.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('price_lists')) {
            Schema::create('price_lists', function (Blueprint $table) {
                $table->id();
                // ⚠️ الكود ثابت وبيتخزن على العميل والعقد. تغييره
                // بيقطع الربط في صمت — العميل يفضل مشاور على كود
                // مالوش قايمة وياخد الافتراضي.
                $table->string('code', 30)->unique();
                $table->string('name');
                $table->string('name_en')->nullable();

                // ⚠️ **مش مفعّلة لما بتتعمل.** التفعيل قرار صريح
                // بيتم بعد ما كل الأصناف تتسعّر.
                $table->boolean('active')->default(false);

                // ⚠️ **الافتراضية واحدة بس.** العميل اللي مااتحددلوش
                // قايمة بياخد دي. من غيرها كان لازم كل عميل يتحدد
                // له قايمة بالإيد، وأول واحد يتنسى بيتباع بصفر.
                $table->boolean('is_default')->default(false);

                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()
                    ->constrained('users')->nullOnDelete();
                $table->timestamp('activated_at')->nullable();
                $table->timestamps();

                $table->index('active');
            });
        }

        if (! Schema::hasTable('price_list_items')) {
            Schema::create('price_list_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('price_list_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_id')->constrained()->cascadeOnDelete();
                $table->decimal('price', 10, 2)->default(0);
                $table->timestamps();

                // ⚠️ **سعر واحد للصنف في القايمة.** من غير الفهرس ده،
                // ضغطتين على حفظ بيعملوا صفّين بسعرين، و`first()`
                // بترجّع واحد منهم على مزاج الداتابيز.
                $table->unique(['price_list_id', 'product_id']);
            });
        }

        // ═══════════ نقل القايمتين الحاليتين ═══════════
        //
        // ⚠️ **بيتعمل مرة واحدة بس.** الشرط ده بيخلّي إعادة تشغيل
        // المايجريشن على داتابيز فيها قوايم متعدّلة ماتدوسش عليها.
        if (DB::table('price_lists')->count() === 0) {
            $now = now();

            $oldId = DB::table('price_lists')->insertGetId([
                'code' => 'old', 'name' => 'قائمة السعر القديمة',
                'name_en' => 'Old price list',
                'active' => true, 'is_default' => false,
                'created_at' => $now, 'updated_at' => $now,
            ]);

            // ⚠️ الجديدة هي الافتراضية — نفس اللي `Pricing::listFor`
            // كانت بترجّعه لما مافيش قايمة على العميل ولا العقد.
            $newId = DB::table('price_lists')->insertGetId([
                'code' => 'new', 'name' => 'قائمة السعر الجديدة',
                'name_en' => 'New price list',
                'active' => true, 'is_default' => true,
                'activated_at' => $now,
                'created_at' => $now, 'updated_at' => $now,
            ]);

            if (Schema::hasTable('products')) {
                foreach (DB::table('products')->select('id', 'price_old', 'price_new')->get() as $p) {
                    DB::table('price_list_items')->insert([
                        ['price_list_id' => $oldId, 'product_id' => $p->id,
                         'price' => $p->price_old ?? 0, 'created_at' => $now, 'updated_at' => $now],
                        ['price_list_id' => $newId, 'product_id' => $p->id,
                         'price' => $p->price_new ?? 0, 'created_at' => $now, 'updated_at' => $now],
                    ]);
                }
            }
        }

        // ═══════════ العميل والعقد بيشاوروا على قايمة ═══════════
        //
        // ⚠️ **عمود جديد مش تعديل القديم.** `clients.price_list` نص
        // فيه `old` أو `new` ومقروء من كود كتير؛ تحويله لـFK دلوقتي
        // بيكسّر كل ده. العمود الجديد بيتملا من القديم، والقراية
        // بتفضّله وبترجع للقديم لو فاضي.
        foreach (['clients', 'contracts'] as $t) {
            if (! Schema::hasTable($t) || Schema::hasColumn($t, 'price_list_id')) {
                continue;
            }

            Schema::table($t, function (Blueprint $table) {
                $table->foreignId('price_list_id')->nullable()->after('price_list')
                    ->constrained('price_lists')->nullOnDelete();
            });

            // الربط من النص القديم
            foreach (DB::table('price_lists')->whereIn('code', ['old', 'new'])->get() as $l) {
                DB::table($t)->where('price_list', $l->code)->update(['price_list_id' => $l->id]);
            }
        }
    }

    public function down(): void
    {
        foreach (['clients', 'contracts'] as $t) {
            if (Schema::hasTable($t) && Schema::hasColumn($t, 'price_list_id')) {
                Schema::table($t, function (Blueprint $table) {
                    $table->dropConstrainedForeignId('price_list_id');
                });
            }
        }

        Schema::dropIfExists('price_list_items');
        Schema::dropIfExists('price_lists');
    }
};
