<?php

namespace App\Services\Gl;

use App\Models\Gl\GlAccount;
use App\Models\Gl\GlPostingRule;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * ربط القواعد بالشجرة المعتمدة (D1 / D5)
 * ═══════════════════════════════════════════════════════════════
 *
 * طبقتين:
 *  (أ) **مفتاح النظام** (`gl_accounts.system_key`) بيتنقل على حساب معتمد —
 *      كل القواعد اللي بتشاور على المفتاح بتمشي وراه.
 *  (ب) **خانة قاعدة** (`gl_posting_rules.<side>_account_id`) ممكن تتثبت على
 *      حساب بعينه — `Rules` بتقدّمها على المفتاح، فنوع حركة واحد يروح حساب
 *      المحاسب الخاص من غير ما يلمس باقي القواعد.
 *
 * قبل الاعتماد الشجرة المتولدة شغالة زي ما هي ومفيش حاجة بتتمنع؛ بعد الاعتماد
 * أي مفتاح أو خانة مش مظبوطة بتوقف إعادة البناء (`assertComplete`).
 */
class GlMapping
{
    public const REQUIRED_KEYS = [
        'receivables', 'payables', 'cash_main', 'bank', 'rep_cash', 'sales', 'sales_returns',
        'discounts_allowed', 'vat_output', 'withheld_tax', 'opening_equity', 'expense_purchases',
        'suspense', 'opening_difference', 'inventory',
    ];

    /** ممكن يفضلوا على حساب النظام المتولد/الأوتوماتيك بعد الاعتماد */
    public const SYSTEM_ONLY = ['suspense', 'opening_difference'];

    public const SIDES = ['debit', 'credit', 'tax'];

    /**
     * `rep_cash` مفتاح **مجموعة** — بيتحل لحساب المندوب وقت الترحيل، وده
     * بيشتغل بس في القواعد اللي بتستنتج المندوب من المستند.
     *
     * @var array<string, list<string>>
     */
    public const REP_CASH_SLOTS = [
        'tx.collection.rep_cash' => ['debit_key', 'credit_key'],
        'tx.collection.auto' => ['debit_key', 'credit_key'],
        'tx.refund' => ['debit_key', 'credit_key'],
        'cash.rep_advance' => ['debit_key', 'credit_key'],
        'cash.rep_return' => ['debit_key', 'credit_key'],
        // بيتحلّوا لمندوب التصفية/السند — على الدائن بس
        'settle.received' => ['credit_key'],
        'expense.rep_cash' => ['credit_key'],
    ];

    /** المدين في قواعد المصروفات جاي من السند نفسه، مش من القاعدة */
    public static function fromDocument(string $ruleKey, string $side): bool
    {
        return $side === 'debit' && str_starts_with($ruleKey, 'expense.');
    }

    /** خانة لازم يكون ليها مفتاح (الضريبة اختيارية، ومدين المصروف من السند) */
    public static function requiresKey(string $ruleKey, string $slot): bool
    {
        if ($slot === 'credit_key') {
            return true;
        }

        return $slot === 'debit_key' && ! str_starts_with($ruleKey, 'expense.');
    }

    public static function keyLabel(string $key): string
    {
        $t = 'gl.sk_'.$key;

        return __($t) === $t ? $key : __($t);
    }

    /**
     * @return list<array{key:string, account:?GlAccount, ok:bool, why:?string}>
     */
    public static function keys(): array
    {
        $holders = GlAccount::whereIn('system_key', self::REQUIRED_KEYS)->get()->keyBy('system_key');
        $adopted = CoaAdopter::adopted();
        $out = [];
        foreach (self::REQUIRED_KEYS as $key) {
            $acc = $holders->get($key);
            $why = self::keyWhy($key, $acc, $adopted);
            $out[] = ['key' => $key, 'account' => $acc, 'ok' => $why === null, 'why' => $why];
        }

        return $out;
    }

    /** سبب إن المفتاح مش مظبوط، أو null */
    private static function keyWhy(string $key, ?GlAccount $acc, bool $adopted): ?string
    {
        if ($acc === null) {
            // «فرق الأرصدة الافتتاحية» بيتعمل عند الطلب (`GlAccount::openingDifference`)
            return $key === 'opening_difference' ? null : __('gl.map_why_missing');
        }
        if (! $acc->active) {
            return __('gl.map_why_inactive');
        }
        if ($key === 'rep_cash' ? $acc->is_postable : ! $acc->is_postable) {
            return $key === 'rep_cash' ? __('gl.map_why_not_group') : __('gl.map_why_not_postable');
        }
        if ($adopted && $acc->coa_draft_id === null && ! in_array($key, self::SYSTEM_ONLY, true)) {
            return __('gl.map_why_not_adopted');
        }

        return null;
    }

