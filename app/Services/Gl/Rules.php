<?php

namespace App\Services\Gl;

use App\Models\CashMovement;
use App\Models\CostCenter;
use App\Models\Expense;
use App\Models\FixedAssetDepreciation;
use App\Models\Gl\GlAccount;
use App\Models\Gl\GlPostingRule;
use App\Models\Invoice;
use App\Models\PurchaseOrder;
use App\Models\RepSettlement;
use App\Models\Safe;
use App\Models\SafeCount;
use App\Models\SupplierTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Database\Eloquent\Model;

/**
 * تحويل مستند → سطور قيد حسب `gl_posting_rules`. مفيش كتابة هنا.
 * المفاتيح (`debit_key`/`credit_key`) بتشاور على `gl_accounts.system_key`؛
 * المفتاح الخاص `rep_cash` بيتحل لحساب المندوب المستنتج من المستند،
 * ولو مفيش مندوب بيرجع للخزنة الرئيسية ويعلّم القيد `needs_review`.
 *
 * ⚠️ **مفيش سطر بيطلع من هنا بحساب فاضي ولا حساب مش بيقبل ترحيل.**
 * أي مفتاح ناقص/موقوف/مجموعة بيروح «الحساب المعلّق» (`suspense`) والقيد
 * بيتعلّم `needs_review` — الميزان بيفضل متوازن والمحاسب بيشوف اللي محتاج
 * تصليح في اليومية، بدل ما الترحيل يرمي ويوقف بيع أو تحصيل.
 */
class Rules
{
    /** الأنواع اللي اتجاهها الطبيعي مدين — الباقي اتجاهه الطبيعي دائن */
    public const NATURAL_DEBIT = ['sale', 'refund', 'opening', 'transfer'];

    /** الأنواع اللي اتجاهها الطبيعي دائن — القواعد مكتوبة على الاتجاه ده */
    public const NATURAL_CREDIT = ['collection', 'return', 'rebate', 'settlement', 'taxded'];

    /** @return array{rule:string, memo:string, needs_review:bool, lines:list<array{slot:string,account_id:int,cost_center_id:?int,debit:float,credit:float}>} */
    public function linesFor(Model $source): array
    {
        return $this->withCostCenter($source, match (true) {
            $source instanceof Transaction => $this->transaction($source),
            $source instanceof RepSettlement => $this->settlement($source),
            $source instanceof SupplierTransaction => $this->supplier($source),
            $source instanceof Expense => $this->expense($source),
            $source instanceof CashMovement => $this->cash($source),
            $source instanceof SafeCount => $this->count($source),
            $source instanceof FixedAssetDepreciation => $this->depreciation($source),
            // Lane 7: المرتبات والسلف (مسير معتمد/مصروف، دفعة صرف، سلفة مش ملغية)
            \App\Services\Hr\PayrollGl::handles($source) => \App\Services\Hr\PayrollGl::lines($source),
            // Lane 9: قيود التكلفة (بيع/مرتجع، وبعدين تحويل/إنتاج/انحراف)
            \App\Services\Costing\CostGl::handles($source) => \App\Services\Costing\CostGl::lines($source),
            default => $this->none(),
        });
    }

    /**
     * مركز التكلفة على كل سطر مالوش مركز (خطة القفل ١٩/١٠) — نداء واحد
     * للريزولفر لكل مستند، ومفيش نداء لو مفيش سطور. السطر اللي جاي بمركز
     * (حتى لو null صريح) بيفضل زي ما هو.
     *
     * ⚠️ فشل الريزولفر = سطور من غير مركز، مش قيد ضايع.
     */
    protected function withCostCenter(Model $source, array $result): array
    {
        if (($result['lines'] ?? []) === []) {
            return $result;
        }
        try {
            $center = $this->costCenterFor($source);
        } catch (\Throwable $e) {
            report($e);
            $center = null;
        }
        foreach ($result['lines'] as $i => $line) {
            if (! array_key_exists('cost_center_id', $line)) {
                $result['lines'][$i]['cost_center_id'] = $center;
            }
        }

        return $result;
    }

