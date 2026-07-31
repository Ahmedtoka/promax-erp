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
    public function boot(): void
    {
        // ⚠️ التحذير بتاع المايجريشن المعلّقة مكاش دقيقة. من غير المسح
        // ده، اليوزر بيشغّل `migrate` وبيفضل شايف التحذير دقيقة كاملة
        // ويفتكر إن الأمر مانفعش ويعيده.
        Event::listen(MigrationsEnded::class, fn () => Cache::forget('pending-migrations'));
    }
}
