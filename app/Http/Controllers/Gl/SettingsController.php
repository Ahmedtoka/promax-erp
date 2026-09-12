<?php

namespace App\Http\Controllers\Gl;

use App\Http\Controllers\Controller;
use App\Models\Gl\GlAccount;
use App\Models\Gl\GlEntry;
use App\Models\Gl\GlPeriod;
use App\Models\Gl\GlPostingRule;
use App\Models\Setting;
use App\Services\Gl\ClosedPeriod;
use App\Services\Gl\Ledger;
use App\Services\Gl\RebuildFailed;
use App\Services\Gl\RebuildReport;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
    /**
     * `rep_cash` مفتاح **مجموعة** مش حساب ترحيل — بيتحل لحساب المندوب
     * وقت الترحيل، وده بيشتغل بس في القواعد اللي الريزولفر بتاعها
     * بيستنتج المندوب من المستند. حطّه في قاعدة تانية = سطر على مجموعة
     * (الرصيد بيتعدّ مرتين) أو قيد رايح للخزنة وهو اتحصّل في الشارع.
     *
     * @var array<string, list<string>>
     */
    private const REP_CASH_SLOTS = [
        'tx.collection.rep_cash' => ['debit_key', 'credit_key'],
        'tx.collection.auto' => ['debit_key', 'credit_key'],
        'tx.refund' => ['debit_key', 'credit_key'],
        'cash.rep_advance' => ['debit_key', 'credit_key'],
        'cash.rep_return' => ['debit_key', 'credit_key'],
        // الطرف التاني في القاعدتين دول متكتب في الكود على حساب المندوب
        // (`Rules::settlement()` / `Rules::expense()`) — المفتاح على الطرف
        // ده بس هو اللي ليه معنى
        'settle.received' => ['credit_key'],
        'expense.rep_cash' => ['credit_key'],
    ];

    public function __construct(private Ledger $ledger)
    {
    }

    /**
     * الطرف المدين في قواعد المصروفات جاي من السند نفسه (حساب المصروف
     * اللي المحاسب اختاره)، مش من القاعدة — فمفتاحه مش مطلوب.
     */
    private static function requiresKey(string $ruleKey, string $slot): bool
    {
        if ($slot === 'credit_key') {
            return true;
        }

        return $slot === 'debit_key' && ! str_starts_with($ruleKey, 'expense.');
    }

    public function index()
    {
        return $this->page();
    }

    /** صفحة الإعدادات — بتتنادى من `index()` ومن إعادة البناء بتقريرها */
    private function page(?RebuildReport $report = null, ?string $error = null)
    {
        $rules = GlPostingRule::orderBy('key')->get();

        $view = view('gl.settings', [
            'rules' => $rules,
            'brokenRules' => $this->brokenRules($rules),
            // القايمة بتعرض حسابات الترحيل بس — و`rep_cash` (مجموعة) في
            // القواعد اللي بتفهمه لوحدها، عشان الشاشة ماتعرضش اختيار
            // الفاليديشن هيرفضه بعد الحفظ
            'repCashSlots' => self::REP_CASH_SLOTS,
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
     * القواعد اللي هتوقف الترحيل — موقوفة أو ناقصة مفتاح مطلوب. بتتعرض
     * في بانر فوق الشاشة: المحاسب بيكتشف القاعدة الموقوفة النهاردة لما
     * إعادة البناء ترفض أو لما فاتورة تعدي من غير قيد، مش قبلها.
     *
     * @param  \Illuminate\Support\Collection<int, GlPostingRule>  $rules
     * @return list<array{key: string, label: string, why: string}>
     */
    private function brokenRules($rules): array
    {
        $out = [];
        foreach ($rules as $r) {
            $why = [];
            if (! $r->active) {
                $why[] = __('gl.rule_inactive');
            }
            foreach (['debit_key', 'credit_key'] as $slot) {
                if (self::requiresKey($r->key, $slot) && ! $r->{$slot}) {
                    $why[] = __('gl.rule_missing_key', ['side' => __('gl.rule_'.($slot === 'debit_key' ? 'debit' : 'credit'))]);
                }
            }
            if ($why !== []) {
                $out[] = ['key' => $r->key, 'label' => $r->label ?: $r->key, 'why' => implode(' · ', $why)];
            }
        }

        return $out;
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

    /**
     * ⚠️ **قاعدة ناقصة مفتاح = الترحيل بيقع.** `Rules` بتحوّل المفتاح
     * الفاضي دلوقتي لـ«حساب معلّق» + `needs_review` بدل ما ترمي، لكن ده
     * إنقاذ مش هدف: القيد بيروح حساب مالوش معنى محاسبي وإعادة البناء
     * بترفض بعدها (الفحص الثابت بيقع). فالشاشة هي الحارس الأول — مفيش
     * حفظ لقاعدة من غير طرفها، ومفيش مفتاح مجموعة على طرف مابيفهموش.
     */
    public function saveRules(Request $request)
    {
        $data = $request->validate([
            'rules' => ['required', 'array'],
            'rules.*.debit_key' => ['nullable', 'string', 'max:40'],
            'rules.*.credit_key' => ['nullable', 'string', 'max:40'],
            'rules.*.tax_key' => ['nullable', 'string', 'max:40'],
            'rules.*.active' => ['nullable', 'boolean'],
        ]);

        $postable = GlAccount::where('is_postable', true)->where('active', true)
            ->whereNotNull('system_key')->pluck('system_key')->all();
        $known = GlPostingRule::whereIn('key', array_keys($data['rules']))->get()->keyBy('key');
        $sideLabel = ['debit_key' => __('gl.rule_debit'), 'credit_key' => __('gl.rule_credit'), 'tax_key' => __('gl.rule_tax')];

        $errors = [];
        foreach ($data['rules'] as $key => $values) {
            $rule = $known->get($key);
            if ($rule === null) {
                continue;   // مفتاح مش موجود — مش بنولّد قواعد من الفورم
            }
            $label = $rule->label ?: $key;

            foreach (['debit_key', 'credit_key', 'tax_key'] as $slot) {
                $field = 'rules.'.$key.'.'.$slot;
                $value = ($values[$slot] ?? null) ?: null;

                if ($value === null) {
                    if ($slot !== 'tax_key' && self::requiresKey($key, $slot)) {
                        $errors[$field][] = __('gl.rule_key_required', ['rule' => $label, 'side' => $sideLabel[$slot]]);
                    }

                    continue;
                }

                if ($value === 'rep_cash') {
                    // حساب الضريبة مابيتحلش لمندوب أبداً — مفيش ريزولفر ليه
                    if ($slot === 'tax_key' || ! in_array($slot, self::REP_CASH_SLOTS[$key] ?? [], true)) {
                        $errors[$field][] = __('gl.rule_rep_cash_not_allowed', ['rule' => $label, 'side' => $sideLabel[$slot]]);
                    }

                    continue;
                }

                if (! in_array($value, $postable, true)) {
                    $errors[$field][] = __('gl.rule_key_not_postable', ['rule' => $label, 'key' => $value]);
                }
            }
        }

        if ($errors !== []) {
            // ولا قاعدة بتتحفظ — البوست كله واحد، وحفظ نصه كان بيسيب
            // الشجرة في حالة نص مظبوطة أسوأ من رفض الحفظ كله
            throw ValidationException::withMessages($errors);
        }

        foreach ($data['rules'] as $key => $values) {
            $rule = $known->get($key);
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

        // ⚠️ `?? null` — الفاليديشن `nullable` مابيحطش المفتاح أصلاً لو
        // الخانة مابعتتش (فورم ناقص خانة/بوست من تيست)، وكان بيرمي
        // «Undefined array key» بدل ما يعتبرها فاضية
        $old = Setting::read('gl_start_date');
        $new = ($data['gl_start_date'] ?? null) ? Carbon::parse($data['gl_start_date'])->toDateString() : null;

        Setting::writeMany([
            'gl_start_date' => $new,
            'gl_bank_name' => ($data['gl_bank_name'] ?? null) ?: null,
            'gl_enabled' => $request->boolean('gl_enabled') ? '1' : '0',
        ]);

        // ⚠️ **تاريخ البداية لما يتحرك لقدام لازم القيود القديمة تتشال.**
        // `post()` بترفض أي مستند قبل البداية، لكن القيود اللي اتولدت
        // وهي البداية أقدم بتفضل في الدفتر: الشجرة بتفضل شايلة فترة
        // المفروض إنها برّه الدفتر، والفحص الثابت (بيعدّ المستندات من
        // البداية الجديدة) بيقع ويمنع أي إعادة بناء بعدها.
        // القيد اليدوي/الافتتاحي مايتلمسش — ده قرار محاسب مش اشتقاق.
        $purged = 0;
        if ($new !== null && ($old === null || $new > $old)) {
            $purged = (int) DB::transaction(
                fn () => GlEntry::where('origin', 'auto')->whereDate('date', '<', $new)->delete()
            );
        }

        // اسم البنك بيتكتب على حساب البنك نفسه كمان — عشان كشف الحساب
        // والتقارير يقولوا «بنك CIB» مش «البنك»
        if (! empty($data['gl_bank_name'])) {
            GlAccount::where('system_key', 'bank')->update(['name' => $data['gl_bank_name']]);
        }

        $msg = __('gl.settings_saved');
        if ($purged > 0) {
            $msg .= ' — '.__('gl.start_moved_purged', ['n' => $purged]);
        }

        return redirect()->route('gl.settings')->with('ok', $msg);
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