    protected function costCenterFor(Model $source): ?int
    {
        return CostCenterResolver::for($source);
    }

    private function none(): array
    {
        return ['rule' => '', 'memo' => '', 'needs_review' => false, 'lines' => []];
    }

    /**
     * حساب صالح للترحيل أو «المعلّق». حساب مش موجود / موقوف / مجموعة
     * (مش `is_postable`) مايتكتبش عليه سطر — الأرصدة بتتجمع تحت المجموعة
     * من أولادها، فسطر عليها مباشرة بيتعدّ مرتين في الشجرة.
     */
    private function safeAccount(?GlAccount $acc, bool &$needsReview): int
    {
        if ($acc !== null && $acc->is_postable && $acc->active) {
            return $acc->id;
        }
        $needsReview = true;

        return GlAccount::findKey('suspense')->id;
    }

    /**
     * مفتاح قاعدة → حساب. `rep_cash` بيتحل لحساب المندوب؛ من غير مندوب
     * بيرجع للخزنة الرئيسية والقيد بيتعلّم `needs_review` (المبلغ موجود
     * فعلاً في إيد حد، بس مش عارفين إيد مين).
     * `$rep` بيتجاهل لأي مفتاح غير `rep_cash`.
     *
     * كويري واحدة للحساب — الموديل نفسه بيتفحص (`safeAccount`) بدل ما
     * يتجاب تاني بالـid (كانت كويريتين لكل سطر في إعادة البناء).
     */
    private function keyAccount(?string $key, ?User $rep, bool &$needsReview): int
    {
        if ($key === 'rep_cash') {
            if ($rep !== null) {
                return $this->safeAccount(GlAccount::repCash($rep), $needsReview);
            }
            $needsReview = true;
            $key = 'cash_main';
        }

        $acc = $key !== null && $key !== '' ? GlAccount::where('system_key', $key)->first() : null;

        return $this->safeAccount($acc, $needsReview);
    }

    /**
     * خانة قاعدة → حساب (D1). الخانة المثبّتة (`<side>_account_id`) بتتقدّم
     * على المفتاح وبتعدّي على نفس الحارس (`safeAccount`): حساب موقوف أو
     * مجموعة ⇒ المعلّق + مراجعة. من غير تثبيت ⇒ المفتاح زي ما هو.
     * `$side` = `debit` | `credit` | `tax`.
     */
    public function slotAccount(GlPostingRule $rule, string $side, ?User $rep, bool &$needsReview): int
    {
        $pinned = $rule->{$side.'_account_id'};
        if ($pinned !== null) {
            return $this->safeAccount(GlAccount::find($pinned), $needsReview);
        }

        return $this->keyAccount($rule->{$side.'_key'}, $rep, $needsReview);
    }

    /** مندوب التحصيل من مصدر القيد */
    public function repFor(Transaction $tx): ?User
    {
        return match ($tx->source_type) {
            Visit::class => Visit::find($tx->source_id)?->user,
            Invoice::class => Invoice::find($tx->source_id)?->user,
            PurchaseOrder::class => ($po = PurchaseOrder::find($tx->source_id)) && $po->assigned_to ? User::find($po->assigned_to) : null,
            User::class => User::find($tx->source_id),
            default => null,
        };
    }

