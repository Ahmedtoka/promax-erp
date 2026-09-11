<?php

namespace App\Observers\Gl;

use App\Models\Transaction;
use App\Services\Gl\Ledger;
use Illuminate\Support\Facades\Log;

/**
 * كل صف في `transactions` = قيد يومية. الإنشاء بيرحّل، والمسح بيشيل القيد.
 * ⚠️ التعديلات اللي بتمشي بـ`whereKey()->update()` (تعديل تاريخ الفاتورة،
 * الترقيم، التحويل لعميل تاني) مابتمرش هنا — أدوات الفاتورة بتنادي
 * `Ledger::repost` صراحةً (Task 5).
 *
 * ⚠️ دفتر الأستاذ طبقة مشتقة — أي استثناء هنا لازم يتبلع ويتسجل في اللوج
 * وميوقفش حفظ صف `transactions` نفسه (قرار المراجعة). لو فشل الترحيل،
 * القيد بيفضل ناقص لحد ما يتعمل rebuild، بس حركة المديونية الحقيقية اتسجلت.
 */
class TransactionObserver
{
    public function __construct(private Ledger $ledger)
    {
    }

    public function created(Transaction $tx): void
    {
        try {
            $this->ledger->post($tx);
        } catch (\Throwable $e) {
            Log::error('gl.observer failed', ['tx' => $tx->id, 'event' => 'created', 'error' => $e->getMessage()]);
        }
    }

    public function updated(Transaction $tx): void
    {
        try {
            if ($tx->wasChanged(['debit', 'credit', 'tax', 'kind', 'method', 'date', 'source_type', 'source_id', 'client_id'])) {
                $this->ledger->repost($tx);
            }
        } catch (\Throwable $e) {
            Log::error('gl.observer failed', ['tx' => $tx->id, 'event' => 'updated', 'error' => $e->getMessage()]);
        }
    }

    public function deleted(Transaction $tx): void
    {
        try {
            $this->ledger->unpost($tx);
        } catch (\Throwable $e) {
            Log::error('gl.observer failed', ['tx' => $tx->id, 'event' => 'deleted', 'error' => $e->getMessage()]);
        }
    }
}
