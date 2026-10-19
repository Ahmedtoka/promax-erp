<?php

namespace App\Services\Cash;

use App\Exceptions\Rejected;
use App\Models\Expense;
use App\Models\Gl\GlPeriod;
use App\Models\Safe;
use App\Models\User;
use App\Support\Access;
use Illuminate\Support\Facades\DB;

/**
 * دورة سند المصروف (L4-D7): `pending → approved → posted` (posted = مدفوع)،
 * أو `rejected`. `Rules::expense()` بترحّل `posted` بس، فالمعلّق والمعتمد
 * مابيلمسوش الخزنة.
 *
 * ⚠️ **مكان واحد للدفع.** الإنشاء المدفوع على طول (صاحب صلاحية الاعتماد)
 * و`pay()` الاتنين بيعدّوا على `afterPaid()` — Task 7 (الأصول، L4-D10)
 * بيتعلّق هناك بس.
 */
class ExpenseFlow
{
    public function canApprove(?User $u): bool
    {
        return Access::action($u, 'act.gl.expense_approve');
    }

    /**
     * إنشاء السند: صاحب صلاحية الاعتماد = معتمد ومدفوع على طول، وأي حد
     * تاني = معلّق لحد ما يتعتمد.
     *
     * @param  array<string, mixed>  $attrs
     */
    public function create(array $attrs, User $by): Expense
    {
        return DB::transaction(function () use ($attrs, $by) {
            $now = now();
            $paid = $this->canApprove($by);
            $x = Expense::create(array_merge($attrs, [
                'number' => Expense::nextNumber(),
                'created_by' => $by->id,
                'status' => $paid ? 'posted' : 'pending',
            ], $paid ? [
                'approved_by' => $by->id, 'approved_at' => $now,
                'paid_by' => $by->id, 'paid_at' => $now,
            ] : []));

            if ($paid) {
                $this->afterPaid($x);
            }

            return $x;
        });
    }

    public function approve(Expense $x, User $by, ?string $note = null): void
    {
        DB::transaction(function () use ($x, $by, $note) {
            $row = $this->lock($x);
            if ($row->status !== 'pending') {
                throw new Rejected(__('cash.exp_not_pending'));
            }
            $row->update([
                'status' => 'approved', 'approved_by' => $by->id, 'approved_at' => now(),
                'decision_note' => $note !== null && $note !== '' ? $note : $row->decision_note,
            ]);
            $x->setRawAttributes($row->getAttributes(), true);
        });
    }

    public function reject(Expense $x, User $by, ?string $note = null): void
    {
        DB::transaction(function () use ($x, $by, $note) {
            $row = $this->lock($x);
            if (! in_array($row->status, ['pending', 'approved'], true)) {
                throw new Rejected(__('cash.exp_cannot_reject'));
            }
            $row->update([
                'status' => 'rejected', 'rejected_by' => $by->id, 'rejected_at' => now(),
                'decision_note' => $note,
            ]);
            $x->setRawAttributes($row->getAttributes(), true);
        });
    }

    /**
     * الدفع: معتمد بس ← مرحّل. تغيير الخزنة اختياري (خزنة شغّالة)، و
     * `paid_from` القديم بيتحسب من نوعها؛ نقدية المندوب بتفضل زي ما هي.
     */
    public function pay(Expense $x, User $by, ?int $safeId = null): void
    {
        DB::transaction(function () use ($x, $by, $safeId) {
            $row = $this->lock($x);
            if ($row->status !== 'approved') {
                throw new Rejected(__('cash.exp_not_approved'));
            }
            if (GlPeriod::isClosed($row->date)) {
                throw new Rejected(__('gl.period_closed', ['period' => GlPeriod::keyFor($row->date)]));
            }

            $changes = ['status' => 'posted', 'paid_by' => $by->id, 'paid_at' => now()];
            if ($safeId !== null) {
                if ($row->paid_from === 'rep_cash') {
                    throw new Rejected(__('cash.exp_rep_cash_no_safe'));
                }
                $safe = Safe::whereKey($safeId)->where('active', true)->first()
                    ?? throw new Rejected(__('cash.exp_safe_inactive'));
                $changes['safe_id'] = $safe->id;
                $changes['paid_from'] = self::paidFromFor($safe);
            }

            $row->update($changes);
            $x->setRawAttributes($row->getAttributes(), true);
            $x->unsetRelation('safe');

            $this->afterPaid($row);
        });
    }

    /** قيمة `paid_from` القديمة اللي بتقابل نوع الخزنة */
    public static function paidFromFor(Safe $safe): string
    {
        return $safe->kind === 'bank' ? 'bank' : 'cash_main';
    }

    /**
     * ⚠️ **نقطة التعليق لـTask 7 (L4-D10):** بتتنادى جوه نفس الترانزاكشن
     * بعد ما السند بقى `posted` — هنا هيتعمل صف `fixed_assets` لمصروف
     * من نوع أصل. لسه مابتعملش حاجة.
     */
    protected function afterPaid(Expense $x): void
    {
    }

    private function lock(Expense $x): Expense
    {
        return Expense::whereKey($x->getKey())->lockForUpdate()->firstOrFail();
    }
}
