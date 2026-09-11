<?php

namespace App\Observers\Gl;

use App\Models\SupplierTransaction;
use App\Services\Gl\Ledger;
use Illuminate\Support\Facades\Log;

/**
 * كل صف في `supplier_transactions` (من `Supplier::post()`) = قيد يومية على
 * الموردين. نفس عزل `TransactionObserver`: الدفتر طبقة مشتقة، أي استثناء
 * هنا لازم يتبلع ويتسجل في اللوج ومايوقفش قيد دفتر المورد نفسه.
 */
class SupplierTransactionObserver
{
    public function __construct(private Ledger $ledger)
    {
    }

    public function created(SupplierTransaction $t): void
    {
        try {
            $this->ledger->post($t);
        } catch (\Throwable $e) {
            Log::error('gl.observer failed', ['supplier_tx' => $t->id, 'event' => 'created', 'error' => $e->getMessage()]);
        }
    }

    public function deleted(SupplierTransaction $t): void
    {
        try {
            $this->ledger->unpost($t);
        } catch (\Throwable $e) {
            Log::error('gl.observer failed', ['supplier_tx' => $t->id, 'event' => 'deleted', 'error' => $e->getMessage()]);
        }
    }
}
