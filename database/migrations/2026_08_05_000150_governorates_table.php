<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════
 * المحافظات بقت داتابيز (قرار المالك 2026-08-05)
 * ═══════════════════════════════════════════════════════════════
 *
 * كانت ثابتة في `Governorates::KEYS` + ملفات اللغة — يعني تعديل اسم
 * أو إضافة محافظة كان محتاج ديبلوي. دلوقتي الجدول هو المصدر،
 * والـ27 القديمة بتتزرع هنا **بنفس مفاتيحها** عشان كل عميل ومنطقة
 * متخزن عليهم المفتاح ده مايتأثروش.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('governorates')) {
            Schema::create('governorates', function (Blueprint $table) {
                $table->id();
                $table->string('key', 40)->unique();
                $table->string('name', 120);
                $table->string('name_en', 120)->nullable();
                $table->unsignedSmallInteger('sort')->default(0);
                $table->boolean('active')->default(true);
                $table->timestamps();
            });
        }

        // زرع الـ27 بنفس المفاتيح والأسماء من ملفات اللغة — idempotent
        foreach (\App\Support\Governorates::BUILTIN as $i => $key) {
            if (DB::table('governorates')->where('key', $key)->exists()) {
                continue;
            }

            DB::table('governorates')->insert([
                'key' => $key,
                'name' => trans('geo.gov.'.$key, [], 'ar'),
                'name_en' => trans('geo.gov.'.$key, [], 'en'),
                'sort' => $i + 1,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('governorates');
    }
};
