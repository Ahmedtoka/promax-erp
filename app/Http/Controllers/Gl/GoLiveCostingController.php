<?php

namespace App\Http\Controllers\Gl;

use App\Http\Controllers\Controller;
use App\Services\Costing\CostingGlSeed;
use App\Services\Costing\CostingSettings;
use App\Services\Gl\GoLive\Steps\RunStep;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * معالج التشغيل — خطوة ٧ (إعدادات التكاليف) وخطوة ٩ (التشغيل الفعلي) — الخط ٩،
 * المهمة ٩. الكتابة أدمن بس (`role:admin` على الراوت + `act.gl.admin`)،
 * والتقدّم (JSON) مقروء للمحاسب كمان.
 */
class GoLiveCostingController extends Controller
{
    // ═══ ٧ — الإعدادات ═══
    public function save(Request $request)
    {
        $data = $request->validate([
            'cost_method' => ['required', Rule::in(CostingSettings::METHODS)],
            'cost_default_kg_rate' => ['required', 'numeric', 'gt:0', 'max:999999'],
            'cost_unit_kg_fallback' => ['required', 'numeric', 'gt:0', 'max:9999'],
            'cost_markup_pct' => ['required', 'numeric', 'min:0', 'max:500'],
            'cost_alert_pct' => ['required', 'numeric', 'min:1', 'max:100'],
            'cost_branch_alloc' => ['required', Rule::in(CostingSettings::ALLOC)],
        ]);
        CostingSettings::save($data, $request->user());

        return redirect()->route('gl.golive.show', 7)->with('ok', __('golive.s7_saved'));
    }

    // ═══ ٧ — حسابات وقواعد التكاليف: معاينة الأول، وبعدين التشغيل ═══
    public function seed(Request $request)
    {
        $dry = $request->boolean('dry_run');
        if (! $dry && ($why = CostingGlSeed::blocker()) !== null) {
            return back()->withErrors(['golive' => $why]);
        }
        $r = CostingGlSeed::run($dry);

        return redirect()->route('gl.golive.show', 7)
            ->with('ok', $dry ? __('golive.s7_seed_dry') : __('golive.s7_seeded'))
            ->with('costing_seed', $r + ['dry' => $dry]);
    }

    // ═══ ٩ — التشغيل الفعلي (D13) ═══
    public function run(Request $request)
    {
        set_time_limit(0);
        ignore_user_abort(true);

        try {
            $run = RunStep::run($request->user());
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['golive' => $e->getMessage()]);
        }

        return redirect()->route('gl.golive.show', 9)->with(
            $run->status === 'ok' ? 'ok' : 'err',
            $run->status === 'ok' ? __('golive.s9_ran_ok') : __('golive.s9_failed', ['msg' => (string) $run->error]),
        );
    }

    public function progress()
    {
        return response()->json(RunStep::progress());
    }
}
