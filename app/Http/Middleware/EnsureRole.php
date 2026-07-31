<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * الاستخدام: ->middleware('role:admin,manager')
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = Auth::user();

        if ($user === null) {
            return redirect()->route('login');
        }

        if ($roles && ! in_array($user->role, $roles, true) && ! $user->isAdmin()) {
            abort(403, __('common.forbidden'));
        }

        return $next($request);
    }
}
