<?php

namespace App\Observers\Gl;

use App\Models\CashMovement;
use App\Services\Gl\Ledger;
use Illuminate\Support\Facades\Log;

/**
 * حركة النقدية = قيد آلي بين حسابين نقديين (خزنة · بنك · نقدية مندوب).
 * الإلغاء بيشيل القيد — زي `ExpenseObserver` بالظبط.
 *
 * ⚠️ نفس عقيدة `TransactionObserver`: الاستثناء بيتبلع ويتسجل، وحفظ
 * السند نفسه مايتوقفش على الشجرة.
 */
class CashMovementObserver
{
    public function __construct(private Ledger $ledger)
    {
    }

    public function created(CashMovement $m): void
    {
        try {
            $this->ledger->post($m);
        } catch (\Throwable $e) {
            Log::error('gl.observer failed', ['cash_movement' => $m->id, 'event' => 'created', 'error' => $e->getMessage()]);
        }
    }

    public function updated(CashMovement $m): void
    {
        try {
            if ($m->wasChanged('status') && $m->status === 'void') {
                $this->ledger->unpost($m);
            } elseif ($m->wasChanged(['amount', 'date', 'kind', 'user_id', 'status'])) {
                // ⚠️ نفس فخ `ExpenseObserver` — العلاقة المحمّلة بالمندوب
                // القديم بتودّي القيد الجديد لحساب غلط
                $m->unsetRelation('user');
                $this->ledger->repost($m);
            }
        } catch (\Throwable $e) {
            Log::error('gl.observer failed', ['cash_movement' => $m->id, 'event' => 'updated', 'error' => $e->getMessage()]);
        }
    }

    public function deleted(CashMovement $m): void
    {
        try {
            $this->ledger->unpost($m);
        } catch (\Throwable $e) {
            Log::error('gl.observer failed', ['cash_movement' => $m->id, 'event' => 'deleted', 'error' => $e->getMessage()]);
        }
    }
}
