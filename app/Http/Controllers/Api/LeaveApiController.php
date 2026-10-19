<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\Rejected;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\LeaveController;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Services\Hr\Leave;
use App\Support\Idempotency;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * الإجازات في الأبلكيشن (Lane 7 — المهمة ٣، عقد Lane 8).
 *
 * ⚠️ بره حارس `attendance` — الإجازة مش محتاجة بانش.
 * ⚠️ من غير ملف موظف: 403 `{message}` على الأرصدة والطلبات (الأبلكيشن
 * بيخبّي الكارت)، والموافقات `{requests: []}`.
 */
class LeaveApiController extends Controller
{
    public function balances(Request $request)
    {
        $mine = Employee::forUser($request->user());

        if ($mine === null) {
            return $this->noEmployee();
        }

        $year = (int) now()->year;

        return response()->json(['balances' => Leave::balances($mine, $year), 'year' => $year]);
    }

    /** `?mine=1` = طلباتي (ومن غيرها نفس الحاجة لحد دلوقتي) */
    public function index(Request $request)
    {
        $mine = Employee::forUser($request->user());

        if ($mine === null) {
            return $this->noEmployee();
        }

        $rows = LeaveController::mineQuery($mine)
            ->orderByDesc('from')->orderByDesc('id')->limit(100)->get()
            ->each(fn ($r) => $r->setRelation('employee', $mine));

        return response()->json(['requests' => $rows->map->apiRow()->values()]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $mine = Employee::forUser($user);

        if ($mine === null) {
            return $this->noEmployee();
        }

        $data = $request->validate([
            'type' => ['required', Rule::in(LeaveRequest::TYPES)],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:300'],
            'idem_key' => Idempotency::RULES,
        ]);

        $key = $data['idem_key'] ?? null;

        // إعادة إرسال بنفس المفتاح = نفس الطلب
        if (($old = Idempotency::find($user, Idempotency::LEAVE_REQUEST, $key, LeaveRequest::class)) !== null) {
            return response()->json(['request' => $old->apiRow()]);
        }

        try {
            $r = Leave::request($mine, $user, $data['type'],
                Carbon::parse($data['from']), Carbon::parse($data['to']), $data['note'] ?? null, $key);
        } catch (Rejected $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (QueryException $e) {
            // ريكوست تاني بنفس المفتاح سبق — ترانزاكشننا رجعت كلها
            if (! Idempotency::isDuplicate($e)) {
                throw $e;
            }

            $old = Idempotency::find($user, Idempotency::LEAVE_REQUEST, $key, LeaveRequest::class);

            return $old === null
                ? response()->json(['message' => __('hrm.leave_retry')], 409)
                : response()->json(['request' => $old->apiRow()]);
        }

        return response()->json(['request' => $r->apiRow()], 201);
    }

    /** الطلبات المعلّقة لفريقي (الأدمن والموارد: الكل) */
    public function approvals(Request $request)
    {
        $rows = LeaveController::approvalsFor($request->user())
            ->with('employee:id,code,name,name_en')
            ->orderBy('from')->orderBy('id')->limit(200)->get();

        return response()->json(['requests' => $rows->map->apiRow()->values()]);
    }

    public function decide(Request $request, LeaveRequest $leaveRequest)
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'note' => ['nullable', 'string', 'max:300'],
            'idem_key' => Idempotency::RULES, // بيتتجاهل — القرار أصلاً idempotent
        ]);

        $user = $request->user();
        $employee = $leaveRequest->employee;

        // ⚠️ الحارس على السيرفر — مش مديره = 403، وطلبه هو = 403 برضه
        if ($employee === null || ! $employee->isManagedBy($user)) {
            return response()->json(['message' => __('hrm.leave_not_your_team')], 403);
        }

        if ((int) $employee->user_id === (int) $user->id) {
            return response()->json(['message' => __('hrm.leave_own_request')], 403);
        }

        try {
            $r = Leave::decide($leaveRequest, $user, $data['decision'], $data['note'] ?? null);
        } catch (Rejected $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['request' => $r->apiRow()]);
    }

    private function noEmployee()
    {
        return response()->json(['message' => __('hrm.no_employee_record')], 403);
    }
}
