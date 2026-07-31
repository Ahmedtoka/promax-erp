<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * صلاحيات الـ API: ->middleware('api.role:admin,manager')
 */
class EnsureApiRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json(['message' => __('api.not_signed_in')], 401);
        }

        if ($roles && ! in_array($user->role, $roles, true) && ! $user->isAdmin()) {
            return response()->json(['message' => __('api.forbidden')], 403);
        }

        return $next($request);
    }
}
