<?php

use App\Http\Controllers\IclockController;
use Illuminate\Support\Facades\Route;

/*
 * ═══ مكنة البصمة ZKTeco — ADMS / iclock (٧/١١/٢٠٢٦) ═══
 *
 * ⚠️ الملف ده متسجل في `bootstrap/app.php` (`then:`) **من غير** جروب `web` —
 * مفيش CSRF ولا سيشن ولا لوجين. المكنة بتتعرف بالسيريال بس (`Adms::device`).
 * المسار `/iclock/...` ثابت في الـfirmware — مايتغيرش.
 * بعض الـfirmware بتطلب `cdata.aspx` / `getrequest.aspx` — نفس الكنترولر.
 */
Route::prefix('iclock')->middleware('throttle:600,1')->group(function () {
    Route::match(['GET', 'POST'], '/cdata', [IclockController::class, 'cdata'])->name('iclock.cdata');
    Route::match(['GET', 'POST'], '/cdata.aspx', [IclockController::class, 'cdata']);
    Route::match(['GET', 'POST'], '/getrequest', [IclockController::class, 'getrequest'])->name('iclock.getrequest');
    Route::match(['GET', 'POST'], '/getrequest.aspx', [IclockController::class, 'getrequest']);
    Route::post('/devicecmd', [IclockController::class, 'devicecmd'])->name('iclock.devicecmd');
    Route::post('/devicecmd.aspx', [IclockController::class, 'devicecmd']);
    Route::get('/ping', [IclockController::class, 'ping'])->name('iclock.ping');
});
