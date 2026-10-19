<?php

namespace App\Observers\Gl;

use App\Models\Expense;
use App\Services\Gl\Ledger;
use Illuminate\Support\Facades\Log;

/**
 * سند المصروف = قيد آلي (مدين حساب المصروف / دائن الخزنة أو البنك أو
 * نقدية المندوب). الإلغاء (`status=void`) بيشيل القيد بدل ما يعمل عكسي —
 * `Rules::expense()` بترجّع سطور فاضية لأي سند مش `posted`، فالـ`repost`
 * كان هيمسح القيد ومايكتبش بديل؛ `unpost` أوضح وبنفس النتيجة.
 *
 * ⚠️ نفس عقيدة `TransactionObserver`: دفتر الأستاذ طبقة مشتقة — أي
 * استثناء هنا بيتبلع ويتسجل في اللوج وميوقفش حفظ السند نفسه. السند
 * الموجود من غير قيد بيتصلح بـrebuild؛ السند اللي مااتحفظش خسارة حقيقية.
 */
class ExpenseObserver
{
    public function __construct(private Ledger $ledger)
    {
    }

    public function created(Expense $x): void
    {
        try {
            $this->ledger->post($x);
        } catch (\Throwable $e) {
            Log::error('gl.observer failed', ['expense' => $x->id, 'event' => 'created', 'error' => $e->getMessage()]);
        }
    }

    public function updated(Expense $x): void
    {
        try {
            if ($x->wasChanged('status') && $x->status === 'void') {
                $this->ledger->unpost($x);
            } elseif ($x->wasChanged(['amount', 'date', 'account_id', 'paid_from', 'paid_from_user_id', 'status', 'safe_id', 'cost_center_id'])) {
                // L4-D7: pending → approved بيعدّي هنا و`Rules` بترجّع فاضي
                // (مفيش قيد يتشال ولا يتكتب)؛ approved → posted هو اللي بيرحّل.
                // ⚠️ العلاقة ممكن تكون محمّلة بالمندوب القديم — و`Rules`
                // بتقرا `paidFromUser` عشان تختار حساب النقدية، فالقيد
                // الجديد كان هيتكتب على حساب المندوب اللي اتشال
                $x->unsetRelation('paidFromUser');
                $x->unsetRelation('safe');
                $this->ledger->repost($x);
            }
        } catch (\Throwable $e) {
            Log::error('gl.observer failed', ['expense' => $x->id, 'event' => 'updated', 'error' => $e->getMessage()]);
        }
    }

    public function deleted(Expense $x): void
    {
        try {
            $this->ledger->unpost($x);
        } catch (\Throwable $e) {
            Log::error('gl.observer failed', ['expense' => $x->id, 'event' => 'deleted', 'error' => $e->getMessage()]);
        }
    }
}
