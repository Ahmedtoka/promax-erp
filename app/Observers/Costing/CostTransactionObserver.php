<?php

namespace App\Observers\Costing;

use App\Models\Transaction;
use App\Services\Costing\CostSync;
use App\Services\Gl\Ledger;
use Illuminate\Support\Facades\Log;

/**
 * مزامنة لايف لطبقة التكلفة (الخط ٩ — D12). بعد الـcommit عشان بنود
 * الفاتورة/الأمر/المرتجع تكون اتكتبت كلها قبل ما نحسب التكلفة.
 *
 * ⚠️ طبقة مشتقة — أي فشل بيتسجّل في اللوج ومابيترميش أبداً (البيع نفسه
 * اتسجل خلاص)، و`promax:costing-sync` أو إعادة البناء بيصلّحوه.
 */
class CostTransactionObserver
{
    public bool $afterCommit = true;

    public function __construct(private Ledger $ledger)
    {
    }

    public function created(Transaction $tx): void
    {
        $this->sync($tx, 'created');
    }

    public function updated(Transaction $tx): void
    {
        if ($tx->wasChanged(['debit', 'credit', 'tax', 'kind', 'date', 'source_type', 'source_id', 'client_id'])) {
            $this->sync($tx, 'updated');
        }
    }

    public function deleted(Transaction $tx): void
    {
        try {
            CostSync::forgetTransaction($tx);
        } catch (\Throwable $e) {
            Log::error('costing.observer failed', ['tx' => $tx->id, 'event' => 'deleted', 'error' => $e->getMessage()]);
        }
    }

    private function sync(Transaction $tx, string $event): void
    {
        try {
            $entry = CostSync::forTransaction($tx);
            if ($entry !== null) {
                $this->ledger->repost($entry);
            }
        } catch (\Throwable $e) {
            Log::error('costing.observer failed', ['tx' => $tx->id, 'event' => $event, 'error' => $e->getMessage()]);
        }
    }
}
