<?php

namespace App\Http\Controllers;

use App\Models\KpiBand;
use App\Models\KpiChannel;
use App\Models\KpiInput;
use App\Models\KpiMetric;
use App\Models\Product;
use App\Models\Setting;
use App\Services\Kpi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ═══ شاشات العمولات والـKPI (٢٣ أغسطس ٢٠٢٦) ═══
 *
 * الحاسبة الشهرية (أدمن + مدير قناته بس) + شاشة الإعدادات (أدمن):
 * «شاشة أعدل فيها النسب براحتي وبناء عليه يتغير كل شيء» — كل قيمة
 * في النموذج قابلة للتعديل، والفحوصات (أوزان = 100 وسقف 3%) حية.
 */
class KpiController extends Controller
{
    /** الحاسبة الشهرية */
    public function index(Request $request)
    {
        $period = preg_match('/^\d{4}-\d{2}$/', (string) $request->input('period'))
            ? $request->input('period')
            : now()->format('Y-m');

        $result = Kpi::calculate($period);

        // ⚠️ سكوب المدير: قناته بس — الأدمن بيشوف الكل
        $u = $request->user();
        if ($u->role === 'manager') {
            $result['channels'] = array_values(array_filter(
                $result['channels'],
                fn ($c) => (int) $c['channel']->manager_id === (int) $u->id,
            ));
        }

        // تصدير CSV — صف لكل مندوب + صفوف القادة
        if ($request->boolean('export')) {
            return $this->csv($result, $period);
        }

        return view('erp.kpi', [
            'period' => $period,
            'result' => $result,
            'checks' => Kpi::checks(),
        ]);
    }

    /** حفظ المدخلات اليدوية الشهرية (توقع/مستهدف عملاء/تقارير) */
    public function saveInputs(Request $request)
    {
        $data = $request->validate([
            'period' => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'rows' => ['required', 'array'],
            'rows.*.role' => ['required', 'in:manager,director'],
            'rows.*.kpi_channel_id' => ['required', 'exists:kpi_channels,id'],
            'rows.*.forecast' => ['nullable', 'numeric', 'min:0'],
            'rows.*.new_target' => ['nullable', 'integer', 'min:0'],
            'rows.*.reporting' => ['nullable', 'numeric', 'min:0', 'max:1'],
        ]);

        foreach ($data['rows'] as $row) {
            KpiInput::updateOrCreate(
                ['period' => $data['period'], 'role' => $row['role'],
                    'kpi_channel_id' => $row['kpi_channel_id']],
                ['forecast' => (float) ($row['forecast'] ?? 0),
                    'new_target' => (int) ($row['new_target'] ?? 0),
                    'reporting' => (float) ($row['reporting'] ?? 0.95)],
            );
        }

        return back()->with('ok', __('kpi.inputs_saved'));
    }

    /** شاشة الإعدادات */
    public function setup()
    {
        return view('erp.kpi_setup', [
            'channels' => KpiChannel::with('manager')->orderBy('id')->get(),
            'repMetrics' => KpiMetric::where('scope', 'rep')->orderBy('sort')->get(),
            'leaderMetrics' => KpiMetric::where('scope', 'leader')->orderBy('sort')->get(),
            'multBands' => KpiBand::where('kind', 'multiplier')->orderBy('from_value')->get(),
            'rateBands' => KpiBand::where('kind', 'rate')->orderBy('kpi_channel_id')->orderBy('from_value')->get(),
            'policy' => Kpi::policy(),
            'managers' => \App\Models\User::whereIn('role', \App\Models\User::ASSIGNABLE_MANAGER_ROLES)
                ->where('active', true)->orderBy('name')->get(),
            'focusProducts' => Product::sellable()->orderBy('name')
                ->get(['id', 'name', 'name_en', 'code', 'is_focus']),
            'checks' => Kpi::checks(),
        ]);
    }

