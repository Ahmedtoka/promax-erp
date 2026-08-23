<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ═══ نظام العمولات والـKPI (٢٣ أغسطس ٢٠٢٦) ═══
 *
 * ترجمة حرفية لنموذج ProMax_Commission_KPI_Calculator (شيت Setup):
 *
 *   • `kpi_channels` — قناة العمولة (Specialty / Convenience...) بحدود
 *     التارجت ونسب الأدوار التلاتة. مربوطة بمدير القناة.
 *   • `kpi_metrics` — المؤشرات بأوزانها واتجاهها ومستهدفاتها
 *     (scope: rep = ١٣ مؤشر المندوب · leader = ١٢ مؤشر الإدارة).
 *     المستهدف ممكن يختلف بالقناة → JSON `targets` {channel_id: قيمة}
 *     مع `target` ديفولت.
 *   • `kpi_bands` — الشرائح: `multiplier` (الدرجة ← معامل الأداء)
 *     و`rate` (نسبة التحقيق ← النسبة الأساسية، بالقناة).
 *   • `kpi_inputs` — المدخلات اليدوية الشهرية لكل قناة (التوقع،
 *     مستهدف العملاء الجدد، درجة التقارير) للمدير والمدير العام.
 *   • `products.is_focus` — أصناف التركيز لمؤشر «المزيج».
 *
 * السياسات المفردة (نسب حافز الـKPI، الحد الأدنى للدرجة، عتبة
 * البوابة...) في `settings` — مفيش داعي لجدول.
 *
 * ⚠️ محروسة بالكامل — اللايف بيترفع بالإيد.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kpi_channels')) {
            Schema::create('kpi_channels', function (Blueprint $t) {
                $t->id();
                $t->string('name', 120);            // Specialty
                $t->string('name_ar', 120)->nullable();
                // مدير القناة — بيه بنعرف مناديب القناة (فريقه)
                $t->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
                $t->decimal('rep_gate', 14, 2)->default(0);       // تارجت المندوب الشهري
                $t->decimal('rep_max_rate', 8, 5)->default(0);    // 0.015
                $t->decimal('manager_gate', 14, 2)->default(0);
                $t->decimal('manager_rate', 8, 5)->default(0);    // 0.01
                $t->decimal('director_gate', 14, 2)->default(0);
                $t->decimal('director_rate', 8, 5)->default(0);   // 0.005
                $t->boolean('active')->default(true);
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('kpi_metrics')) {
            Schema::create('kpi_metrics', function (Blueprint $t) {
                $t->id();
                $t->string('scope', 10);            // rep | leader
                $t->string('key', 40);              // stability, growth...
                $t->string('name_ar', 160);
                $t->string('name_en', 160);
                $t->decimal('weight', 6, 2)->default(0);      // من 100
                $t->string('direction', 8)->default('higher'); // higher | lower
                $t->decimal('target', 12, 4)->default(0);     // الديفولت
                $t->json('targets')->nullable();              // {kpi_channel_id: target}
                $t->unsignedSmallInteger('sort')->default(0);
                $t->boolean('active')->default(true);
                $t->timestamps();

                $t->unique(['scope', 'key']);
            });
        }

        if (! Schema::hasTable('kpi_bands')) {
            Schema::create('kpi_bands', function (Blueprint $t) {
                $t->id();
                $t->string('kind', 12);             // multiplier | rate
                // شرائح النسبة بالقناة — المعامل عام (channel null)
                $t->foreignId('kpi_channel_id')->nullable()->constrained('kpi_channels')->cascadeOnDelete();
                $t->decimal('from_value', 10, 4);   // الدرجة من / التحقيق من
                $t->decimal('value', 8, 5);         // المعامل / النسبة
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('kpi_inputs')) {
            Schema::create('kpi_inputs', function (Blueprint $t) {
                $t->id();
                $t->string('period', 7);            // 2026-08
                $t->string('role', 10);             // manager | director
                $t->foreignId('kpi_channel_id')->constrained('kpi_channels')->cascadeOnDelete();
                $t->decimal('forecast', 14, 2)->default(0);      // التوقع
                $t->unsignedInteger('new_target')->default(0);   // مستهدف العملاء الجدد
                $t->decimal('reporting', 6, 4)->default(0.95);   // درجة التقارير 0..1
                $t->timestamps();

                $t->unique(['period', 'role', 'kpi_channel_id']);
            });
        }

        if (! Schema::hasColumn('products', 'is_focus')) {
            Schema::table('products', function (Blueprint $t) {
                // أصناف التركيز — مؤشر «مزيج المنتجات» بيقيس مبيعاتها
                $t->boolean('is_focus')->default(false)->after('active');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_inputs');
        Schema::dropIfExists('kpi_bands');
        Schema::dropIfExists('kpi_metrics');
        Schema::dropIfExists('kpi_channels');

        if (Schema::hasColumn('products', 'is_focus')) {
            Schema::table('products', fn (Blueprint $t) => $t->dropColumn('is_focus'));
        }
    }
};
