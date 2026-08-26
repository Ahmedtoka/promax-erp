<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════
 * إدارة المهام — Task Management (٢٦ أغسطس ٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * المدير بيكلّف موظف مكتب بمهمة (عنوان + وصف + ملفات + ديدلاين +
 * أولوية) → نوتفيكيشن → شات رايح جاي جوه المهمة → الموظف «تم
 * التسليم» → المكلِّف «اعتمد» أو «رفض» (الرفض بيرجّعها مفتوحة).
 *
 * داش بورد فقط — مفيش مناديب ولا سواقين (User::TASK_ROLES).
 *
 * ⚠️ محروسة + كل الأعمدة الزمنية dateTime (عقيدة ٢٣/٨).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tasks')) {
            Schema::create('tasks', function (Blueprint $table) {
                $table->id();
                $table->string('title');
                $table->text('description')->nullable();
                $table->foreignId('assigned_to')->index();   // الموظف المكلَّف
                $table->foreignId('created_by')->index();    // المكلِّف
                $table->string('priority', 10)->default('normal'); // low|normal|high|urgent
                $table->dateTime('deadline')->nullable();
                // open → submitted (تم التسليم) → approved — الرفض بيرجّعها open
                $table->string('status', 12)->default('open')->index();
                $table->dateTime('submitted_at')->nullable();
                $table->dateTime('approved_at')->nullable();
                $table->unsignedSmallInteger('rejections')->default(0);
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
            });
        }

        if (! Schema::hasTable('task_comments')) {
            Schema::create('task_comments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('task_id')->index();
                $table->foreignId('user_id');
                $table->text('body')->nullable();
                // مرفق اختياري مع الرسالة — صورة أو شيت
                $table->string('file_path')->nullable();
                $table->string('file_name')->nullable();
                // system = سطر أوتوماتيك (اتسلمت/اتعمدت/اترفضت) وسط الشات
                $table->boolean('is_system')->default(false);
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
            });
        }

        if (! Schema::hasTable('task_files')) {
            Schema::create('task_files', function (Blueprint $table) {
                $table->id();
                $table->foreignId('task_id')->index();
                $table->foreignId('uploaded_by');
                $table->string('path');
                $table->string('name');
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('task_files');
        Schema::dropIfExists('task_comments');
        Schema::dropIfExists('tasks');
    }
};