    /** حفظ الإعدادات كلها — ذرّي */
    public function saveSetup(Request $request)
    {
        $data = $request->validate([
            // القنوات
            'channels' => ['required', 'array'],
            'channels.*.id' => ['required', 'exists:kpi_channels,id'],
            'channels.*.manager_id' => ['nullable', 'exists:users,id'],
            'channels.*.rep_gate' => ['required', 'numeric', 'min:0'],
            'channels.*.rep_max_rate' => ['required', 'numeric', 'min:0', 'max:0.1'],
            'channels.*.manager_gate' => ['required', 'numeric', 'min:0'],
            'channels.*.manager_rate' => ['required', 'numeric', 'min:0', 'max:0.1'],
            'channels.*.director_gate' => ['required', 'numeric', 'min:0'],
            'channels.*.director_rate' => ['required', 'numeric', 'min:0', 'max:0.1'],
            // السياسة
            'policy.rep_rate' => ['required', 'numeric', 'min:0', 'max:0.1'],
            'policy.manager_rate' => ['required', 'numeric', 'min:0', 'max:0.1'],
            'policy.director_rate' => ['required', 'numeric', 'min:0', 'max:0.1'],
            'policy.min_score' => ['required', 'numeric', 'min:0', 'max:100'],
            'policy.require_gate' => ['required', 'in:0,1'],
            'policy.gate' => ['required', 'numeric', 'min:0', 'max:1'],
            // المؤشرات
            'metrics' => ['required', 'array'],
            'metrics.*.id' => ['required', 'exists:kpi_metrics,id'],
            'metrics.*.weight' => ['required', 'numeric', 'min:0', 'max:100'],
            'metrics.*.direction' => ['required', 'in:higher,lower'],
            'metrics.*.target' => ['required', 'numeric'],
            'metrics.*.targets' => ['nullable', 'array'],
            // الشرائح
            'mult_bands' => ['required', 'array', 'min:1'],
            'mult_bands.*.from' => ['required', 'numeric', 'min:0', 'max:100'],
            'mult_bands.*.value' => ['required', 'numeric', 'min:0', 'max:2'],
            'rate_bands' => ['nullable', 'array'],
            'rate_bands.*.channel_id' => ['required', 'exists:kpi_channels,id'],
            'rate_bands.*.from' => ['required', 'numeric', 'min:0', 'max:5'],
            'rate_bands.*.value' => ['required', 'numeric', 'min:0', 'max:0.1'],
            // أصناف التركيز
            'focus_ids' => ['nullable', 'array'],
            'focus_ids.*' => ['integer', 'exists:products,id'],
        ]);

        // ═══ فحص الأوزان قبل الحفظ — نفس شيت Checks: لازم 100 بالظبط ═══
        $metricRows = collect($data['metrics']);
        $byScope = KpiMetric::whereIn('id', $metricRows->pluck('id'))->get()->keyBy('id');

        foreach (['rep', 'leader'] as $scope) {
            $sum = $metricRows->sum(fn ($m) => $byScope[$m['id']]->scope === $scope ? (float) $m['weight'] : 0);
            if (abs($sum - 100) > 0.001) {
                return back()->withErrors(['metrics' => __('kpi.weights_not_100', [
                    'scope' => __('kpi.scope_'.$scope), 'sum' => $sum,
                ])])->withInput();
            }
        }

        DB::transaction(function () use ($data) {
            foreach ($data['channels'] as $c) {
                KpiChannel::whereKey($c['id'])->update([
                    'manager_id' => $c['manager_id'] ?? null,
                    'rep_gate' => $c['rep_gate'], 'rep_max_rate' => $c['rep_max_rate'],
                    'manager_gate' => $c['manager_gate'], 'manager_rate' => $c['manager_rate'],
                    'director_gate' => $c['director_gate'], 'director_rate' => $c['director_rate'],
                ]);
            }

            Setting::writeMany([
                'kpi_rep_rate' => (string) $data['policy']['rep_rate'],
                'kpi_manager_rate' => (string) $data['policy']['manager_rate'],
                'kpi_director_rate' => (string) $data['policy']['director_rate'],
                'kpi_min_score' => (string) $data['policy']['min_score'],
                'kpi_require_gate' => (string) $data['policy']['require_gate'],
                'kpi_gate_threshold' => (string) $data['policy']['gate'],
            ]);

            foreach ($data['metrics'] as $m) {
                KpiMetric::whereKey($m['id'])->update([
                    'weight' => $m['weight'],
                    'direction' => $m['direction'],
                    'target' => $m['target'],
                    'targets' => empty($m['targets']) ? null
                        : json_encode(array_map('floatval', $m['targets'])),
                ]);
            }

            // الشرائح — مسح وإعادة كتابة (أبسط وأضمن من diff)
            KpiBand::where('kind', 'multiplier')->delete();
            foreach ($data['mult_bands'] as $b) {
                KpiBand::create(['kind' => 'multiplier',
                    'from_value' => $b['from'], 'value' => $b['value']]);
            }

            KpiBand::where('kind', 'rate')->delete();
            foreach ($data['rate_bands'] ?? [] as $b) {
                KpiBand::create(['kind' => 'rate', 'kpi_channel_id' => $b['channel_id'],
                    'from_value' => $b['from'], 'value' => $b['value']]);
            }

            // أصناف التركيز
            Product::where('is_focus', true)->update(['is_focus' => false]);
            if (! empty($data['focus_ids'])) {
                Product::whereIn('id', $data['focus_ids'])->update(['is_focus' => true]);
            }
        });

        return back()->with('ok', __('kpi.setup_saved'));
    }

