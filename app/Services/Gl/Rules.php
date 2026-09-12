<?php

namespace App\Services\Gl;

use App\Models\CashMovement;
use App\Models\Expense;
use App\Models\Gl\GlAccount;
use App\Models\Gl\GlPostingRule;
use App\Models\Invoice;
use App\Models\PurchaseOrder;
use App\Models\RepSettlement;
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

    /** @return array{rule:string, memo:string, needs_review:bool, lines:list<array{slot:string,account_id:int,debit:float,credit:float}>} */
    public function linesFor(Model $source): array
    {
        return match (true) {
            $source instanceof Transaction => $this->transaction($source),
            $source instanceof RepSettlement => $this->settlement($source),
            $source instanceof SupplierTransaction => $this->supplier($source),
            $source instanceof Expense => $this->expense($source),
            $source instanceof CashMovement => $this->cash($source),
            default => $this->none(),
        };
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
    private function safeAccount(?int $accountId, bool &$needsReview): int
    {
        $acc = $accountId !== null ? GlAccount::find($accountId) : null;
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
     */
    private function keyAccount(?string $key, ?User $rep, bool &$needsReview): int
    {
        if ($key === 'rep_cash') {
            if ($rep !== null) {
                return $this->safeAccount(GlAccount::repCash($rep)->id, $needsReview);
            }
            $needsReview = true;
            $key = 'cash_main';
        }

        $acc = $key !== null && $key !== '' ? GlAccount::where('system_key', $key)->first() : null;

        return $this->safeAccount($acc?->id, $needsReview);
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
        $resolve = function (?string $k) use ($tx, &$needsReview): int {
            return $this->keyAccount($k, $k === 'rep_cash' ? $this->repFor($tx) : null, $needsReview);
        };

        $drKey = $rule->debit_key;
        $crKey = $rule->credit_key;

        $lines = [['slot' => 'dr', 'account_id' => $resolve($drKey), 'debit' => $amount, 'credit' => 0.0]];
        if ($rule->tax_key && $tax > 0) {
            // الضريبة بتتفصل عن الطرف «الإيراد/المرتجع» مش عن العملاء
            if ($tx->kind === 'sale') {
                $lines[] = ['slot' => 'cr', 'account_id' => $resolve($crKey), 'debit' => 0.0, 'credit' => round($amount - $tax, 2)];
                $lines[] = ['slot' => 'tax', 'account_id' => $resolve($rule->tax_key), 'debit' => 0.0, 'credit' => $tax];
            } else { // return: المدين هو المرتجعات + الضريبة، الدائن العملاء
                $lines = [
                    ['slot' => 'dr', 'account_id' => $resolve($drKey), 'debit' => round($amount - $tax, 2), 'credit' => 0.0],
                    ['slot' => 'tax', 'account_id' => $resolve($rule->tax_key), 'debit' => $tax, 'credit' => 0.0],
                    ['slot' => 'cr', 'account_id' => $resolve($crKey), 'debit' => 0.0, 'credit' => $amount],
                ];
            }
        } else {
            $lines[] = ['slot' => 'cr', 'account_id' => $resolve($crKey), 'debit' => 0.0, 'credit' => $amount];
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
        $lines = [
            ['slot' => 'dr', 'account_id' => $this->keyAccount($rule->debit_key, null, $needsReview), 'debit' => $received, 'credit' => 0.0],
            ['slot' => 'cr', 'account_id' => $this->keyAccount('rep_cash', $s->user, $needsReview), 'debit' => 0.0, 'credit' => $received],
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
        $dr = $rule->debit_key;
        $cr = $rule->credit_key;
        if (in_array($st->kind, ['opening', 'adjust'], true) && (float) $st->debit > 0) {
            [$dr, $cr] = [$cr, $dr];
        }

        $needsReview = false;
        $lines = [
            ['slot' => 'dr', 'account_id' => $this->keyAccount($dr, null, $needsReview), 'debit' => $amount, 'credit' => 0.0],
            ['slot' => 'cr', 'account_id' => $this->keyAccount($cr, null, $needsReview), 'debit' => 0.0, 'credit' => $amount],
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
        $creditId = $rep !== null
            ? $this->keyAccount('rep_cash', $rep, $needsReview)
            : $this->keyAccount($rule->credit_key, null, $needsReview);
        // حساب المصروف نفسه جاي من السند (مش من القاعدة) — بيعدّي على نفس
        // الحارس: حساب موقوف أو مجموعة بيروح المعلّق
        $debitId = $this->safeAccount($x->account_id, $needsReview);
        $amount = round((float) $x->amount, 2);

        return [
            'rule' => 'expense.'.$x->paid_from, 'needs_review' => $needsReview,
            'memo' => $x->number.' — '.($x->note ?: $x->account?->displayName()),
            'lines' => [
                ['slot' => 'dr', 'account_id' => $debitId, 'debit' => $amount, 'credit' => 0.0],
                ['slot' => 'cr', 'account_id' => $creditId, 'debit' => 0.0, 'credit' => $amount],
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
        $res = function (?string $k) use ($m, &$needsReview): int {
            return $this->keyAccount($k, $k === 'rep_cash' ? $m->user : null, $needsReview);
        };
        $amount = round((float) $m->amount, 2);
        $lines = [
            ['slot' => 'dr', 'account_id' => $res($rule->debit_key), 'debit' => $amount, 'credit' => 0.0],
            ['slot' => 'cr', 'account_id' => $res($rule->credit_key), 'debit' => 0.0, 'credit' => $amount],
        ];

        return [
            'rule' => 'cash.'.$m->kind, 'needs_review' => $needsReview,
            'memo' => $m->number.' — '.$rule->label,
            'lines' => $lines,
        ];
    }
}