    /**
     * @return list<array{rule:GlPostingRule, slots: array<string, array{key:?string, account:?GlAccount, pinned:bool, ok:bool, why:?string}>}>
     */
    public static function rules(): array
    {
        $rules = GlPostingRule::orderBy('key')->get();
        $accounts = GlAccount::whereIn('id', $rules->flatMap(fn ($r) => [$r->debit_account_id, $r->credit_account_id, $r->tax_account_id])->filter()->unique()->all())
            ->get()->keyBy('id');
        $holders = GlAccount::whereNotNull('system_key')->get()->keyBy('system_key');
        $adopted = CoaAdopter::adopted();
        $keyWhy = [];

        $out = [];
        foreach ($rules as $rule) {
            $slots = [];
            foreach (self::SIDES as $side) {
                $key = $rule->{$side.'_key'} ?: null;
                $pinId = $rule->{$side.'_account_id'};
                if ($side === 'tax' && $key === null && $pinId === null) {
                    continue;
                }
                if (self::fromDocument($rule->key, $side)) {
                    $slots[$side] = ['key' => null, 'account' => null, 'pinned' => false, 'ok' => true, 'why' => null];

                    continue;
                }
                if ($pinId !== null) {
                    $acc = $accounts->get($pinId);
                    $why = match (true) {
                        $acc === null => __('gl.map_why_missing'),
                        ! $acc->active => __('gl.map_why_inactive'),
                        ! $acc->is_postable => __('gl.map_why_not_postable'),
                        default => null,
                    };
                    $slots[$side] = ['key' => $key, 'account' => $acc, 'pinned' => true, 'ok' => $why === null, 'why' => $why];

                    continue;
                }
                $acc = $key !== null ? $holders->get($key) : null;
                if ($key === null) {
                    $why = __('gl.map_why_no_key');
                } elseif ($key === 'rep_cash' && ! in_array($side.'_key', self::REP_CASH_SLOTS[$rule->key] ?? [], true)) {
                    $why = __('gl.map_why_rep_cash_slot');
                } else {
                    $why = $keyWhy[$key] ??= [self::keyWhy($key, $acc, $adopted)];
                    $why = $why[0];
                }
                $slots[$side] = ['key' => $key, 'account' => $acc, 'pinned' => false, 'ok' => $why === null, 'why' => $why];
            }
            $out[] = ['rule' => $rule, 'slots' => $slots];
        }

        return $out;
    }

    /** @return list<string> */
    public static function problems(): array
    {
        $out = [];
        foreach (self::keys() as $k) {
            if (! $k['ok']) {
                $out[] = __('gl.map_problem_key', ['key' => self::keyLabel($k['key']), 'why' => $k['why']]);
            }
        }
        foreach (self::rules() as $r) {
            $label = $r['rule']->label ?: $r['rule']->key;
            if (! $r['rule']->active) {
                $out[] = __('gl.map_problem_inactive', ['rule' => $label]);

                continue;
            }
            foreach ($r['slots'] as $side => $s) {
                if (! $s['ok']) {
                    $out[] = __('gl.map_problem_slot', ['rule' => $label, 'side' => __('gl.rule_'.$side), 'why' => $s['why']]);
                }
            }
        }

        return $out;
    }

    /** بعد الاعتماد بس (D5) — قبله الشجرة المتولدة شغالة زي ما هي */
    public static function assertComplete(): void
    {
        if (! CoaAdopter::adopted()) {
            return;
        }
        $problems = self::problems();
        if ($problems !== []) {
            throw new UnmappedRules($problems);
        }
    }

