<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ═══════════════════════════════════════════════════════════════
 * API توكن الجهاز — إشعارات فاير بيز (2026-08-07)
 * ═══════════════════════════════════════════════════════════════
 *
 * - `store`: الأبلكيشن بيسجّل توكن الجهاز بعد الدخول ومع كل فتحة —
 *   فاير بيز بيغيّر التوكن من نفسه (إعادة تنصيب، مسح داتا، ترقية)،
 *   فالتسجيل بيتكرر مش مرة واحدة.
 * - `destroy`: بيشيل التوكن عند تسجيل الخروج.
 *
 * ⚠️ **المسح عند الخروج مش رفاهية.** الموظف اللي خرج من الأبلكيشن
 * لازم يبطّل ياخد إشعارات شغل — وأهم من كده، لو التليفون اتسلّم
 * لحد تاني، التوكن اللي فاضل مسجّل بيوصّل إشعارات موظف لموظف تاني.
 * `DeviceToken::remember` بتنقل التوكن لليوزر الجديد لما يسجّل دخول،
 * بس الخروج من غير تسجيل دخول بعده مالوش حل غير المسح ده.
 */
class DeviceTokenApiController extends Controller
{
    /** تسجيل/تجديد توكن الجهاز لليوزر الحالي */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'platform' => ['nullable', 'in:android,ios'],
            'app_version' => ['nullable', 'string', 'max:30'],
        ]);

        DeviceToken::remember(
            $request->user(),
            $data['token'],
            $data['platform'] ?? null,
            $data['app_version'] ?? null,
        );

        return response()->json(['ok' => true]);
    }

    /** مسح توكن الجهاز — بيتنده عند تسجيل الخروج */
    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:255'],
        ]);

        // ⚠️ المسح بالتوكن لوحده مش بـ(التوكن + اليوزر): لو الجهاز
        // كان مسجّل لموظف تاني قبل كده، السطر القديم لازم يروح برضه
        // وإلا هيفضل واصله إشعارات جهاز مابقاش معاه.
        DeviceToken::where('token', $data['token'])->delete();

        return response()->json(['ok' => true]);
    }
}
