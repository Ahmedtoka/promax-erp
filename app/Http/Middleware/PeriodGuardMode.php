<?php

namespace App\Http\Middleware;

use App\Support\PeriodGuard;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * مود حارس الشهر المقفول للطلب ده (قرار D7): الويب `reject`، الـAPI
 * `flag`. ⚠️ المود بيرجع للقديم في `finally` — من غيره طلب ويب في
 * تيست كان هيسيب `reject` شغّال على أي `create` مباشر بعده.
 */
class PeriodGuardMode
{
    public function handle(Request $request, Closure $next, string $mode = PeriodGuard::MODE_REJECT): Response
    {
        return PeriodGuard::using($mode, fn () => $next($request));
    }
}