    private function transaction(Transaction $tx): array
    {
        $amount = round((float) $tx->debit + (float) $tx->credit, 2);
        if ($tx->kind === 'consignment' || $amount == 0.0) {
            return $this->none();
        }

        $key = match ($tx->kind) {
            'collection' => match (true) {
                $tx->method === null || $tx->method === '' => 'tx.collection.auto',
                $tx->method === Transaction::METHOD_CASH && $tx->source_type !== null => 'tx.collection.rep_cash',
                $tx->method === Transaction::METHOD_CASH => 'tx.collection.office_cash',
                default => 'tx.collection.bank',
            },
            default => 'tx.'.$tx->kind,
        };

        $rule = GlPostingRule::forKey($key);
        if ($rule === null || ! $rule->active) {
            return $this->none();
        }

        $tax = round((float) ($tx->tax ?? 0), 2);
        $needsReview = false;
        // `repFor()` بيعمل كويري على المستند — مانناديهاش غير لما المفتاح
        // يكون `rep_cash` فعلاً
        $resolve = function (string $side) use ($rule, $tx, &$needsReview): int {
            $rep = $rule->{$side.'_account_id'} === null && $rule->{$side.'_key'} === 'rep_cash' ? $this->repFor($tx) : null;

            return $this->slotAccount($rule, $side, $rep, $needsReview);
        };

        $lines = [['slot' => 'dr', 'account_id' => $resolve('debit'), 'debit' => $amount, 'credit' => 0.0]];
        if (($rule->tax_key || $rule->tax_account_id) && $tax > 0) {
            // الضريبة بتتفصل عن الطرف «الإيراد/المرتجع» مش عن العملاء
            if ($tx->kind === 'sale') {
                $lines[] = ['slot' => 'cr', 'account_id' => $resolve('credit'), 'debit' => 0.0, 'credit' => round($amount - $tax, 2)];
                $lines[] = ['slot' => 'tax', 'account_id' => $resolve('tax'), 'debit' => 0.0, 'credit' => $tax];
            } else { // return: المدين هو المرتجعات + الضريبة، الدائن العملاء
                $lines = [
                    ['slot' => 'dr', 'account_id' => $resolve('debit'), 'debit' => round($amount - $tax, 2), 'credit' => 0.0],
                    ['slot' => 'tax', 'account_id' => $resolve('tax'), 'debit' => $tax, 'credit' => 0.0],
                    ['slot' => 'cr', 'account_id' => $resolve('credit'), 'debit' => 0.0, 'credit' => $amount],
                ];
            }
        } else {
            $lines[] = ['slot' => 'cr', 'account_id' => $resolve('credit'), 'debit' => 0.0, 'credit' => $amount];
        }

        // ═══ الاتجاه ═══
        // القاعدة مكتوبة على **الاتجاه الطبيعي** للنوع (فاتورة مدين على
        // العملاء، تحصيل دائن عليهم). لو الصف اتسجّل على الطرف المعاكس
        // (تسوية بمدين، فاتورة معكوسة بدائن) بنقلب المدين والدائن على
        // **كل** السطور — سطر الضريبة كمان — بدل قلب مفتاحين بس: القلب
        // بالمفاتيح كان بيخلّي سطر الضريبة في مكانه ويطلّع قيد ضريبة
        // بالعكس على فاتورة معكوسة، وماكانش شغال غير على opening/transfer.
        $flip = in_array($tx->kind, self::NATURAL_DEBIT, true)
            ? (float) $tx->credit > 0
            : (float) $tx->debit > 0;
        if ($flip) {
            $lines = array_map(function (array $l): array {
                [$l['debit'], $l['credit']] = [$l['credit'], $l['debit']];

                return $l;
            }, $lines);
        }

        return [
            'rule' => $key,
            'memo' => (string) ($tx->memo ?: Transaction::KINDS[$tx->kind] ?? $tx->kind),
            'needs_review' => $needsReview,
            'lines' => $lines,
        ];
    }

    private function settlement(RepSettlement $s): array
    {
        $received = round((float) $s->received, 2);
        $rule = GlPostingRule::forKey('settle.received');
        if ($received <= 0 || ! $rule || ! $rule->active || ! $s->user) {
            return $this->none();
        }

        $needsReview = false;
        // الطرفين من القاعدة — `rep_cash` بيتحل لمندوب التصفية (كان الدائن
        // مكتوب في الكود على حساب المندوب وبيتجاهل القاعدة)
        $lines = [
            ['slot' => 'dr', 'account_id' => $this->slotAccount($rule, 'debit', $s->user, $needsReview), 'debit' => $received, 'credit' => 0.0],
            ['slot' => 'cr', 'account_id' => $this->slotAccount($rule, 'credit', $s->user, $needsReview), 'debit' => 0.0, 'credit' => $received],
        ];

        return [
            'rule' => 'settle.received', 'needs_review' => $needsReview,
            'memo' => 'تصفية '.$s->number.' — '.$s->user->displayName(),
            'lines' => $lines,
        ];
    }

