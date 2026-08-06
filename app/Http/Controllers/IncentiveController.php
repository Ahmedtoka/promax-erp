<?php

namespace App\Http\Controllers;

use App\Models\CommissionTier;
use App\Models\RepPoint;
use App\Models\RepTarget;
use App\Models\Setting;
use App\Models\User;
use App\Services\RepKpis;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * ═══════════════════════════════════════════════════════════════
 * الحوافز: التارجتات + الأداء + الإعدادات (2026-08-06)
 * ═══════════════════════════════════════════════════════════════
 *
 * - التارجتات شهرية لكل مندوب لوحده (قرار المالك).
 * - الأداء: كل مؤشرات RepKpis لكل مندوب في جدول واحد + منح نقاط يدوي.
 * - الإعدادات: شرايح العمولة + قيم النقاط + نطاق أليرت الليد.
 */
class IncentiveController extends Controller
{
    /** المناديب والسواقين النشطين — نطاق الحوافز كله */
    private function reps()
    {
        return User::whereIn('role', ['sales_agent', 'driver'])
            ->where('active', true)->orderBy('name')->get();
    }

    private function month(Request $request): Carbon
    {
        return Carbon::parse($request->query('month', now()->format('Y-m')).'-01')->startOfMonth();
    }

    // ═══════════ التارجتات ═══════════

    public function targets(Request $request)
    {
        $month = $this->month($request);
        $reps = $this->reps();

        return view('erp.targets', [
            'month' => $month,
            'reps' => $reps,
            'targets' => RepTarget::whereIn('user_id', $reps->pluck('id'))
                ->whereDate('month', $month->toDateString())
                ->get()->keyBy('user_id'),
        ]);
    }

    public function saveTargets(Request $request)
    {
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'rows' => ['required', 'array'],
            'rows.*.money' => ['nullable', 'numeric', 'min:0'],
            'rows.*.clients' => ['nullable', 'integer', 'min:0'],
            'rows.*.visits' => ['nullable', 'integer', 'min:0'],
            'rows.*.pieces' => ['nullable', 'integer', 'min:0'],
        ]);

        $month = Carbon::parse($data['month'].'-01')->startOfMonth()->toDateString();
        $repIds = $this->reps()->pluck('id')->all();

        foreach ($data['rows'] as $userId => $row) {
            if (! in_array((int) $userId, $repIds, true)) {
                continue;
            }

            RepTarget::updateOrCreate(
                ['user_id' => (int) $userId, 'month' => $month],
                [
                    'money_target' => (float) ($row['money'] ?? 0),
                    'new_clients_target' => (int) ($row['clients'] ?? 0),
                    'visits_target' => (int) ($row['visits'] ?? 0),
                    'pieces_target' => (int) ($row['pieces'] ?? 0),
                ],
            );
        }

        return back()->with('ok', __('incent.targets_saved'));
    }

    /** نسخ تارجتات الشهر اللي فات — بداية سريعة للشهر الجديد */
    public function copyTargets(Request $request)
    {
        $data = $request->validate(['month' => ['required', 'date_format:Y-m']]);
        $month = Carbon::parse($data['month'].'-01')->startOfMonth();
        $prev = $month->copy()->subMonthNoOverflow()->toDateString();
        $copied = 0;

        foreach (RepTarget::whereDate('month', $prev)->get() as $t) {
            RepTarget::updateOrCreate(
                ['user_id' => $t->user_id, 'month' => $month->toDateString()],
                $t->only(['money_target', 'new_clients_target', 'visits_target', 'pieces_target']),
            );
            $copied++;
        }

        return back()->with('ok', __('incent.targets_copied', ['count' => $copied]));
    }

    // ═══════════ الأداء ═══════════

    public function performance(Request $request)
    {
        $month = $this->month($request);

        $rows = $this->reps()->map(fn (User $rep) => [
            'rep' => $rep,
            'kpi' => RepKpis::forMonth($rep, $month),
            'km_today' => RepKpis::kmForDay($rep, now()),
        ]);

        return view('erp.performance', [
            'month' => $month,
            'rows' => $rows,
            'pointValue' => (float) Setting::read('point_value', '5'),
            'recentPoints' => RepPoint::with(['user', 'creator'])->latest()->limit(10)->get(),
        ]);
    }

    /** منح/خصم نقاط يدوي — بسبب إجباري */
    public function storePoints(Request $request)
    {
        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'points' => ['required', 'integer', 'between:-1000,1000', 'not_in:0'],
            'reason' => ['required', 'string', 'max:190'],
        ]);

        RepPoint::create($data + ['date' => today(), 'created_by' => $request->user()->id]);

        return back()->with('ok', __('incent.points_saved'));
    }

    // ═══════════ إعدادات الحوافز ═══════════

    public function settings()
    {
        return view('erp.incentives', [
            'tiers' => CommissionTier::orderBy('min_pct')->get(),
            'values' => [
                'point_value' => Setting::read('point_value', '5'),
                'pts_per_visit' => Setting::read('pts_per_visit', '1'),
                'pts_per_new_client' => Setting::read('pts_per_new_client', '10'),
                'pts_per_100_pieces' => Setting::read('pts_per_100_pieces', '1'),
                'lead_alert_km' => Setting::read('lead_alert_km', '1'),
            ],
        ]);
    }

    public function saveSettings(Request $request)
    {
        $data = $request->validate([
            'point_value' => ['required', 'numeric', 'min:0'],
            'pts_per_visit' => ['required', 'integer', 'min:0'],
            'pts_per_new_client' => ['required', 'integer', 'min:0'],
            'pts_per_100_pieces' => ['required', 'integer', 'min:0'],
            'lead_alert_km' => ['required', 'numeric', 'min:0.1', 'max:20'],
            // الشرايح: نسبة تحقيق ← نسبة عمولة (مئوية في الشاشة)
            'tiers' => ['nullable', 'array'],
            'tiers.*.min_pct' => ['required', 'numeric', 'min:0', 'max:1000'],
            'tiers.*.rate' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        Setting::writeMany([
            'point_value' => (string) $data['point_value'],
            'pts_per_visit' => (string) $data['pts_per_visit'],
            'pts_per_new_client' => (string) $data['pts_per_new_client'],
            'pts_per_100_pieces' => (string) $data['pts_per_100_pieces'],
            'lead_alert_km' => (string) $data['lead_alert_km'],
        ]);

        // الشرايح بتتكتب من الأول — الفاضي بيتشال والجديد بيتضاف
        // ⚠️ النسبة بتتقسم على 100 هنا **مرة واحدة** (الدوكترين)
        CommissionTier::query()->delete();

        foreach ($data['tiers'] ?? [] as $tier) {
            CommissionTier::create([
                'min_pct' => (float) $tier['min_pct'],
                'rate' => (float) $tier['rate'] / 100,
            ]);
        }

        return back()->with('ok', __('incent.settings_saved'));
    }
}
