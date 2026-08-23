<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════
 * صلاحيات الرولز (2026-08-23) — «الرول ده يشوف إيه» من شاشة
 * ═══════════════════════════════════════════════════════════════
 *
 * قبلها كان فيه مستويين بس: افتراضي الرول المكتوب في الكود
 * (Access::SCREENS) واستثناءات اليوزر (user_permissions). المالك
 * عايز يظبط الرول كله من غير ما يلف على الموظفين واحد واحد —
 * فده جدول بنفس فكرة user_permissions بالظبط بس بالرول:
 * صف = (رول، مفتاح، سماح/منع)، ومفيش صف = وراثة من الكود.
 *
 * الترتيب في Access::allows: استثناء اليوزر ← استثناء الرول ← الكود.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('role_permissions')) {
            return;
        }

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('role', 30);
            // نفس مفاتيح user_permissions: اسم راوت/بادئة أو
            // nav.group_x (قسم كامل) أو act.x (زرار)
            $table->string('perm', 190);
            $table->boolean('allow');
            // ⚠️ dateTime مش timestamps() — عقيدة ٢٣/٨: أعمدة timestamp
            // على السيرفر ده بتاخد ON UPDATE ضمني بيدوس القيم
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(['role', 'perm']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
    }
};
