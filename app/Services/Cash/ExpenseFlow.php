<?php

namespace App\Services\Cash;

use App\Exceptions\Rejected;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\FixedAsset;
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
            // الراوت عليه `role:admin` بس الخدمة نفسها بتتنده من أماكن تانية
            if (! $this->canApprove($by)) {
                throw new Rejected(__('cash.exp_no_approve_right'));
            }
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
            // الراوت عليه `role:admin` بس الخدمة نفسها بتتنده من أماكن تانية
            if (! $this->canApprove($by)) {
                throw new Rejected(__('cash.exp_no_approve_right'));
            }
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
     * L4-D10: سند من بند نوعه «أصل» بقى مدفوع = صف `fixed_assets` في
     * نفس الترانزاكشن (التكلفة = المبلغ، العمر والبداية من السند وإلا
     * من البند). الأصل نفسه مابيترحّلش — تكلفته في قيد السند (مدين حساب
     * الأصل). مرة واحدة لكل سند (`expense_id` unique).
     */
    protected function afterPaid(Expense $x): void
    {
        $cat = $x->category_id ? ExpenseCategory::find($x->category_id) : null;
        if ($cat?->type !== 'asset' || FixedAsset::where('expense_id', $x->id)->exists()) {
            return;
        }
        FixedAsset::create([
            'code' => FixedAsset::nextNumber(),
            'name' => $x->asset_name ?: ($x->note ?: $cat->name),
            'name_en' => $x->asset_name || $x->note ? null : $cat->name_en,
            'category_id' => $cat->id,
            'expense_id' => $x->id,
            'cost' => round((float) $x->amount, 2),
            'salvage' => 0,
            'life_months' => $x->asset_life_months ?: ($cat->life_months ?: 12),
            'start_date' => ($x->asset_start_date ?? $x->date)->toDateString(),
            // نفس سلسلة مركز سطر السند (السند → الخزنة) — الإهلاك على نفس مركز التكلفة
            'cost_center_id' => $x->cost_center_id ?? $x->resolvedSafe()?->cost_center_id,
            'asset_account_id' => $x->account_id,
            'created_by' => $x->paid_by ?? $x->created_by,
        ]);
    }

    /**
     * إلغاء السند. سند أصل مدفوع اتهلك = مرفوض (الإهلاك مرحّل على
     * تكلفة الأصل — الصح استبعاد الأصل مش مسح السند). من غير إهلاك:
     * الأصل بيتمسح مع الإلغاء، وقيد السند بيتشال من `ExpenseObserver`.
     */
    public function void(Expense $x, User $by): void
    {
        DB::transaction(function () use ($x, $by) {
            $row = $this->lock($x);
            if ($row->status === 'void') {
                return;
            }
            // نفس حارس الكنترولر جوه القفل — أي نداء تاني للخدمة محروس
            if ($row->status === 'posted' && GlPeriod::isClosed($row->date)) {
                throw new Rejected(__('gl.void_closed_hint'));
            }
            $asset = FixedAsset::where('expense_id', $row->id)->lockForUpdate()->first();
            if ($asset !== null) {
                if ($asset->depreciationRows()->exists()) {
                    throw new Rejected(__('cash.asset_void_depreciated', ['code' => $asset->code]));
                }
                $asset->delete();
            }
            $row->update(['status' => 'void', 'voided_at' => now(), 'voided_by' => $by->id]);
            $x->setRawAttributes($row->getAttributes(), true);
        });
    }

    private function lock(Expense $x): Expense
    {
        return Expense::whereKey($x->getKey())->lockForUpdate()->firstOrFail();
    }
}
