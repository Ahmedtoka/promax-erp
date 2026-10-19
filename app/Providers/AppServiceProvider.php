<?php

namespace App\Providers;

use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // ═══ توقيت مصر مفروض من الكود (حادثة التايم زون ٢٣/٨/٢٠٢٦) ═══
        //
        // ⚠️ **مش رفاهية ولا تكرار.** اللايف فضل شغال UTC رغم إن
        // APP_TIMEZONE=Africa/Cairo في الـ.env والكاش اتمسح — لأن
        // على السيرفر فيه config/app.php بقيمة 'UTC' مكتوبة صريحة
        // بتغلب الـ.env (النسخة المحلية مالهاش الملف ده أصلاً).
        // النتيجة كانت: كل ساعة معروضة غلط بـ3 ساعات، وأوامر تصليح
        // بنت فوق بعضها. السطرين دول بيقفلوا الباب ده للأبد مهما
        // كانت ملفات الكونفيج على أي سيرفر.
        date_default_timezone_set('Africa/Cairo');
        config(['app.timezone' => 'Africa/Cairo']);

        // ═══ سجلات قيد الافتتاح (D6) — الخط ٤ يربط الأصول والخط ٧ السلف ═══
        // `bindIf`: ربط الخط التاني بيكسب أياً كان ترتيب التسجيل
        $this->app->bindIf(\App\Contracts\Gl\FixedAssetRegister::class, \App\Services\Gl\Opening\NullFixedAssetRegister::class);
        $this->app->bindIf(\App\Contracts\Gl\AdvancesRegister::class, \App\Services\Gl\Opening\NullAdvancesRegister::class);
        // سينجلتون عشان `register()` من أي خط يفضل موجود طول الطلب
        $this->app->singletonIf(\App\Services\Gl\OpeningBuilder::class);
    }

    /**
     * Bootstrap any application services.
     */
    /**
     * الموديلز اللي حركتها بتتسجّل في سجل الحركة (2026-08-07).
     *
     * ⚠️ **مش كل الموديلز.** الحركات المالية والمخزنية (Transaction،
     * InvoiceItem، CustodyItem، TrackEvent) بتتكتب بالآلاف يومياً
     * وليها سجلاتها الخاصة أصلاً — تسجيلها هنا بيغرق الجدول ويبطّئ
     * كل فاتورة. اللي هنا: الكيانات اللي بني آدم بيعدّلها بإيده.
     *
     * @var list<class-string>
     */
    private const AUDITED = [
        \App\Models\Client::class,
        \App\Models\Product::class,
        \App\Models\User::class,
        \App\Models\Contract::class,
        \App\Models\Zone::class,
        \App\Models\ClientGroup::class,
        \App\Models\Channel::class,
        \App\Models\PurchaseOrder::class,
        \App\Models\Invoice::class,
        \App\Models\Custody::class,
        \App\Models\Warehouse::class,
        \App\Models\Setting::class,
        // (٢٢/٩) مركز النشاط — مستندات بتتعمل وتتعدّل بالإيد وكانت بتعدّي من غير أثر
        \App\Models\ClientReturn::class,
        \App\Models\StockTransfer::class,
        \App\Models\SupplierOrder::class,
        \App\Models\Supplier::class,
        \App\Models\GoodsReceipt::class,
        \App\Models\PriceList::class,
        \App\Models\Target::class,
        \App\Models\RepTarget::class,
        \App\Models\Task::class,
        \App\Models\Expense::class,
        \App\Models\Quotation::class,
        \App\Models\Vehicle::class,
        \App\Models\Branch::class,
        \App\Models\ProductFamily::class,
        \App\Models\RepSettlement::class,
        \App\Models\DayClose::class,
        \App\Models\StockCount::class,
        \App\Models\PickOrder::class,
    ];

    public function boot(): void
    {
        // ⚠️ التحذير بتاع المايجريشن المعلّقة مكاش دقيقة. من غير المسح
        // ده، اليوزر بيشغّل `migrate` وبيفضل شايف التحذير دقيقة كاملة
        // ويفتكر إن الأمر مانفعش ويعيده.
        Event::listen(MigrationsEnded::class, fn () => Cache::forget('pending-migrations'));

        // ═══ سجل الحركة (2026-08-07) ═══
        // ⚠️ التسكين محمي بـclass_exists — لو موديل اتشال أو اتسمى،
        // السيستم كله كان هيقع على boot بدل ما يشتغل من غير تسجيله.
        foreach (self::AUDITED as $model) {
            if (class_exists($model)) {
                $model::observe(\App\Observers\ActivityObserver::class);
            }
        }

        // الدخول والخروج — من أحداث لارافيل نفسها، فبيمسك كل المسارات
        Event::listen(\Illuminate\Auth\Events\Login::class, function ($e) {
            \App\Models\ActivityLog::record('login', [
                'user_id' => $e->user->id,
                'user_name' => $e->user->name,
                'role' => $e->user->role,
                'title' => $e->user->code,
            ]);
        });

        Event::listen(\Illuminate\Auth\Events\Logout::class, function ($e) {
            if ($e->user !== null) {
                \App\Models\ActivityLog::record('logout', [
                    'user_id' => $e->user->id,
                    'user_name' => $e->user->name,
                    'role' => $e->user->role,
                ]);
            }
        });

        // ⚠️ حارس الشهر المقفول (١٩/١٠، D7) **قبل** أوبزرفرات الدفتر —
        // بيرمي (مش بيبلع) عشان مستند الويب جوه شهر مقفول يترفض قبل أي كتابة
        \App\Support\PeriodGuard::registerDefaults();
        \App\Models\Gl\GlPeriod::saved(fn () => \App\Support\PeriodGuard::flush());
        \App\Models\Gl\GlPeriod::deleted(fn () => \App\Support\PeriodGuard::flush());

        // دفتر الأستاذ العام (١١/٩/٢٠٢٦) — كل صف في transactions بيترحّل تلقائياً
        \App\Models\Transaction::observe(\App\Observers\Gl\TransactionObserver::class);
        // تصفية المندوب وقيود الموردين (١١/٩/٢٠٢٦) — نفس مبدأ transactions
        \App\Models\RepSettlement::observe(\App\Observers\Gl\RepSettlementObserver::class);
        \App\Models\SupplierTransaction::observe(\App\Observers\Gl\SupplierTransactionObserver::class);
        // سندات المصروف وحركة النقدية (١١/٩/٢٠٢٦) — الإلغاء بيشيل القيد
        \App\Models\Expense::observe(\App\Observers\Gl\ExpenseObserver::class);
        \App\Models\CashMovement::observe(\App\Observers\Gl\CashMovementObserver::class);
        \App\Models\SafeCount::observe(\App\Observers\Gl\SafeCountObserver::class);
        // قسط الإهلاك الشهري (L4-D11) — الحذف بيشيل القيد
        \App\Models\FixedAssetDepreciation::observe(\App\Observers\Gl\FixedAssetDepreciationObserver::class);
        // مستخدم ميداني جديد = حساب نقدية جديد (لو الشجرة متولدة)
        \App\Models\User::created(function (\App\Models\User $u) {
            try {
                if ($u->active
                    && in_array($u->role, \App\Models\User::FIELD_WORK_ROLES, true)
                    && \App\Models\Gl\GlAccount::where('system_key', 'rep_cash')->exists()) {
                    \App\Models\Gl\GlAccount::repCash($u);
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('gl.observer failed', ['user' => $u->id, 'event' => 'user.created', 'error' => $e->getMessage()]);
            }
        });
    }
}
