<?php

namespace App\Http\Middleware;

use App\Models\ActivityLog;
use App\Support\WorkTimeGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * راوتات «وقت الشغل الفعلي» — جاد بس (`WorkTimeGate`).
 *
 * ⚠️ أي أدمن تاني بياخد 403 حتى بالـURL المباشر، والمحاولة بتتسجل
 * في مركز النشاط باسم صاحب الحساب.
 */
class WorkTimeOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! WorkTimeGate::allows($request->user())) {
            ActivityLog::record('action', ['title' => 'worktime_blocked', 'status' => 403]);

            abort(403);
        }

        return $next($request);
    }
}
