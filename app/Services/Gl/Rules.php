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
 */
class Rules
{
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
        $resolve = function (?string $k) use ($tx, &$needsReview): ?int {
            if ($k === null) {
                return null;
            }
            if ($k === 'rep_cash') {
                $rep = $this->repFor($tx);
                if ($rep === null) {
                    $needsReview = true;

                    return GlAccount::findKey('cash_main')->id;
                }

                return GlAccount::repCash($rep)->id;
            }

            return GlAccount::findKey($k)->id;
        };

        // الاتجاه بالإشارة: debit>0 → المدين هو debit_key، وإلا القيد معكوس
        // (opening/transfer ممكن يبقوا دائن)
        $isDebit = (float) $tx->debit > 0;
        $drKey = $isDebit ? $rule->debit_key : $rule->credit_key;
        $crKey = $isDebit ? $rule->credit_key : $rule->debit_key;
        // ⚠️ القاعدة اللي مكتوبة أصلاً كقيد دائن (collection/return/...) الإشارة
        // بتاعتها credit>0 وهي الطبيعي — فمانقلبش إلا لو النوع ثنائي الاتجاه
        if (! in_array($tx->kind, ['opening', 'transfer'], true)) {
            $drKey = $rule->debit_key;
            $crKey = $rule->credit_key;
        }

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

        return [
            'rule' => 'settle.received', 'needs_review' => false,
            'memo' => 'تصفية '.$s->number.' — '.$s->user->displayName(),
            'lines' => [
                ['slot' => 'dr', 'account_id' => GlAccount::findKey($rule->debit_key)->id, 'debit' => $received, 'credit' => 0.0],
                ['slot' => 'cr', 'account_id' => GlAccount::repCash($s->user)->id, 'debit' => 0.0, 'credit' => $received],
            ],
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

        return [
            'rule' => $key, 'needs_review' => false,
            'memo' => (string) ($st->memo ?: $rule->label),
            'lines' => [
                ['slot' => 'dr', 'account_id' => GlAccount::findKey($dr)->id, 'debit' => $amount, 'credit' => 0.0],
                ['slot' => 'cr', 'account_id' => GlAccount::findKey($cr)->id, 'debit' => 0.0, 'credit' => $amount],
            ],
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
        $creditAcc = $x->paid_from === 'rep_cash' && $x->paidFromUser
            ? GlAccount::repCash($x->paidFromUser)
            : GlAccount::findKey($rule->credit_key === 'rep_cash' ? 'cash_main' : $rule->credit_key);
        $amount = round((float) $x->amount, 2);

        return [
            'rule' => 'expense.'.$x->paid_from, 'needs_review' => false,
            'memo' => $x->number.' — '.($x->note ?: $x->account?->displayName()),
            'lines' => [
                ['slot' => 'dr', 'account_id' => $x->account_id, 'debit' => $amount, 'credit' => 0.0],
                ['slot' => 'cr', 'account_id' => $creditAcc->id, 'debit' => 0.0, 'credit' => $amount],
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
        $res = fn (string $k) => $k === 'rep_cash' && $m->user ? GlAccount::repCash($m->user)->id : GlAccount::findKey($k === 'rep_cash' ? 'cash_main' : $k)->id;
        $amount = round((float) $m->amount, 2);

        return [
            'rule' => 'cash.'.$m->kind, 'needs_review' => false,
            'memo' => $m->number.' — '.$rule->label,
            'lines' => [
                ['slot' => 'dr', 'account_id' => $res($rule->debit_key), 'debit' => $amount, 'credit' => 0.0],
                ['slot' => 'cr', 'account_id' => $res($rule->credit_key), 'debit' => 0.0, 'credit' => $amount],
            ],
        ];
    }
}
