<?php

namespace Database\Seeders;

use App\Models\KpiBand;
use App\Models\KpiChannel;
use App\Models\KpiMetric;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * ═══ سيدر نظام العمولات والـKPI (٢٣ أغسطس ٢٠٢٦) ═══
 *
 * القيم الافتراضية **بالحرف** من نموذج الإكسيل المعتمد
 * (ProMax_Commission_KPI_Calculator — شيت Setup). كل القيم قابلة
 * للتعديل بعدين من شاشة إعدادات الـKPI.
 *
 * ⚠️ آمن على الإعادة — firstOrCreate/updateOrCreate في كل حتة.
 */
class KpiSeeder extends Seeder
{
    public function run(): void
    {
        // ═══ القناتين — بأسماء المديرين من شيت Commission_Policy ═══
        $spec = KpiChannel::firstOrCreate(['name' => 'Specialty'], [
            'name_ar' => 'سبيشيالتي',
            'manager_id' => User::where('name', 'like', '%حجر%')->value('id'),
            'rep_gate' => 200000, 'rep_max_rate' => 0.015,
            'manager_gate' => 800000, 'manager_rate' => 0.01,
            'director_gate' => 800000, 'director_rate' => 0.005,
        ]);

        $conv = KpiChannel::firstOrCreate(['name' => 'Convenience & Contracted'], [
            'name_ar' => 'كونفينيانس وعقود',
            'manager_id' => User::where('name', 'like', '%عمرو%')->where('role', 'manager')->value('id'),
            'rep_gate' => 400000, 'rep_max_rate' => 0.01,
            'manager_gate' => 1600000, 'manager_rate' => 0.01,
            'director_gate' => 1600000, 'director_rate' => 0.01,
        ]);

        // ═══ سياسة الحافز — Setup!K5:K10 ═══
        foreach ([
            'kpi_rep_rate' => '0.01',        // نسبة KPI المندوب
            'kpi_manager_rate' => '0.01',    // نسبة KPI المدير
            'kpi_director_rate' => '0.01',   // نسبة KPI المدير العام
            'kpi_min_score' => '75',         // الحد الأدنى للحافز (من 100)
            'kpi_require_gate' => '1',       // اشتراط تحقيق التارجت
            'kpi_gate_threshold' => '0.8',   // البوابة من 80%
        ] as $k => $v) {
            Setting::firstOrCreate(['key' => $k], ['value' => $v]);
        }

        // ═══ شرائح معامل الأداء — Setup!J13:K16 ═══
        if (KpiBand::where('kind', 'multiplier')->count() === 0) {
            foreach ([[0, 0.7], [30, 0.8], [40, 0.9], [50, 1.0]] as [$from, $mult]) {
                KpiBand::create(['kind' => 'multiplier', 'from_value' => $from, 'value' => $mult]);
            }
        }

        // ═══ شرائح نسبة التارجت بالقناة — Setup!J20:L22 ═══
        foreach ([
            [$spec->id, [[0.8, 0.01], [0.9, 0.0125], [1.0, 0.015]]],
            [$conv->id, [[0.8, 0.01], [0.9, 0.01], [1.0, 0.01]]],
        ] as [$chId, $bands]) {
            if (KpiBand::where('kind', 'rate')->where('kpi_channel_id', $chId)->count() === 0) {
                foreach ($bands as [$from, $rate]) {
                    KpiBand::create(['kind' => 'rate', 'kpi_channel_id' => $chId,
                        'from_value' => $from, 'value' => $rate]);
                }
            }
        }

        // ═══ مؤشرات المندوب (١٣) — Setup!A11:F23 · Σ = 100 ═══
        // [key, ar, en, weight, direction, specialtyTarget, convenienceTarget]
        $repMetrics = [
            ['stability', 'استقرار العملاء النشطين', 'Active Account Stability', 13, 'higher', 0.85, 0.85],
            ['growth', 'معدل النمو لكل عميل', 'Growth Rate per Account', 8, 'higher', 0.05, 0.05],
            ['coverage', 'تغطية المناطق', 'Coverage Area', 6, 'higher', 0.9, 0.9],
            ['salesPerAccount', 'المبيعات لكل عميل نشط', 'Sales per Active Account', 6, 'higher', 7000, 9000],
            ['mix', 'مزيج المنتجات', 'Sales Mix Products', 8, 'higher', 0.3, 0.3],
            ['newAccounts', 'العملاء الجدد المؤهلون', 'New Qualified Accounts', 10, 'higher', 5, 3],
            ['followup', 'دقة المتابعة', 'Follow-up Accuracy', 7, 'higher', 0.95, 0.95],
            ['sla', 'الالتزام بزمن تنفيذ الطلب', 'Order SLA', 7, 'higher', 0.95, 0.95],
            ['fifo', 'تطبيق FIFO وتصريف المخزون', 'FIFO & On-hand Clearance', 5, 'higher', 0.95, 0.95],
            ['reorder', 'معدل تكرار الطلب', 'Repeated Order Rate', 9, 'higher', 0.75, 0.75],
            ['collectionQuality', 'جودة التحصيل', 'Collection Quality', 7, 'lower', 0.1, 0.1],
            ['defectRate', 'نسبة البضاعة المعيبة', 'Defect Rate', 7, 'lower', 0.02, 0.02],
            ['returnRate', 'نسبة المرتجعات', 'Return Rate', 7, 'lower', 0.03, 0.03],
        ];

        foreach ($repMetrics as $i => [$key, $ar, $en, $w, $dir, $ts, $tc]) {
            KpiMetric::updateOrCreate(['scope' => 'rep', 'key' => $key], [
                'name_ar' => $ar, 'name_en' => $en, 'weight' => $w, 'direction' => $dir,
                'target' => $ts,
                'targets' => [(string) $spec->id => $ts, (string) $conv->id => $tc],
                'sort' => $i,
            ]);
        }

        // ═══ مؤشرات الإدارة (١٢) — Setup!A28:E39 · Σ = 100 ═══
        $leaderMetrics = [
            ['salesTarget', 'تحقيق تارجت القناة', 'Channel Sales Target Achievement', 16, 'higher', 1],
            ['forecastAccuracy', 'دقة التوقعات', 'Forecast Accuracy', 12, 'higher', 0.9],
            ['collectionQuality', 'جودة التحصيل', 'Collection Quality', 10, 'lower', 0.1],
            ['newCustomers', 'العملاء الجدد المؤهلون', 'New Qualified Customers', 9, 'higher', 1],
            ['reorder', 'معدل تكرار الطلب', 'Repeating Order Rate', 9, 'higher', 0.75],
            ['loyalty', 'ولاء واستبقاء العملاء', 'Customer Loyalty & Retention', 9, 'higher', 0.85],
            ['mix', 'تحقيق مزيج المنتجات', 'Product Mix Achievement', 7, 'higher', 0.3],
            ['teamPerformance', 'أداء الفريق', 'Team Performance', 6, 'higher', 0.85],
            ['followup', 'الالتزام بالمتابعة', 'Follow-up Compliance', 5, 'higher', 0.95],
            ['reporting', 'التقارير والتنسيق', 'Reporting & Coordination', 3, 'higher', 0.95],
            ['defectRate', 'نسبة البضاعة المعيبة', 'Defect Rate', 7, 'lower', 0.02],
            ['returnRate', 'نسبة المرتجعات', 'Return Rate', 7, 'lower', 0.03],
        ];

        foreach ($leaderMetrics as $i => [$key, $ar, $en, $w, $dir, $t]) {
            KpiMetric::updateOrCreate(['scope' => 'leader', 'key' => $key], [
                'name_ar' => $ar, 'name_en' => $en, 'weight' => $w, 'direction' => $dir,
                'target' => $t, 'targets' => null, 'sort' => $i,
            ]);
        }
    }
}