    /**
     * سبب رفض نقل المفتاح على الحساب ده، أو null.
     *
     * @param  list<string>  $freed  مفاتيح بتتنقل في نفس الطلب (حاملها هيتفضى)
     */
    public static function keyRefusal(string $key, GlAccount $to, array $freed = []): ?string
    {
        if (! in_array($key, GlAccount::SYSTEM_KEYS, true)) {
            return __('gl.map_bad_key', ['key' => $key]);
        }
        if ($to->system_key !== null && $to->system_key !== $key && ! in_array($to->system_key, $freed, true)) {
            return __('gl.map_holds_key', ['code' => $to->code, 'key' => self::keyLabel($to->system_key)]);
        }
        if (! $to->active) {
            return __('gl.map_account_bad', ['code' => $to->code, 'why' => __('gl.map_why_inactive')]);
        }
        if ($to->user_id !== null) {
            return __('gl.map_account_bad', ['code' => $to->code, 'why' => __('gl.map_why_rep_account')]);
        }
        if ($key === 'rep_cash') {
            // هيبقى مجموعة — سطر عليه مباشرة بيتعدّ مرتين في الشجرة
            if ($to->lines()->exists()) {
                return __('gl.map_account_bad', ['code' => $to->code, 'why' => __('gl.map_why_has_lines')]);
            }
        } elseif (! $to->is_postable) {
            return __('gl.map_account_bad', ['code' => $to->code, 'why' => __('gl.map_why_not_postable')]);
        }

        return null;
    }

    /**
     * ينقل مفتاح النظام على `$to`. لـ`rep_cash`: `$to` بيبقى مجموعة، وحسابات
     * المناديب (`user_id`) بتتنقل تحته بكود `<to.code>.<suffix>`.
     */
    public static function assignKey(string $key, GlAccount $to, User $by): void
    {
        $why = self::keyRefusal($key, $to);
        if ($why !== null) {
            throw new \InvalidArgumentException($why);
        }
        if ($to->system_key === $key && ($key !== 'rep_cash' || ! $to->is_postable)) {
            return;
        }

        DB::transaction(function () use ($key, $to, $by) {
            GlAccount::where('system_key', $key)->where('id', '!=', $to->id)->update(['system_key' => null]);
            $to->system_key = $key;
            if ($key === 'rep_cash') {
                $to->is_postable = false;
            }
            $to->save();

            if ($key === 'rep_cash') {
                foreach (GlAccount::whereNotNull('user_id')->orderBy('id')->get() as $acc) {
                    $pos = strrpos((string) $acc->code, '.');
                    $suffix = $pos === false ? (string) $acc->code : substr((string) $acc->code, $pos + 1);
                    $code = $to->code.'.'.$suffix;
                    for ($n = 2; GlAccount::where('code', $code)->where('id', '!=', $acc->id)->exists(); $n++) {
                        $code = $to->code.'.'.$suffix.'.'.$n;
                    }
                    $acc->update(['parent_id' => $to->id, 'code' => $code, 'type' => $to->type, 'normal_side' => $to->normal_side]);
                }
            }

            Setting::writeMany([
                'gl_mapping_changed_at' => now()->format('Y-m-d H:i:s'),
                'gl_mapping_changed_by' => (string) $by->id,
            ]);
        });
    }

    /** سبب رفض تثبيت الخانة على الحساب، أو null. `null` (مسح التثبيت) دايماً مسموح. */
    public static function pinRefusal(GlPostingRule $rule, string $side, ?GlAccount $account): ?string
    {
        if (! in_array($side, self::SIDES, true)) {
            return __('gl.map_bad_side', ['side' => $side]);
        }
        if ($account === null) {
            return null;
        }
        $label = $rule->label ?: $rule->key;
        $sideLabel = __('gl.rule_'.$side);
        if (self::fromDocument($rule->key, $side)) {
            return __('gl.map_pin_from_document', ['rule' => $label]);
        }
        if ($rule->{$side.'_key'} === 'rep_cash') {
            return __('gl.map_pin_rep_cash', ['rule' => $label, 'side' => $sideLabel]);
        }
        if ($side === 'tax' && ! $rule->tax_key && ! $rule->tax_account_id) {
            return __('gl.map_pin_no_tax', ['rule' => $label]);
        }
        $why = match (true) {
            ! $account->active => __('gl.map_why_inactive'),
            ! $account->is_postable => __('gl.map_why_not_postable'),
            $account->user_id !== null => __('gl.map_why_rep_account'),
            default => null,
        };

        return $why === null ? null : __('gl.map_pin_bad', ['rule' => $label, 'side' => $sideLabel, 'code' => $account->code, 'why' => $why]);
    }

    public static function pin(GlPostingRule $rule, string $side, ?GlAccount $account): void
    {
        $why = self::pinRefusal($rule, $side, $account);
        if ($why !== null) {
            throw new \InvalidArgumentException($why);
        }
        // `saved` بيمسح كاش `forKey`
        $rule->update([$side.'_account_id' => $account?->id]);
    }
}
