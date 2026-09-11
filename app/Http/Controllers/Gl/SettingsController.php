<?php

namespace App\Http\Controllers\Gl;

use App\Http\Controllers\Controller;
use App\Models\Gl\GlAccount;
use App\Models\Gl\GlPeriod;
use App\Models\Gl\GlPostingRule;
use App\Models\Setting;
use App\Services\Gl\ClosedPeriod;
use App\Services\Gl\Ledger;
use App\Services\Gl\RebuildFailed;
use App\Services\Gl\RebuildReport;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * ═══════════════════════════════════════════════════════════════
 * إعدادات الدفتر — القواعد، الفترات، وإعادة البناء
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **إعادة البناء بتمسح كل القيود الآلية وتولّدها تاني.** عشان كده
 * فيه «معاينة» (dry run) بترجّع نفس التقرير بالظبط وبتعمل rollback في
 * الآخر — الفرق بين الأرصدة قبل وبعد بيتعرض قبل أي كتابة. التشغيل
 * الحقيقي أدمن بس، والمعاينة كمان: الفرق بين القبل والبعد بيكشف
 * أسعار وأرصدة الشركة كلها في صفحة واحدة.
 */
class SettingsController extends Controller
{
    public function __construct(private Ledger $ledger)
    {
    }

    public function index()
    {
        return $this->page();
    }

    /** صفحة الإعدادات — بتتنادى من `index()` ومن إعادة البناء بتقريرها */
    private function page(?RebuildReport $report = null, ?string $error = null)
    {
        $view = view('gl.settings', [
            'rules' => GlPostingRule::orderBy('key')->get(),
            'keys' => GlAccount::whereNotNull('system_key')->orderBy('code')->get(),
            'general' => [
                'gl_enabled' => Setting::read('gl_enabled') === '1',
                'gl_start_date' => Setting::read('gl_start_date'),
                'gl_bank_name' => Setting::read('gl_bank_name'),
            ],
            'periods' => $this->periods(),
            'invariants' => $this->invariants(),
            'report' => $report,
        ]);

        return $error === null ? $view : $view->with('rebuildError', $error);
    }

    /**
     * آخر ١٨ شهر بحالتها — الشهر اللي مالوش صف في `gl_periods` مفتوح.
     *
     * @return list<array{key: string, closed: bool}>
     */
    private function periods(): array
    {
        $status = GlPeriod::pluck('status', 'key')->all();
        $out = [];
        $cursor = today()->startOfMonth();

        for ($i = 0; $i < 18; $i++) {
            $key = $cursor->format('Y-m');
            $out[] = ['key' => $key, 'closed' => ($status[$key] ?? 'open') === 'closed'];
            $cursor->subMonth();
        }

        return $out;
    }

    /**
     * ⚠️ **الشجرة ممكن ماتكونش متزرعة** (بيئة جديدة قبل `GlSeeder`) —
     * و`Ledger::invariants()` بترمي `RuntimeException` وقتها. الشاشة
     * لازم تفتح وتقول «الشجرة مش متولّدة» بدل ما ترمي ٥٠٠.
     */
    private function invariants(): ?array
    {
        if (! GlAccount::whereIn('system_key', ['receivables', 'payables'])->exists()) {
            return null;
        }

        return $this->ledger->invariants();
    }

