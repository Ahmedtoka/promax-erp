<?php

namespace App\Providers;

use App\Models\Transaction;
use App\Observers\Costing\CostTransactionObserver;
use App\Services\Gl\GoLive\GoLiveWizard;
use App\Services\Gl\GoLive\Steps\CostingStep;
use App\Services\Gl\GoLive\Steps\RunStep;
use App\Services\Gl\RebuildDiff;
use Illuminate\Support\ServiceProvider;

/**
 * التكاليف (الخط ٩) — الأوبزرفرز وأي تسجيل تاني بتاع الخط بيتعمل هنا
 * (خطوات المعالج ٧ و٩ وفحص `cost_cogs`) عشان `AppServiceProvider` مايكبرش.
 */
class CostingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // بيع/مرتجع ← قيد تكلفة بعد الـcommit (D12)
        Transaction::observe(CostTransactionObserver::class);

        // معالج التشغيل (المهمة ٩): ٧ إعدادات التكاليف · ٩ التشغيل الفعلي (D13)
        GoLiveWizard::register(new CostingStep);
        GoLiveWizard::register(new RunStep);

        // فحص المعاينة/التشغيل: حساب تكلفة المبيعات = طبقة التكلفة
        RebuildDiff::addCheck('cost_cogs', fn () => CostingStep::cogsCheck());
    }
}