    private function supplier(SupplierTransaction $st): array
    {
        $amount = round((float) $st->debit + (float) $st->credit, 2);
        if ($amount == 0.0) {
            return $this->none();
        }
        $key = 'sup.'.$st->kind;
        // Lane 9 (D1): المخزون الدائم — القاعدة دي بيعملها seed التكاليف بس،
        // ولو موقوفة أو مش موجودة الفاتورة بتفضل مصروف مشتريات زي الأول
        if ($st->kind === 'invoice' && ($stock = GlPostingRule::forKey('sup.invoice.stock')) !== null && $stock->active) {
            $key = 'sup.invoice.stock';
        }
        // مرتجع لمورد بصف في دفتره (`kind = return`): مدين الموردين / دائن
        // المخزون. ⚠️ النهارده مفيش كود بيكتب النوع ده — مرتجع فحص الجودة
        // (Lane 5 D6) مالوش قيد مورد أصلاً — القاعدة جاهزة للي جاي.
        if ($st->kind === 'return' && ($stock = GlPostingRule::forKey('sup.return.stock')) !== null && $stock->active) {
            $key = 'sup.return.stock';
        }
        if ($st->kind === 'payment') {
            $method = $st->source_type === \App\Models\SupplierPayment::class
                ? (\App\Models\SupplierPayment::find($st->source_id)?->method ?? 'cash') : 'cash';
            $key = $method === 'cash' ? 'sup.payment.cash' : 'sup.payment.bank';
        }
        $rule = GlPostingRule::forKey($key);
        if (! $rule || ! $rule->active) {
            return $this->none();
        }
        // في دفتر المورد: credit = علينا له (فاتورة)، debit = دفعنا له. المفاتيح مكتوبة
        // على الاتجاه الطبيعي لكل نوع؛ opening/adjust بيتقلبوا لو الإشارة معكوسة
        // الخانة المثبّتة بتتقلب مع مفتاحها
        $needsReview = false;
        $dr = $this->slotAccount($rule, 'debit', null, $needsReview);
        $cr = $this->slotAccount($rule, 'credit', null, $needsReview);
        if (in_array($st->kind, ['opening', 'adjust'], true) && (float) $st->debit > 0) {
            [$dr, $cr] = [$cr, $dr];
        }

        $lines = [
            ['slot' => 'dr', 'account_id' => $dr, 'debit' => $amount, 'credit' => 0.0],
            ['slot' => 'cr', 'account_id' => $cr, 'debit' => 0.0, 'credit' => $amount],
        ];

        return [
            'rule' => $key, 'needs_review' => $needsReview,
            'memo' => (string) ($st->memo ?: $rule->label),
            'lines' => $lines,
        ];
    }

