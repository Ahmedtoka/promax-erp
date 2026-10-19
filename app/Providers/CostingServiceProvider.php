<?php

namespace App\Providers;

use App\Models\Transaction;
use App\Observers\Costing\CostTransactionObserver;
use Illuminate\Support\ServiceProvider;

/**
 * التكاليف (الخط ٩) — الأوبزرفرز وأي تسجيل تاني بتاع الخط بيتعمل هنا
 * (خطوات المعالج في المهمة ٩) عشان `AppServiceProvider` مايكبرش.
 */
class CostingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // بيع/مرتجع ← قيد تكلفة بعد الـcommit (D12)
        Transaction::observe(CostTransactionObserver::class);
    }
}
