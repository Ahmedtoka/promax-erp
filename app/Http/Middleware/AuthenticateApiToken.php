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

        if (! $record || ! $record->user) {
            return response()->json(['message' => __('api.token_invalid')], 401);
        }

        // ⚠️ **الموقوف غير المنتهية جلسته** (2026-08-08). الاتنين كانوا
        // بيرجعوا نفس الرسالة، فالموظف اللي الإدارة وقفته كان بيشوف
        // «الجلسة انتهت» ويفضل يحاول يسجّل دخول ويتصل بالدعم يقول
        // «الأبلكيشن بايظ». الكود ده بيخلّي الأبلكيشن يوري شاشة
        // بتقول الحقيقة وتوجّهه للإدارة.
        //
        // ⚠️ و**403 مش 401**: الـ401 معناها «اتصرّف وسجّل دخول تاني»،
        // وهو ده اللي الأبلكيشن كان بيعمله — بيمسح التوكن ويرجّعه
        // للوجين فيدخل ويتوقف تاني في لفة لا نهائية.
        if (! $record->user->active) {
            return response()->json([
                'message' => __('api.account_blocked'),
                'code' => 'account_blocked',
            ], 403);
        }

        $record->forceFill(['last_used_at' => now()])->saveQuietly();

        Auth::setUser($record->user);
        $request->setUserResolver(fn () => $record->user);

        return $next($request);
    }
}
