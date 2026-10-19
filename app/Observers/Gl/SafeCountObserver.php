<?php

namespace App\Observers\Gl;

use App\Models\SafeCount;
use App\Services\Gl\Ledger;
use Illuminate\Support\Facades\Log;

/**
 * جرد الخزنة (L4-D5) = قيد عجز/زيادة بالفرق المتخزّن. الإلغاء بيشيل
 * القيد — زي `CashMovementObserver`. الجرد مابيتعدّلش بعد الحفظ (إلغاء
 * وجرد جديد)، فمفيش repost هنا.
 *
 * ⚠️ نفس العقيدة: الاستثناء بيتبلع ويتسجل، وحفظ الجرد مايتوقفش على الشجرة.
 */
class SafeCountObserver
{
    public function __construct(private Ledger $ledger)
    {
    }

    public function created(SafeCount $c): void
    {
        try {
            $this->ledger->post($c);
        } catch (\Throwable $e) {
            Log::error('gl.observer failed', ['safe_count' => $c->id, 'event' => 'created', 'error' => $e->getMessage()]);
        }
    }

    public function updated(SafeCount $c): void
    {
        try {
            if ($c->wasChanged('status') && $c->status === 'void') {
                $this->ledger->unpost($c);
            }
        } catch (\Throwable $e) {
            Log::error('gl.observer failed', ['safe_count' => $c->id, 'event' => 'updated', 'error' => $e->getMessage()]);
        }
    }

    public function deleted(SafeCount $c): void
    {
        try {
            $this->ledger->unpost($c);
        } catch (\Throwable $e) {
            Log::error('gl.observer failed', ['safe_count' => $c->id, 'event' => 'deleted', 'error' => $e->getMessage()]);
        }
    }
}
