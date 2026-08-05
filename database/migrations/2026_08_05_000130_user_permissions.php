<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════
 * صلاحيات مخصصة لكل يوزر — فوق افتراضيات الرول (قرار المالك 2026-08-05)
 * ═══════════════════════════════════════════════════════════════
 *
 * الرول بيدي الافتراضي (Access::SCREENS)، والجدول ده بيسجل
 * الاستثناءات بس: «اليوزر ده شايل منه القسم الفلاني» أو «مديله
 * صفحة زيادة» أو «الزرار ده مخفي عنه». مفيش صف = وراثة من الرول.
 *
 * perm بيشيل تلات أنواع مفاتيح:
 *   - قسم منيو كامل:  nav.group_wh
 *   - صفحة/بادئة راوت: erp.clients أو wh.receipts
 *   - زرار جوه صفحة:  act.clients.create
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_permissions')) {
            Schema::create('user_permissions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('perm', 120);
                // true = إظهار/سماح — false = إخفاء/منع
                $table->boolean('allow');
                $table->timestamps();
                $table->unique(['user_id', 'perm']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_permissions');
    }
};