    /** CSV بالـBOM — نفس أسلوب مركز التقارير */
    private function csv(array $result, string $period)
    {
        return response()->streamDownload(function () use ($result) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [__('kpi.c_name'), __('kpi.c_role'), __('kpi.c_channel'),
                __('kpi.c_collections'), __('kpi.c_ach'), __('kpi.c_score'),
                __('kpi.c_base_rate'), __('kpi.c_base'), __('kpi.c_mult'),
                __('kpi.c_after'), __('kpi.c_kpi'), __('kpi.c_final'), __('kpi.c_actual')]);

            foreach ($result['channels'] as $c) {
                foreach ($c['reps'] as $r) {
                    fputcsv($out, [$r['rep']->displayName(), __('kpi.role_rep'),
                        $c['channel']->displayName(),
                        number_format($r['data']['collections'], 2),
                        number_format($r['achievement'] * 100, 1).'%',
                        $r['score'],
                        number_format($r['base_rate'] * 100, 2).'%',
                        number_format($r['base_value'], 2), $r['multiplier'],
                        number_format($r['after_perf'], 2),
                        number_format($r['kpi_earned'], 2),
                        number_format($r['final'], 2),
                        number_format($r['actual_rate'] * 100, 2).'%']);
                }

                foreach ([['manager', __('kpi.role_manager')], ['director', __('kpi.role_director')]] as [$k, $label]) {
                    $r = $c[$k];
                    fputcsv($out, [
                        $k === 'manager' ? ($c['channel']->manager?->displayName() ?? $label) : $label,
                        $label, $c['channel']->displayName(),
                        number_format($r['collections'], 2),
                        number_format($r['achievement'] * 100, 1).'%',
                        $r['score'],
                        number_format($r['base_rate'] * 100, 2).'%',
                        number_format($r['base_value'], 2), $r['multiplier'],
                        number_format($r['after_perf'], 2),
                        number_format($r['kpi_earned'], 2),
                        number_format($r['final'], 2),
                        number_format($r['actual_rate'] * 100, 2).'%']);
                }
            }

            fclose($out);
        }, 'kpi-'.$period.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
