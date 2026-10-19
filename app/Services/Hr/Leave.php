<?php

namespace App\Services\Hr;

use App\Exceptions\Rejected;
use App\Models\AppNotification;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Support\Idempotency;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * الإجازات (Lane 7 — المهمة ٣) — الكاتب الوحيد لـ`leave_requests` و`used`
 * ═══════════════════════════════════════════════════════════════
 *
 *   request → pending ─┬→ approved (المدير المباشر / الأدمن / الموارد — بيخصم الرصيد)
 *                      ├→ rejected
 *                      └→ cancelled (صاحب الطلب، وهو لسه pending)
 *
 * ⚠️ الطلب بيتفحص على الرصيد وقت التقديم، **والاعتماد بيعيد الفحص** جوه
 * ترانزاكشن بـ`lockForUpdate` على صف الرصيد — طلبين معلّقين ممكن يعدّوا
 * التقديم، والاعتماد التاني بيترفض لو الرصيد خلص.
 */
final class Leave
{
    /** أيام الطلب = الأيام التقويمية في الفترة ناقص الإجازة الأسبوعية */
    public static function days(Carbon $from, Carbon $to): float
    {
        $weekend = self::weekendDays();
        $n = 0;

        for ($d = $from->copy()->startOfDay(); $d->lte($to); $d->addDay()) {
            if (! in_array($d->dayOfWeek, $weekend, true)) {
                $n++;
            }
        }

        return (float) $n;
    }

    /** @return list<int> */
    public static function weekendDays(): array
    {
        return collect(explode(',', HrSettings::get('hr_weekend_days')))
            ->map(fn ($v) => trim($v))
            ->filter(fn ($v) => $v !== '' && ctype_digit($v) && (int) $v <= 6)
            ->map(fn ($v) => (int) $v)
            ->unique()->values()->all();
    }

    /** الرصيد الافتراضي للنوع من الإعدادات */
    public static function defaultEntitled(string $type): float
    {
        return (float) HrSettings::get('hr_leave_'.$type);
    }

    /** ⚠️ الإجازة بدون مرتب من غير فحص رصيد طول ما إعدادها 0 */
    public static function checked(string $type): bool
    {
        return $type !== 'unpaid' || self::defaultEntitled('unpaid') > 0;
    }

    /**
     * @return list<array{type: string, label: string, entitled: float, used: float, remaining: float}>
     */
    public static function balances(Employee $e, int $year): array
    {
        $rows = LeaveBalance::query()->where('employee_id', $e->id)->where('year', $year)->get()->keyBy('type');

        return array_map(function (string $type) use ($rows) {
            $row = $rows->get($type);
            $entitled = $row !== null ? (float) $row->entitled : self::defaultEntitled($type);
            $used = $row !== null ? (float) $row->used : 0.0;

            return [
                'type' => $type,
                'label' => __('hrm.leave_type_'.$type),
                'entitled' => $entitled,
                'used' => $used,
                'remaining' => self::checked($type) ? max(0.0, round($entitled - $used, 1)) : 0.0,
            ];
        }, LeaveRequest::TYPES);
    }

    /** المتبقي للنوع ده في السنة دي (من غير قفل — للتقديم بس) */
    private static function remaining(Employee $e, string $type, int $year): float
    {
        return (float) collect(self::balances($e, $year))->firstWhere('type', $type)['remaining'];
    }

    public static function request(
        Employee $e,
        User $by,
        string $type,
        Carbon $from,
        Carbon $to,
        ?string $note,
        ?string $idemKey = null,
    ): LeaveRequest {
        if (! in_array($type, LeaveRequest::TYPES, true)) {
            throw new Rejected(__('hrm.leave_bad_type'));
        }

        $from = $from->copy()->startOfDay();
        $to = $to->copy()->startOfDay();

        if ($to->lt($from)) {
            throw new Rejected(__('hrm.leave_bad_range'));
        }

        // ⚠️ سقف المدة قبل أي لوب على الأيام
        if ((int) $from->diffInDays($to, true) + 1 > 366) {
            throw new Rejected(__('hrm.leave_too_long'));
        }

        $days = self::days($from, $to);

        if ($days <= 0) {
            throw new Rejected(__('hrm.leave_zero_days'));
        }

        $r = DB::transaction(function () use ($e, $by, $type, $from, $to, $note, $idemKey, $days) {
            // قفل ملف الموظف — طلبين متزامنين لنفس الموظف مايعدّوش فحص التداخل سوا
            Employee::query()->whereKey($e->id)->lockForUpdate()->first();

            $overlap = LeaveRequest::query()
                ->where('employee_id', $e->id)
                ->whereIn('status', ['pending', 'approved'])
                ->whereDate('from', '<=', $to->toDateString())
                ->whereDate('to', '>=', $from->toDateString())
                ->exists();

            if ($overlap) {
                throw new Rejected(__('hrm.leave_overlap'));
            }

            if (self::checked($type) && $days > self::remaining($e, $type, $from->year)) {
                throw new Rejected(__('hrm.leave_no_balance', [
                    'days' => self::fmt($days),
                    'left' => self::fmt(self::remaining($e, $type, $from->year)),
                ]));
            }

            $r = LeaveRequest::create([
                'employee_id' => $e->id,
                'type' => $type,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'days' => $days,
                'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 300) : null,
                'status' => 'pending',
                'requested_by' => $by->id,
            ]);

            Idempotency::remember($by, Idempotency::LEAVE_REQUEST, $idemKey, $r);

            return $r;
        });

        $r->setRelation('employee', $e);

        // المدير المباشر لو ليه لوجين — ⚠️ من غير لينك: مفيش وجهة `leave` في
        // الأبلكيشن لسه، ولينك مايتفتحش أسوأ من مفيش (promax-field-team §9)
        $boss = $e->manager?->user;
        if ($boss !== null && $boss->id !== $by->id) {
            $name = $e->displayName();
            AppNotification::send(
                $boss,
                fn () => __('hrm.leave_notify_new_title'),
                fn () => __('hrm.leave_notify_new_body', [
                    'name' => $name,
                    'type' => __('hrm.leave_type_'.$type),
                    'days' => self::fmt($days),
                    'from' => $from->toDateString(),
                ]),
            );
        }

        return $r;
    }