    private function expense(Expense $x): array
    {
        if ($x->status !== 'posted') {
            return $this->none();
        }
        $rule = GlPostingRule::forKey('expense.'.$x->paid_from);
        if (! $rule || ! $rule->active) {
            return $this->none();
        }
        $needsReview = false;
        $rep = $x->paid_from === 'rep_cash' ? $x->paidFromUser : null;
        // ⚠️ مصروف مكتوب «من نقدية مندوب» ومالوش مندوب: الفلوس طلعت من
        // إيد حد مش معروف — بيروح الخزنة الرئيسية بعلامة مراجعة مش يوقع
        if ($x->paid_from === 'rep_cash' && $rep === null) {
            $needsReview = true;
        }
        // الدائن (L4-D4): **الخزنة المسمّاة صراحةً** (`safe_id`) بحسابها في
        // الشجرة؛ من غير `safe_id` مفتاح القاعدة زي قبل Lane 4 بالظبط (نفس
        // `cash()`: مفيش خزنة مسمّاة = مفاتيح القاعدة). `rep_cash` مش خزنة.
        // ⚠️ `resolvedSafe()` هنا للمركز بس — مش للحساب، وإلا السندات القديمة
        // كانت هتتنقل لحساب `MAADI-MAIN` الخاص لو اتربط بغير `cash_main`.
        $named = $x->paid_from !== 'rep_cash' && $x->safe_id ? $x->safe : null;
        $safe = $x->paid_from === 'rep_cash' ? null : ($named ?? $x->resolvedSafe());
        $creditId = null;
        if ($named !== null) {
            $acc = $named->gl_account_id ? GlAccount::find($named->gl_account_id) : null;
            if ($acc !== null && $acc->is_postable && $acc->active) {
                $creditId = $acc->id;
            } else {
                // خزنة اختارها المستخدم ومالهاش حساب صالح = المراجعة لازم تعرف
                $needsReview = true;
            }
        }
        // مركز التكلفة (L4-D9): السند ← الخزنة ← الشركة — مفيش سطر من غير مركز
        $center = $x->cost_center_id ?? $safe?->cost_center_id ?? \App\Models\CostCenter::company()->id;
        // من غير خزنة مسمّاة: خانة القاعدة (المثبّتة D1 أو المفتاح) — `rep_cash` بيتحل لمندوب السند
        $creditId ??= $this->slotAccount($rule, 'credit', $rep, $needsReview);
        // حساب المصروف نفسه جاي من السند (مش من القاعدة) — بيعدّي على نفس
        // الحارس: حساب موقوف أو مجموعة بيروح المعلّق
        $debitId = $this->safeAccount($x->account_id ? GlAccount::find($x->account_id) : null, $needsReview);
        $amount = round((float) $x->amount, 2);

        return [
            'rule' => 'expense.'.$x->paid_from, 'needs_review' => $needsReview,
            'memo' => $x->number.' — '.($x->note ?: $x->account?->displayName()),
            'lines' => [
                ['slot' => 'dr', 'account_id' => $debitId, 'cost_center_id' => $center, 'debit' => $amount, 'credit' => 0.0],
                ['slot' => 'cr', 'account_id' => $creditId, 'cost_center_id' => $safe?->cost_center_id ?? $center, 'debit' => 0.0, 'credit' => $amount],
            ],
        ];
    }

    private function cash(CashMovement $m): array
    {
        if ($m->status !== 'posted') {
            return $this->none();
        }
        $rule = GlPostingRule::forKey('cash.'.$m->kind);
        if (! $rule || ! $rule->active) {
            return $this->none();
        }
        $needsReview = false;
        // ⚠️ عهدة أو رد عهدة من غير مندوب = الطرف التاني مش معروف —
        // بيروح الخزنة الرئيسية بعلامة مراجعة (الحركة نفسها مش بتتلغي)
        if (in_array($m->kind, ['rep_advance', 'rep_return'], true) && ! $m->user) {
            $needsReview = true;
        }
        $res = function (string $side) use ($rule, $m, &$needsReview): int {
            return $this->slotAccount($rule, $side, $rule->{$side.'_key'} === 'rep_cash' ? $m->user : null, $needsReview);
        };
        // L4-D4: الخزنة المسمّاة على طرف بحسابها بتحل محل مفتاح القاعدة
        // (المدين = الوجهة، الدائن = المصدر). ⚠️ هنا قراءة بس — مافيش
        // `ensureGlAccount()`؛ خزنة حسابها مش صالح بترجع للمفتاح بعلامة مراجعة
        // `$slot` = `debit` | `credit` — الخانة (مش المفتاح) عشان التثبيت (D1) يتطبق
        $side = function (?Safe $safe, string $slot) use ($res, $rule, &$needsReview): int {
            if ($safe !== null && $rule->{$slot.'_key'} !== 'rep_cash') {
                $acc = $safe->gl_account_id ? GlAccount::find($safe->gl_account_id) : null;
                if ($acc !== null && $acc->is_postable && $acc->active) {
                    return $acc->id;
                }
                $needsReview = true;
            }

            return $res($slot);
        };
        $from = $m->from_safe_id ? $m->fromSafe : null;
        $to = $m->to_safe_id ? $m->toSafe : null;
        $amount = round((float) $m->amount, 2);
        $drId = $side($to, 'debit');
        $crId = $side($from, 'credit');
        // نفس الحساب على الطرفين (مثلاً خزنتين على حساب واحد) = قيد بيلغي نفسه
        if ($drId === $crId) {
            $needsReview = true;
        }
        $lines = [
            ['slot' => 'dr', 'account_id' => $drId, 'cost_center_id' => $to?->cost_center_id ?? $m->cost_center_id, 'debit' => $amount, 'credit' => 0.0],
            ['slot' => 'cr', 'account_id' => $crId, 'cost_center_id' => $from?->cost_center_id ?? $m->cost_center_id, 'debit' => 0.0, 'credit' => $amount],
        ];

        return [
            'rule' => 'cash.'.$m->kind, 'needs_review' => $needsReview,
            'memo' => $m->number.' — '.$rule->label,
            'lines' => $lines,
        ];
    }

