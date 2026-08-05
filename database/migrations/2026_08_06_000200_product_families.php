<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * العائلات كداتابيز (2026-08-06) — قرار المالك:
 *
 * العائلة بقت كيان بيتدار من شاشة: أسماؤها بتتعدل، منتجات بتدخل
 * وتخرج منها، و**مدة الصلاحية بالشهور بتتحدد عليها** — وأي حساب
 * انتهاء (استلام أو إعادة حساب) بيقرا منها. الثوابت القديمة
 * (Product::FAMILIES / SHELF_LIFE) بقت fallback بس.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_families')) {
            Schema::create('product_families', function (Blueprint $table) {
                $table->id();
                $table->string('key', 40)->unique();          // promax_bar
                $table->string('name', 120);                  // بروماكس بار
                $table->string('name_en', 120)->nullable();
                // مدة الصلاحية بالشهور — null = لسه ماتحددتش (fallback للثوابت)
                $table->unsignedSmallInteger('shelf_life_months')->nullable();
                $table->timestamps();
            });
        }

        // زرع العائلات الموجودة: الثوابت + أي قيمة متسجلة على المنتجات
        $known = \App\Models\Product::FAMILIES;
        $lives = \App\Models\Product::SHELF_LIFE;
        $keys = array_unique(array_merge(
            array_keys($known),
            DB::table('products')->whereNotNull('family')->distinct()->pluck('family')->all(),
        ));

        foreach ($keys as $key) {
            if (DB::table('product_families')->where('key', $key)->exists()) {
                continue;
            }

            DB::table('product_families')->insert([
                'key' => $key,
                'name' => \Illuminate\Support\Facades\Lang::has('enums.family.'.$key, 'ar')
                    ? \Illuminate\Support\Facades\Lang::get('enums.family.'.$key, [], 'ar')
                    : ($known[$key] ?? $key),
                'name_en' => \Illuminate\Support\Facades\Lang::has('enums.family.'.$key, 'en')
                    ? \Illuminate\Support\Facades\Lang::get('enums.family.'.$key, [], 'en')
                    : null,
                'shelf_life_months' => $lives[$key] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_families');
    }
};
