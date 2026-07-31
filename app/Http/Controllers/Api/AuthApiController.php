<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthApiController extends Controller
{
    /** POST /api/login  { login, password } */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string'],   // إيميل أو كود
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['login'])
            ->orWhere('code', $data['login'])
            ->first();

        if (! $user || ! Hash::check($data['password'], $user->password) || ! $user->active) {
            return response()->json(['message' => __('api.bad_credentials')], 401);
        }

        // الأدمن والمدير بيدخلوا الأبلكيشن كمان (شاشة متابعة وموافقات)
        if (! in_array($user->role, ['sales_agent', 'driver', 'promoter', 'manager', 'admin'], true)) {
            return response()->json(['message' => __('api.no_app_access')], 403);
        }

        $token = $user->issueToken('mobile');

        return response()->json([
            'token' => $token->token,
            'user' => $this->userPayload($user),
        ]);
    }

    /** GET /api/me */
    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->userPayload($request->user())]);
    }

    /**
     * POST /api/locale — تبديل لغة اليوزر.
     *
     * ⚠️ اللغة بتتخزن على اليوزر مش على الجهاز، عشان تتبعه على أي
     * جهاز وعشان الإشعارات اللي السيرفر بيرندرها توصله بنفس اللغة.
     */
    public function setLocale(Request $request): JsonResponse
    {
        $data = $request->validate([
            'locale' => ['required', 'in:ar,en'],
        ]);

        $request->user()->update(['locale' => $data['locale']]);

        return response()->json(['user' => $this->userPayload($request->user()->fresh())]);
    }

    /** POST /api/logout */
    public function logout(Request $request): JsonResponse
    {
        $token = $request->bearerToken();
        $request->user()->tokens()->where('token', $token)->delete();

        return response()->json(['message' => __('api.signed_out')]);
    }

    private function userPayload(User $u): array
    {
        return [
            'id' => $u->id,
            'name' => $u->displayName(),
            'code' => $u->code,
            'role' => $u->role,
            'role_label' => $u->roleLabel(),
            'zone' => $u->zone?->displayName(),
            'zone_id' => $u->zone?->id,
            'channel' => $u->channel?->displayName(),
            // ⚠️ الأبلكيشن بيتبع اللغة دي، والإشعارات بتترندر بيها
            // عند السيرفر — لازم يبقوا مصدر واحد.
            'locale' => $u->locale ?: 'ar',
        ];
    }
}