    /**
     * جرد الخزنة (L4-D5): الفرق **المتخزّن** بس (`counted − expected` وقت
     * الجرد) — مابيتحسبش المتوقع هنا تاني، وإلا إعادة البناء كانت هتشوف
     * رصيد فيه قيد الجرد نفسه وتطلّع فرق صفر. عجز (فرق سالب): مدين عجز
     * وزيادة / دائن الخزنة · زيادة: العكس. السطرين على مركز الخزنة.
     */
    private function count(SafeCount $c): array
    {
        $diff = round((float) $c->difference, 2);
        if ($c->status !== 'posted' || $diff == 0.0) {
            return $this->none();
        }
        $rule = GlPostingRule::forKey('cash.count');
        if (! $rule || ! $rule->active) {
            return $this->none();
        }
        $needsReview = false;
        $safe = $c->safe;
        // نفس منطق `cash()`: حساب الخزنة لو صالح، وإلا مفتاح القاعدة بعلامة مراجعة
        $acc = $safe?->gl_account_id ? GlAccount::find($safe->gl_account_id) : null;
        if ($acc !== null && $acc->is_postable && $acc->active) {
            $safeId = $acc->id;
        } else {
            $needsReview = true;
            $safeId = $this->slotAccount($rule, 'credit', null, $needsReview);
        }
        $overShortId = $this->slotAccount($rule, 'debit', null, $needsReview);
        $amount = abs($diff);
        $center = $safe?->cost_center_id;
        [$drId, $crId] = $diff < 0 ? [$overShortId, $safeId] : [$safeId, $overShortId];

        return [
            'rule' => 'cash.count', 'needs_review' => $needsReview,
            'memo' => $c->number.' — '.($safe?->displayName() ?? ''),
            'lines' => [
                ['slot' => 'dr', 'account_id' => $drId, 'cost_center_id' => $center, 'debit' => $amount, 'credit' => 0.0],
                ['slot' => 'cr', 'account_id' => $crId, 'cost_center_id' => $center, 'debit' => 0.0, 'credit' => $amount],
            ],
        ];
    }

    /**
     * قسط الإهلاك (L4-D11): مدين مصروف الإهلاك / دائن مجمع الإهلاك من
     * مفاتيح القاعدة، والسطرين على مركز الأصل (من غيره: الشركة).
     */
    private function depreciation(FixedAssetDepreciation $d): array
    {
        $amount = round((float) $d->amount, 2);
        $rule = GlPostingRule::forKey('asset.depreciation');
        if ($amount <= 0 || ! $rule || ! $rule->active) {
            return $this->none();
        }
        $asset = $d->asset;
        // أصل اتمسح من تحت القسط = المراجعة لازم تعرف
        $needsReview = $asset === null;
        $center = $asset?->cost_center_id ?? CostCenter::company()->id;
        $drId = $this->slotAccount($rule, 'debit', null, $needsReview);
        $crId = $this->slotAccount($rule, 'credit', null, $needsReview);

        return [
            'rule' => 'asset.depreciation', 'needs_review' => $needsReview,
            'memo' => ($asset?->code ?? '#'.$d->fixed_asset_id).' — '.$d->period_key,
            'lines' => [
                ['slot' => 'dr', 'account_id' => $drId, 'cost_center_id' => $center, 'debit' => $amount, 'credit' => 0.0],
                ['slot' => 'cr', 'account_id' => $crId, 'cost_center_id' => $center, 'debit' => 0.0, 'credit' => $amount],
            ],
        ];
    }
}