    public function saveRules(Request $request)
    {
        $keys = GlAccount::SYSTEM_KEYS;

        $data = $request->validate([
            'rules' => ['required', 'array'],
            'rules.*.debit_key' => ['nullable', Rule::in($keys)],
            'rules.*.credit_key' => ['nullable', Rule::in($keys)],
            'rules.*.tax_key' => ['nullable', Rule::in($keys)],
            'rules.*.active' => ['nullable', 'boolean'],
        ]);

        foreach ($data['rules'] as $key => $values) {
            $rule = GlPostingRule::where('key', $key)->first();
            if ($rule === null) {
                continue;   // مفتاح مش موجود — مش بنولّد قواعد من الفورم
            }
            // ⚠️ `?? null` على الخانات كلها — بوست ناقص خانة (سيلكت
            // متشال من الـDOM) كان بيرمي «Undefined array key» بدل ما
            // يعتبرها فاضية، والفاليديشن مابتلزمش وجودها أصلاً
            $rule->update([
                'debit_key' => ($values['debit_key'] ?? null) ?: null,
                'credit_key' => ($values['credit_key'] ?? null) ?: null,
                'tax_key' => ($values['tax_key'] ?? null) ?: null,
                'active' => (bool) ($values['active'] ?? false),
            ]);
        }

        GlPostingRule::flush();

        return redirect()->route('gl.settings')->with('ok', __('gl.rules_saved'));
    }

    public function saveGeneral(Request $request)
    {
        $data = $request->validate([
            'gl_start_date' => ['nullable', 'date'],
            'gl_bank_name' => ['nullable', 'string', 'max:80'],
            'gl_enabled' => ['nullable', 'boolean'],
        ]);

        Setting::writeMany([
            'gl_start_date' => $data['gl_start_date'] ? Carbon::parse($data['gl_start_date'])->toDateString() : null,
            'gl_bank_name' => $data['gl_bank_name'] ?: null,
            'gl_enabled' => $request->boolean('gl_enabled') ? '1' : '0',
        ]);

        // اسم البنك بيتكتب على حساب البنك نفسه كمان — عشان كشف الحساب
        // والتقارير يقولوا «بنك CIB» مش «البنك»
        if (! empty($data['gl_bank_name'])) {
            GlAccount::where('system_key', 'bank')->update(['name' => $data['gl_bank_name']]);
        }

        return redirect()->route('gl.settings')->with('ok', __('gl.settings_saved'));
    }

    public function closePeriod(Request $request, string $key)
    {
        if (preg_match('/^\d{4}-\d{2}$/', $key) !== 1) {
            return back()->withErrors(['period' => __('gl.period_bad_key')]);
        }

        GlPeriod::close($key, $request->user());

        return redirect()->route('gl.settings')->with('ok', __('gl.period_closed_ok', ['period' => $key]));
    }

    public function reopenPeriod(Request $request, string $key)
    {
        if (preg_match('/^\d{4}-\d{2}$/', $key) !== 1) {
            return back()->withErrors(['period' => __('gl.period_bad_key')]);
        }

        GlPeriod::reopen($key, $request->user());

        return redirect()->route('gl.settings')->with('ok', __('gl.period_reopened_ok', ['period' => $key]));
    }

    public function rebuildPreview(Request $request)
    {
        try {
            $report = $this->ledger->rebuild($this->rebuildFrom(), $request->boolean('keep_overrides', true), true, $request->user());
        } catch (ClosedPeriod $e) {
            return $this->page(null, $e->getMessage());
        }

        // ⚠️ **فيو مش redirect.** التقرير فيه جدول فروق ممكن يكون طويل،
        // وتمريره في السيشن كان بيكبّر الكوكي/الملف من غير داعي — وكمان
        // المعاينة مش بتغيّر حاجة فمافيش سبب لـPOST/Redirect/GET
        return $this->page($report);
    }

    public function rebuild(Request $request)
    {
        try {
            $report = $this->ledger->rebuild($this->rebuildFrom(), $request->boolean('keep_overrides', true), false, $request->user());
        } catch (RebuildFailed $e) {
            return $this->page($e->report, $e->getMessage());
        } catch (ClosedPeriod $e) {
            return $this->page(null, $e->getMessage());
        }

        // ⚠️ `session()->now()` مش `flash()` — إحنا بنرجّع الفيو نفسه
        // (مش redirect)، والـflash كان هيظهر على الطلب **اللي بعده**
        session()->now('ok', __('gl.rebuild_ok'));

        return $this->page($report);
    }

    private function rebuildFrom(): Carbon
    {
        return Carbon::parse(Setting::read('gl_start_date') ?: '1970-01-01');
    }
}
