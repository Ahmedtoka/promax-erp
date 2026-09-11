<?php

namespace App\Observers\Gl;

use App\Models\RepSettlement;
use App\Services\Gl\Ledger;
use Illuminate\Support\Facades\Log;

/**
 * تصفية المندوب = قيد آلي (نقدية مع المندوب → الخزنة الرئيسية بمقدار
 * المستلم). نفس عزل `TransactionObserver`: الدفتر طبقة مشتقة، أي استثناء
 * هنا لازم يتبلع ويتسجل في اللوج ومايوقفش حفظ التصفية نفسها.
 */
class RepSettlementObserver
{
    public function __construct(private Ledger $ledger)
    {
    }

    public function created(RepSettlement $s): void
    {
        try {
            $this->ledger->post($s);
        } catch (\Throwable $e) {
            Log::error('gl.observer failed', ['settlement' => $s->id, 'event' => 'created', 'error' => $e->getMessage()]);
        }
    }

    public function deleted(RepSettlement $s): void
    {
        try {
            $this->ledger->unpost($s);
        } catch (\Throwable $e) {
            Log::error('gl.observer failed', ['settlement' => $s->id, 'event' => 'deleted', 'error' => $e->getMessage()]);
        }
    }
}
