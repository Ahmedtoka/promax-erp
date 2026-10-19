<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * أداة تسليم النظام (الإغلاق، الخط ١ — ١٩/١٠/٢٠٢٦، spec §3.8 و§4.1).
 *
 * موديول ← شاشاته ← تعليمات الاستلام. الموديولز والشاشات بتتولّد أول مرة
 * من `Access::NAV` (`promax:handover-seed`) وبعدها المالك بيعدّلها.
 *
 * ⚠️ **التعليم مابيتمسحش** — إلغاؤه بيملا `unmarked_at`/`unmarked_by`،
 * فالسجل فاضل بمين علّم وإمتى ومين لغى وإمتى.
 * ⚠️ كل الأعمدة الزمنية `dateTime()` مش `timestamp()` (promax-system §4).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('handover_modules')) {
            Schema::create('handover_modules', function (Blueprint $table) {
                $table->id();
                $table->string('key', 80)->unique();
                $table->string('name_ar', 150);
                $table->string('name_en', 150);
                $table->integer('sort')->default(0);
                $table->string('nav_group_key', 80)->nullable();
                // pending | in_progress | done
                $table->string('test_status', 20)->default('pending');
                // no | partial | yes
                $table->string('live_status', 20)->default('no');
                $table->date('deadline')->nullable();
                $table->text('notes')->nullable();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
            });
        }

        if (! Schema::hasTable('handover_screens')) {
            Schema::create('handover_screens', function (Blueprint $table) {
                $table->id();
                $table->foreignId('module_id')->constrained('handover_modules')->cascadeOnDelete();
                $table->string('route', 120);
                $table->string('title_ar', 150);
                $table->string('title_en', 150);
                $table->integer('sort')->default(0);
                // آخر مرة الشاشة اتغيّرت (`promax:handover-touch`) — لو بعد
                // التعليم الشاشة بتاخد «اتعدلت بعد الاستلام»
                $table->dateTime('touched_at')->nullable();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();

                $table->unique(['module_id', 'route'], 'handover_screens_module_route_unique');
            });
        }

        if (! Schema::hasTable('handover_marks')) {
            Schema::create('handover_marks', function (Blueprint $table) {
                $table->id();
                $table->foreignId('screen_id')->constrained('handover_screens')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users');
                $table->dateTime('marked_at');
                $table->dateTime('unmarked_at')->nullable();
                $table->foreignId('unmarked_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('note', 500)->nullable();

                $table->index(['screen_id', 'unmarked_at'], 'handover_marks_screen_active_idx');
                $table->index('marked_at', 'handover_marks_marked_at_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('handover_marks');
        Schema::dropIfExists('handover_screens');
        Schema::dropIfExists('handover_modules');
    }
};
