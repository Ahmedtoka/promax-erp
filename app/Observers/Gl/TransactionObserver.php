<?php

namespace App\Observers\Gl;

use App\Models\Transaction;
use App\Services\Gl\Ledger;

/**
 * كل صف في `transactions` = قيد يومية. الإنشاء بيرحّل، والمسح بيشيل القيد.
 * ⚠️ التعديلات اللي بتمشي بـ`whereKey()->update()` (تعديل تاريخ الفاتورة،
 * الترقيم، التحويل لعميل تاني) مابتمرش هنا — أدوات الفاتورة بتنادي
 * `Ledger::repost` صراحةً (Task 5).
 */
class TransactionObserver
{
    public function __construct(private Ledger $ledger)
    {
    }

    public function created(Transaction $tx): void
    {
        $this->ledger->post($tx);
    }

    public function updated(Transaction $tx): void
    {
        if ($tx->wasChanged(['debit', 'credit', 'tax', 'kind', 'method', 'date', 'source_type', 'source_id', 'client_id'])) {
            $this->ledger->repost($tx);
        }
    }

    public function deleted(Transaction $tx): void
    {
        $this->ledger->unpost($tx);
    }
}
