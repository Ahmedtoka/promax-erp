<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TrackEvent;
use App\Services\Attendance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * الحضور والانصراف في الأبلكيشن (2026-08-08).
 *
 * ⚠️ **الراوتس دي بره حارس الحضور عن قصد** — لو الحارس اتحط
 * عليها، الموظف اللي مش حاضر مش هيقدر يسجّل حضور. مصيدة كلاسيكية.
 */
class AttendanceApiController extends Controller
{
    /** GET /api/attendance — حالة النهارده وسجله */
    public function show(Request $request): JsonResponse
    {
        return response()->json(['attendance' => Attendance::payload($request->user())]);
    }

    /** POST /api/attendance/punch — حضور / بريك / رجعت / انصراف */
    public function punch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:in,break,back,out'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $user = $request->user();

        [$error, $day] = Attendance::punch(
            $user,
            $data['type'],
            isset($data['lat']) ? (float) $data['lat'] : null,
            isset($data['lng']) ? (float) $data['lng'] : null,
        );

        if ($error !== null) {
            return response()->json(['error' => $error], 422);
        }

        // ⚠️ **الحضور بيدخل التتبع كمان** — المدير في غرفة التحكم
        // لازم يشوف «بدأ شغل 8:12» في نفس الفيد مع باقي الحركة، مش
        // يفتح شاشة تانية عشان يعرف مين نزل.
        TrackEvent::log(
            $user,
            match ($data['type']) {
                'in' => 'shift_in',
                'break' => 'shift_break',
                'back' => 'shift_back',
                default => 'shift_out',
            },
            __('hr.punch_'.$data['type']),
            __('hr.worked_so_far', ['t' => \App\Models\AttendanceDay::hhmm($day->worked_minutes)]),
            isset($data['lat']) ? (float) $data['lat'] : null,
            isset($data['lng']) ? (float) $data['lng'] : null,
        );

        return response()->json(['attendance' => Attendance::payload($user)]);
    }
}
