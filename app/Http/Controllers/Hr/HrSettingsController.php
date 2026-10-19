<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Hr\HrSettings;
use App\Support\Access;
use Illuminate\Http\Request;

/**
 * إعدادات المرتبات (Lane 7 — المهمة ٤).
 *
 * • `index` — قراءة للأدمن والموارد البشرية، وتنبيه «راجع الديفولتات»
 *   لحد ما `hr_rates_verified_at` يتختم.
 * • `save` — أدمن بس (`role:admin` + `act.hr.settings`)، والحفظ بيختم المراجعة.
 *
 * ⚠️ شرايح الضريبة لازم تبقى متصلة ومتصاعدة (from = to اللي قبلها)، والأخيرة
 * بس اللي ممكن تبقى من غير سقف — الحساب في `PayrollMath::annualTax` معتمد على كده.
 */
class HrSettingsController extends Controller
{
    /** المفاتيح الرقمية اللي بتتحفظ من الفورم → قواعد التحقق */
    private const NUMERIC = [
        'hr_si_employee_rate' => 'required|numeric|min:0|max:1',
        'hr_si_employer_rate' => 'required|numeric|min:0|max:1',
        'hr_si_min_wage' => 'required|numeric|min:0|max:10000000',
        'hr_si_max_wage' => 'required|numeric|gte:hr_si_min_wage|max:10000000',
        'hr_tax_exemption' => 'required|numeric|min:0|max:100000000',
        'hr_month_days' => 'required|integer|min:1|max:31',
        'hr_day_minutes' => 'required|integer|min:1|max:1440',
        'hr_late_grace_minutes' => 'required|integer|min:0|max:600',
        'hr_overtime_rate' => 'required|numeric|min:0|max:10',
    ];

    public function index(Request $request)
    {
        return view('hr.settings', [
            's' => HrSettings::all(),
            'brackets' => HrSettings::json('hr_tax_brackets'),
            'elimination' => HrSettings::json('hr_tax_elimination'),
            'canEdit' => Access::action($request->user(), 'act.hr.settings'),
        ]);
    }

    public function save(Request $request)
    {
        abort_unless(Access::action($request->user(), 'act.hr.settings'), 403);

        $data = $request->validate(self::NUMERIC + [
            'hr_shift_start' => ['required', 'date_format:H:i'],
            'brackets' => 'required|array|min:1|max:20',
            'brackets.*.from' => 'required|numeric|min:0',
            'brackets.*.to' => 'nullable|numeric|min:0',
            'brackets.*.rate' => 'required|numeric|min:0|max:1',
            'elimination' => 'nullable|array|max:20',
            'elimination.*.over' => 'required|numeric|min:0',
            'elimination.*.upto' => 'required|integer|min:0',
        ]);

        $brackets = [];
        $rows = array_values($data['brackets']);
        foreach ($rows as $i => $r) {
            $from = self::n($r['from']);
            $to = ($r['to'] ?? '') === '' || $r['to'] === null ? null : self::n($r['to']);
            $last = $i === count($rows) - 1;

            if ($i === 0 && $from != 0) {
                return back()->withInput()->withErrors(['brackets' => __('hrm.set_brackets_start_zero')]);
            }
            if ($i > 0 && $from != $brackets[$i - 1][1]) {
                return back()->withInput()->withErrors(['brackets' => __('hrm.set_brackets_gap', ['n' => $i + 1])]);
            }
            if ($to === null && ! $last) {
                return back()->withInput()->withErrors(['brackets' => __('hrm.set_brackets_open_last')]);
            }
            if ($to !== null && $to <= $from) {
                return back()->withInput()->withErrors(['brackets' => __('hrm.set_brackets_order', ['n' => $i + 1])]);
            }
            $brackets[] = [$from, $to, self::n($r['rate'])];
        }

        $elim = [];
        foreach (array_values($data['elimination'] ?? []) as $i => $r) {
            $over = self::n($r['over']);
            $upto = (int) $r['upto'];
            if ($upto >= count($brackets)) {
                return back()->withInput()->withErrors(['elimination' => __('hrm.set_elim_bracket', ['n' => $i + 1])]);
            }
            if ($i > 0 && ($over <= $elim[$i - 1][0] || $upto < $elim[$i - 1][1])) {
                return back()->withInput()->withErrors(['elimination' => __('hrm.set_elim_order', ['n' => $i + 1])]);
            }
            $elim[] = [$over, $upto];
        }

        $pairs = [];
        foreach (array_keys(self::NUMERIC) as $k) {
            $pairs[$k] = (string) $data[$k];
        }
        $pairs['hr_shift_start'] = $data['hr_shift_start'];
        $pairs['hr_tax_brackets'] = json_encode($brackets);
        $pairs['hr_tax_elimination'] = json_encode($elim);
        $pairs['hr_rates_verified_at'] = now()->format('Y-m-d H:i:s');

        Setting::writeMany($pairs);

        return redirect()->route('hr.settings')->with('ok', __('hrm.set_saved'));
    }

    /** رقم صحيح لو مافيش كسور، عشان الـ JSON يفضل نضيف (40000 مش 40000.0) */
    private static function n(mixed $v): int|float
    {
        $f = (float) $v;

        return floor($f) == $f && abs($f) < PHP_INT_MAX ? (int) $f : $f;
    }
}
