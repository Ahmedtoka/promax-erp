<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| المهام المجدولة
|--------------------------------------------------------------------------
|
| ⚠️ **محتاجة كرون واحد على السيرفر** عشان تشتغل أصلاً:
|
|     * * * * * cd /home/master/applications/xfdzmdtdaq/public_html \
|               && php artisan schedule:run >> /dev/null 2>&1
|
| من غيره الأوامر دي مش هتشتغل ولا مرة، ومفيش أي خطأ هيظهر — أخطر
| نوع سكوت. لو الحضور بيفضل مفتوح كل يوم، ابدأ بالتأكد من الكرون.
*/

// ═══ قفل الشيفتات المنسية ═══
// 12:05 ص = بعد منتصف الليل بخمس دقايق، بيقفل **يوم إمبارح**.
// الخمس دقايق هامش عشان أي بانش اتبعت الساعة 11:59:5x يوصل الأول.
Schedule::command('promax:attendance-close')
    ->dailyAt('00:05')
    // ⚠️ نسختين من الأمر في نفس اللحظة = بانشين انصراف لنفس اليوم
    ->withoutOverlapping();

// تنضيف سجل حركة اليوزرات — بيكبر بسرعة (فتح الصفحات كمان)
Schedule::command('promax:prune-activity')->weeklyOn(5, '03:00');
