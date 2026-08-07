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
        //
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
    }
}