    public static function decide(LeaveRequest $r, User $by, string $decision, ?string $note): LeaveRequest
    {
        if (! in_array($decision, ['approved', 'rejected'], true)) {
            throw new Rejected(__('hrm.leave_bad_decision'));
        }

        $employee = $r->employee;

        if ($employee === null || ! $employee->isManagedBy($by)) {
            throw new Rejected(__('hrm.leave_not_your_team'));
        }

        if ((int) $employee->user_id === (int) $by->id) {
            throw new Rejected(__('hrm.leave_own_request'));
        }

        $just = false;

        $done = DB::transaction(function () use ($r, $by, $decision, $note, $employee, &$just) {
            $locked = LeaveRequest::query()->whereKey($r->id)->lockForUpdate()->firstOrFail();

            // ⚠️ إعادة نفس القرار (الأبلكيشن بعت تاني) = نفس الصف؛ قرار مختلف = رفض
            if ($locked->status !== 'pending') {
                if ($locked->status === $decision) {
                    return $locked;
                }

                throw new Rejected(__('hrm.leave_already_decided', ['status' => $locked->statusLabel()]));
            }

            if ($decision === 'approved') {
                $year = $locked->from->year;
                self::ensureBalance($employee, $year, $locked->type);

                $bal = LeaveBalance::query()
                    ->where('employee_id', $employee->id)->where('year', $year)->where('type', $locked->type)
                    ->lockForUpdate()->firstOrFail();

                if (self::checked($locked->type) && (float) $locked->days > $bal->remaining()) {
                    throw new Rejected(__('hrm.leave_no_balance', [
                        'days' => self::fmt((float) $locked->days),
                        'left' => self::fmt($bal->remaining()),
                    ]));
                }

                $bal->used = round((float) $bal->used + (float) $locked->days, 1);
                $bal->save();
            }

            $locked->fill([
                'status' => $decision,
                'decided_by' => $by->id,
                'decided_at' => now(),
                'decision_note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 300) : null,
            ])->save();

            $just = true;

            return $locked;
        });

        $done->setRelation('employee', $employee);

        if ($just) {
            $who = $employee->user;

            if ($who !== null) {
                $approved = $decision === 'approved';
                $type = $done->type;
                $from = $done->from->toDateString();
                AppNotification::send(
                    $who,
                    fn () => __($approved ? 'hrm.leave_notify_approved' : 'hrm.leave_notify_rejected'),
                    fn () => __('hrm.leave_notify_decided_body', ['type' => __('hrm.leave_type_'.$type), 'from' => $from]),
                    good: $approved,
                );
            }
        }

        return $done;
    }

    /** صاحب الطلب يلغيه وهو لسه معلّق */
    public static function cancel(LeaveRequest $r, User $by): LeaveRequest
    {
        $mine = (int) $r->requested_by === (int) $by->id
            || ($r->employee !== null && (int) $r->employee->user_id === (int) $by->id);

        if (! $mine) {
            throw new Rejected(__('hrm.leave_not_yours'));
        }

        return DB::transaction(function () use ($r) {
            $locked = LeaveRequest::query()->whereKey($r->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'pending') {
                throw new Rejected(__('hrm.leave_already_decided', ['status' => $locked->statusLabel()]));
            }

            $locked->status = 'cancelled';
            $locked->save();

            return $locked;
        });
    }

    /**
     * رصيد افتتاحي لكل موظف نشط لكل نوع من الإعدادات — الموجود مايتلمسش.
     *
     * @return array{created: int}
     */
    public static function seedOpening(int $year, bool $dryRun = false): array
    {
        $created = 0;

        Employee::query()->where('active', true)->orderBy('id')->each(function (Employee $e) use ($year, $dryRun, &$created) {
            $have = LeaveBalance::query()->where('employee_id', $e->id)->where('year', $year)->pluck('type')->all();

            foreach (LeaveRequest::TYPES as $type) {
                if (in_array($type, $have, true)) {
                    continue;
                }

                $created++;

                if (! $dryRun) {
                    self::ensureBalance($e, $year, $type);
                }
            }
        });

        return ['created' => $created];
    }

    /** صف الرصيد من الديفولت لو مش موجود — آمن مع السباق (اليونيك) */
    private static function ensureBalance(Employee $e, int $year, string $type): void
    {
        $key = ['employee_id' => $e->id, 'year' => $year, 'type' => $type];

        if (LeaveBalance::query()->where($key)->exists()) {
            return;
        }

        try {
            LeaveBalance::create($key + ['entitled' => self::defaultEntitled($type), 'used' => 0]);
        } catch (QueryException $ex) {
            if ((int) ($ex->errorInfo[1] ?? 0) !== 1062) {
                throw $ex;
            }
        }
    }

    public static function fmt(float $n): string
    {
        return rtrim(rtrim(number_format($n, 1, '.', ''), '0'), '.');
    }
}
