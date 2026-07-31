<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════
 * العملاء المحتملين + خطط السير
 * ═══════════════════════════════════════════════════════════════
 *
 * **الليد ≠ طلب عميل جديد.** الاتنين موجودين وكل واحد له معنى:
 *
 *   - `client_requests` — المندوب **قابل** محل وعايز يفتحه أكاونت
 *     دلوقتي، والمدير بيوافق أو يرفض. قرار واحد وخلاص.
 *   - `leads` — محل **معروف** إحنا عايزينه، لسه مااتكلمناش معاه أو
 *     الكلام شغال. بيفضل شهور في القايمة وبيتتابع.
 *
 * الليد اللي بيوافق بيتحوّل لعميل مباشرة (مش لطلب) — لأن الموافقة
 * التجارية بتكون اتاخدت خلاص وقت ما اتحط في القايمة.
 *
 * ═══ خطط السير ═══
 *
 * الخطة **نمط أسبوعي** مش تواريخ. المندوب بيزور نفس العملاء كل
 * أسبوع في نفس اليوم — تخزين التواريخ معناه صف لكل عميل لكل أسبوع
 * للأبد. النمط بيتخزن مرة، وزيارات اليوم بتتولّد منه.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ═══════════ العملاء المحتملين ═══════════
        if (! Schema::hasTable('leads')) {
            Schema::create('leads', function (Blueprint $table) {
                $table->id();
                $table->string('number', 30)->unique();
                $table->string('name');
                $table->string('name_en')->nullable();
                $table->string('phone', 30)->nullable();
                $table->string('contact_name')->nullable();
                $table->string('address')->nullable();

                $table->foreignId('zone_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('channel_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();

                // new = جديد · contacted = اتكلمنا · visited = اتزار
                // negotiating = بنتفاوض · won = بقى عميل · lost = ضاع
                $table->string('status', 20)->default('new');
                $table->string('source', 40)->nullable();     // شيت / مندوب / إحالة
                $table->string('lost_reason', 190)->nullable();

                $table->decimal('lat', 10, 7)->nullable();
                $table->decimal('lng', 10, 7)->nullable();

                // توقّع حجم الشغل — بيرتّب الأولويات
                $table->decimal('expected_monthly', 14, 2)->default(0);

                // ⚠️ العميل اللي اتولد من الليد — عشان مانحوّلش نفس
                // الليد مرتين ونعمل عميلين بنفس الاسم
                $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
                $table->timestamp('converted_at')->nullable();

                $table->date('next_action_on')->nullable();
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['status', 'assigned_to']);
                $table->index('zone_id');
            });
        }

        // ═══════════ خطط السير ═══════════
        if (! Schema::hasTable('journey_plans')) {
            Schema::create('journey_plans', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('client_id')->constrained()->cascadeOnDelete();

                // ⚠️ 0 = الأحد لحد 6 = السبت (نفس ترتيب Carbon::dayOfWeek).
                // استخدام أي ترقيم تاني بيخلّي «زيارات النهارده» تطلع
                // بيوم غلط وده بيبان بعد أسبوع مش في التيست.
                $table->unsignedTinyInteger('weekday');

                // 1 = كل أسبوع · 2 = أسبوع ورا أسبوع · 4 = مرة في الشهر
                $table->unsignedTinyInteger('every_weeks')->default(1);

                $table->unsignedSmallInteger('sort')->default(0);   // ترتيب الزيارة في اليوم
                $table->boolean('active')->default(true);
                $table->text('notes')->nullable();
                $table->timestamps();

                // نفس العميل في نفس اليوم مرة واحدة للمندوب الواحد
                $table->unique(['user_id', 'client_id', 'weekday'], 'jp_user_client_day_unique');
                $table->index(['user_id', 'weekday']);
                $table->index('client_id');
            });
        }

        // الزيارة تعرف إنها من خطة — عشان نقيس الالتزام بالخطة
        Schema::table('visits', function (Blueprint $table) {
            if (! Schema::hasColumn('visits', 'journey_plan_id')) {
                $table->foreignId('journey_plan_id')->nullable()
                    ->constrained('journey_plans')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            if (Schema::hasColumn('visits', 'journey_plan_id')) {
                // ⚠️ الـ FK قبل العمود — العكس بيرمي خطأ MySQL
                $table->dropConstrainedForeignId('journey_plan_id');
            }
        });

        Schema::dropIfExists('journey_plans');
        Schema::dropIfExists('leads');
    }
};
