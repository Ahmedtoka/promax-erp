<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * ═══════════════════════════════════════════════════════════════
 * GET /api/zones — المناطق والعملاء لوحدهم + المزامنة التفاضلية (١٧/١٠/٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * البوت ستراب حوالي 1 MB وأغلبه المناطق، والأبلكيشن بيسحبه كل 45–120 ثانية.
 *
 * • **من غير `updated_since`**: `{"zones": …}` — **نفس مفتاح `zones` في
 *   البوت ستراب بالحرف** (نفس `zonesPayload`)، فأي نسخة أبلكيشن تقدر تقراه.
 * • **بـ`updated_since=<ISO>`**: العملاء اللي اتغيّروا بس جوه مناطقهم،
 *   و`zone_ids`/`client_ids` = كل الظاهر دلوقتي (عشان الحذف)، و`full`.
 *   الأبلكيشن يحفظ `server_time` (أو هيدر `X-Server-Time`) ويبعته المرة الجاية.
 *
 * ⚠️ **رد كامل (`full: true`) بدل التفاضلي** لو:
 *   - الوقت قبل أول النهارده — حالة زيارة النهارده (`visit_status`)
 *     بتتصفّر نص الليل، والعقد بينتهي بالتاريخ، ومن غير ما أي صف يتغيّر.
 *   - إعداد اتغيّر من ساعتها — الضريبة (`tax_rate`/`tax_on`) جاية من `settings`.
 */
class ZonesApiController extends Controller
{
    /** نفس الرولز اللي بيوصلها `zones` في بوت ستراباتها (الميدان/البروموتر/المدير) */
    private const ROLES = ['sales_agent', 'promoter', 'manager'];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        // الوقت **قبل** القراية: أي تعديل أثناء الريكوست يرجع في اللي بعده
        $now = now();
        $raw = $request->query('updated_since');
        $allowed = in_array($user->role, self::ROLES, true);

        if ($raw === null || $raw === '') {
            return response()
                ->json(['zones' => $allowed ? FieldApiController::zonesPayload($user) : []])
                ->header('X-Server-Time', $now->toIso8601String());
        }

        try {
            $since = Carbon::parse((string) $raw);
        } catch (\Throwable) {
            return response()->json(['message' => __('validation.date', ['attribute' => 'updated_since'])], 422);
        }

        $local = $since->copy()->setTimezone(config('app.timezone'));
        $full = $local->lt(today()) || $local->gt($now)
            || Setting::whereBetween('updated_at', [$local->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s')])->exists();

        if (! $allowed) {
            $body = ['zones' => [], 'zone_ids' => [], 'client_ids' => []];
        } elseif ($full) {
            $zones = FieldApiController::zonesPayload($user);
            $body = [
                'zones' => $zones,
                'zone_ids' => collect($zones)->pluck('id')->sort()->values()->all(),
                'client_ids' => collect($zones)->flatMap(fn ($z) => collect($z['clients'])->pluck('id'))
                    ->unique()->sort()->values()->all(),
            ];
        } else {
            $body = FieldApiController::zonesPayload($user, $since);
        }

        return response()->json([
            'full' => $full,
            'since' => $since->toIso8601String(),
            'server_time' => $now->toIso8601String(),
        ] + $body)->header('X-Server-Time', $now->toIso8601String());
    }
}
