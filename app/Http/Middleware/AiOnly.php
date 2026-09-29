<?php

namespace App\Http\Middleware;

use App\Models\ActivityLog;
use App\Support\AiGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * راوتات مساعد الذكاء الاصطناعي — للأكواد المسموحة بس (`AiGate`).
 *
 * ⚠️ إخفاء الفقاعة مش حماية: الراوت نفسه بيرفض، وأي محاولة بتتسجل في
 * مركز النشاط باسم صاحب الحساب.
 */
class AiOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! AiGate::allows($request->user())) {
            ActivityLog::record('action', ['title' => 'ai_blocked', 'status' => 403]);

            return response()->json(['message' => __('agent.not_allowed')], 403);
        }

        return $next($request);
    }
}
