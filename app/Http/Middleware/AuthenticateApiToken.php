<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * توكن الأبلكيشن: Authorization: Bearer <token>
 */
class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return response()->json(['message' => __('api.token_missing')], 401);
        }

        $record = ApiToken::with('user')->where('token', $token)->first();

        if (! $record || ! $record->user || ! $record->user->active) {
            return response()->json(['message' => __('api.token_invalid')], 401);
        }

        $record->forceFill(['last_used_at' => now()])->saveQuietly();

        Auth::setUser($record->user);
        $request->setUserResolver(fn () => $record->user);

        return $next($request);
    }
}
