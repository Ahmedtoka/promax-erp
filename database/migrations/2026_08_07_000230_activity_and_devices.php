<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════
 * سجل الحركة + توكنز الأجهزة (2026-08-07)
 * ═══════════════════════════════════════════════════════════════
 *
 * `activity_logs` — مين عمل إيه وإمتى ومن فين. بيتكتب أوتوماتيك من
 * `LogsActivity` على الموديلز (إنشاء/تعديل/مسح بالقيمة قبل وبعد)
 * ومن ميدل وير `TrackVisit` (فتح الصفحات) ومن أحداث الدخول والخروج.
 *
 * ⚠️ **الجدول ده بيكبر بسرعة** (فتح الصفحات كمان بأمر المالك) —
 * فيه فهارس على اليوزر والتاريخ والنوع، وأمر تنضيف بيمسح الأقدم من
 * فترة الاحتفاظ. متعملش عليه JOIN في شاشات الأرقام.
 *
 * `device_tokens` — توكن FCM لكل جهاز. اليوزر ممكن يبقى له أكتر من
 * جهاز، والتوكن الواحد ممكن ينتقل ليوزر تاني (تليفون اتسلّم لحد
 * تاني) — فالمفتاح الفريد على التوكن نفسه مش على اليوزر.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('activity_logs')) {
            Schema::create('activity_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('user_name', 120)->nullable();   // لقطة الاسم — اليوزر ممكن يتمسح
                $table->string('role', 30)->nullable();
                // event: created / updated / deleted / login / logout / viewed / action
                $table->string('event', 20);
                $table->string('subject_type', 120)->nullable(); // اسم الموديل المختصر
                $table->unsignedBigInteger('subject_id')->nullable();
                $table->string('title', 190)->nullable();        // وصف مقروء للصف
                $table->json('changes')->nullable();             // {field: [قبل, بعد]}
                $table->string('url', 300)->nullable();
                $table->string('method', 10)->nullable();
                $table->string('ip', 45)->nullable();
                $table->string('agent', 200)->nullable();
                $table->timestamps();

                $table->index(['user_id', 'created_at']);
                $table->index(['event', 'created_at']);
                $table->index(['subject_type', 'subject_id']);
            });
        }

        if (! Schema::hasTable('device_tokens')) {
            Schema::create('device_tokens', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('token', 255)->unique();
                $table->string('platform', 20)->default('android');
                $table->string('app_version', 30)->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamps();

                $table->index('user_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
        Schema::dropIfExists('activity_logs');
    }
};
