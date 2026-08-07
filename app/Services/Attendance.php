<?php

namespace App\Services;

use App\Models\AttendanceDay;
use App\Models\AttendancePunch;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * الحضور والانصراف — كل المنطق في مكان واحد (2026-08-08)
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **مفيش أي كود تاني بيكتب في الجدولين دول.** الأبلكيشن والشاشات
 * والأمر المجدول كلهم بيعدّوا من هنا — عشان آلة الحالات وحساب
 * الساعات يفضلوا تعريف واحد. أول ما حد يعمل `AttendancePunch::create`
 * بره الخدمة دي، الساعات بتختلف عن السجل ومحدش يعرف مين الصح.
 *
 * **آلة الحالات** — الانتقالات المسموحة بس:
 *
 *      off  ──(in)──►  working  ──(break)──►  break
 *                         ▲                     │
 *                         └──────(back)─────────┘
 *      working ──(out)──►  off
 *      break   ──(out)──►  off      ← مسموح: نسي يرجع وانصرف
 *
 * ⚠️ **الانصراف من البريك مسموح عن قصد.** الموظف اللي راح ياكل
 * وقرر يمشي مايتحبسش في السيستم — والبريك بيتقفل عند وقت الانصراف
 * فالساعات بتطلع صح.
 *
 * **حساب الساعات:** الوقت بيتقسم لفترات بين كل بانش واللي بعده.
 * الفترة اللي بادئة بـ`in`/`back` = شغل، واللي بادئة بـ`break` =
 * بريك. الفترة المفتوحة (آخر بانش من غير اللي بعده) بتتحسب لحد
 * **دلوقتي** لو اليوم لسه مفتوح — عشان العداد في الأبلكيشن يمشي.
 */
final class Attendance
{
    /** الحالات اللي البانش مسموح فيها */
    private const ALLOWED = [
        AttendancePunch::IN => ['off'],
        AttendancePunch::BREAK => ['working'],
        AttendancePunch::BACK => ['break'],
        AttendancePunch::OUT => ['working', 'break'],
    ];

    /**
     * يوم النهارده للموظف — بيتعمل لو مش موجود.
     *
     * ⚠️ `firstOrCreate` مش `create` — الأبلكيشن بينده الحالة مع كل
     * فتحة، والصف لازم يبقى واحد مهما اتنادت.
     */
    public static function today(User $user, ?string $date = null): AttendanceDay
    {
        return AttendanceDay::firstOrCreate(
            ['user_id' => $user->id, 'date' => $date ?? today()->toDateString()],
            ['status' => AttendanceDay::STATUS_OPEN],
        );
    }

    /** الحالة دلوقتي: working · break · off */
    public static function state(User $user): string
    {
        return self::today($user)->state();
    }

    /**
     * ⚠️ **ده الحارس اللي كل السيستم بيسأله.** الموظف لازم يكون
     * `working` عشان يعمل أي أكشن — البريك بيمنع زي الانصراف
     * بالظبط (قرار المالك 2026-08-08): بريك يعني وقفت شغل، ولو
     * سمحنا بالبيع فيه بيبقى بريك على الورق بس.
     */
    public static function canWork(User $user): bool
    {
        return self::state($user) === 'working';
    }

    /**
     * تسجيل بانش. بترجع `null` لو تمام، أو رسالة الخطأ.
     *
     * @return array{0: ?string, 1: AttendanceDay} [الخطأ، اليوم]
     */
    public static function punch(
        User $user,
        string $type,
        ?float $lat = null,
        ?float $lng = null,
        bool $auto = false,
    ): array {
        if (! isset(self::ALLOWED[$type])) {
            return [__('hr.bad_punch'), self::today($user)];
        }

        $day = self::today($user);
        $state = $day->state();

        // ⚠️ الحارس ده بيمنع الدبل-كليك كمان: ضغطتين حضور ورا بعض
        // كانوا هيعملوا سطرين ويخربوا حساب الفترات
        if (! in_array($state, self::ALLOWED[$type], true)) {
            return [__('hr.bad_state_'.$type), $day];
        }

        DB::transaction(function () use ($day, $user, $type, $lat, $lng, $auto) {
            // ⚠️ **الانصراف بيقفل زيارة المخزن المفتوحة** (2026-08-08).
            // الزيارة المفتوحة هي إذن الاستلام — والموظف اللي انصرف
            // مش في المخزن بالتعريف. سيبانها مفتوحة كان معناه إن
            // الإذن بيفضل معاه بعد ما يمشي ويقدر يستلم من بيته.
            if ($type === AttendancePunch::OUT) {
                WarehouseVisits::closeOpenFor($user);
            }

            AttendancePunch::create([
                'attendance_day_id' => $day->id,
                'user_id' => $user->id,
                'type' => $type,
                'at' => now(),
                'lat' => $lat,
                'lng' => $lng,
                'auto' => $auto,
            ]);

            self::recalculate($day->fresh());
        });

        return [null, $day->fresh()];
    }

    /**
     * إعادة حساب اليوم من بانشاته.
     *
     * ⚠️ **بيتنادى بعد كل بانش وبعد أي تعديل.** الأرقام المخزنة
     * مالهاش أي مصدر تاني — لو اتغيّر سطر في السجل من غير النداء
     * ده، الشاشة بتفضل بترقم قديم للأبد.
     */
    public static function recalculate(AttendanceDay $day): AttendanceDay
    {
        $punches = $day->punches()->get();

        $worked = 0;
        $breaks = 0;
        $sessions = 0;
        $firstIn = null;
        $lastOut = null;

        foreach ($punches as $i => $p) {
            if ($p->type === AttendancePunch::IN) {
                $sessions++;
                $firstIn ??= $p->at;
            }

            if ($p->type === AttendancePunch::OUT) {
                $lastOut = $p->at;
            }

            // نهاية الفترة = البانش اللي بعده، أو دلوقتي لو اليوم
            // لسه مفتوح والبانش ده آخر واحد
            $next = $punches[$i + 1] ?? null;
            $end = $next?->at ?? self::openEnd($day, $p->at);

            if ($end === null) {
                continue;
            }

            $minutes = max($p->at->diffInMinutes($end, absolute: false), 0);

            if (in_array($p->type, [AttendancePunch::IN, AttendancePunch::BACK], true)) {
                $worked += $minutes;
            } elseif ($p->type === AttendancePunch::BREAK) {
                $breaks += $minutes;
            }
        }

        $day->forceFill([
            'worked_minutes' => (int) $worked,
            'break_minutes' => (int) $breaks,
            'sessions' => $sessions,
            'first_in_at' => $firstIn,
            'last_out_at' => $lastOut,
        ])->save();

        return $day;
    }

    /**
     * نهاية الفترة المفتوحة — **`null` دايماً**.
     *
     * ⚠️ **الفترة المفتوحة مابتتخزنش خالص** (إصلاح 2026-08-08). كانت
     * بترجع `now()`، يعني `worked_minutes` كان بيتحسب لحد **لحظة
     * آخر بانش وبس** — وبعدها الرقم يفضل واقف. الموظف يشتغل ساعتين
     * والشاشة تقول نفس الرقم لأن مفيش بانش تاني حصل يعيد الحساب.
     *
     * دلوقتي الفصل واضح:
     *   • `worked_minutes` المخزّن = **الفترات المقفولة بس** — حقيقة
     *     ثابتة مابتتغيّرش إلا ببانش جديد.
     *   • `AttendanceDay::liveMinutes()` = المخزّن + الفترة المفتوحة
     *     محسوبة **وقت العرض** — ده اللي كل الشاشات بتنادي عليه.
     *
     * والأبلكيشن بياخد `open_since` ويعدّ محلياً، فالعدّاد بيمشي من
     * غير ما يضرب السيرفر كل ثانية.
     */
    private static function openEnd(AttendanceDay $day, CarbonInterface $from): ?CarbonInterface
    {
        return null;
    }

    /**
     * قفل اليوم — بانش انصراف تلقائي.
     *
     * بيرجع `true` لو اتقفل فعلاً.
     */
    public static function autoClose(AttendanceDay $day): bool
    {
        if ($day->status !== AttendanceDay::STATUS_OPEN) {
            return false;
        }

        $state = $day->state();

        // مافيش حضور أصلاً — اليوم بيتقفل من غير بانش وهمي
        if ($state === 'off') {
            $day->update(['status' => AttendanceDay::STATUS_CLOSED]);

            return false;
        }

        DB::transaction(function () use ($day) {
            // ⚠️ نفس سبب الانصراف اليدوي — اليوم اللي اتقفل تلقائياً
            // مايسيبش إذن استلام مفتوح لليوم اللي بعده
            if ($day->user !== null) {
                WarehouseVisits::closeOpenFor($day->user);
            }

            AttendancePunch::create([
                'attendance_day_id' => $day->id,
                'user_id' => $day->user_id,
                'type' => AttendancePunch::OUT,
                // ⚠️ آخر ثانية في **يوم الشيفت** مش وقت تشغيل الأمر —
                // الأمر بيشتغل بعد منتصف الليل، ولو سجّلنا وقته
                // الفعلي كان اليوم هيطلع فيه انصراف بتاريخ تاني
                'at' => $day->date->copy()->endOfDay(),
                'auto' => true,
            ]);

            $day->update(['status' => AttendanceDay::STATUS_AUTO]);
            self::recalculate($day->fresh());
        });

        return true;
    }

    /** المدير بيعتمد أو بيعدّل الساعات */
    public static function approve(AttendanceDay $day, User $by, ?int $minutes, ?string $note): void
    {
        $day->update([
            'approved_minutes' => $minutes ?? $day->worked_minutes,
            'approved_by' => $by->id,
            'approved_at' => now(),
            'note' => $note,
        ]);
    }

    /** حمولة الأبلكيشن — نفس الشكل في البوت ستراب وفي شاشة الحضور */
    public static function payload(User $user): array
    {
        $day = self::today($user);

        $live = $day->liveMinutes();

        return [
            'state' => $day->state(),
            'status' => $day->status,
            // ⚠️ **`worked_minutes` = المقفول بس، مش اللحظي.**
            // الأبلكيشن بيضيف عليه الفترة المفتوحة من `open_since`
            // بنفسه عشان العدّاد يمشي — فلو بعتنا اللحظي هنا، الفترة
            // المفتوحة كانت هتتعدّ **مرتين** والرقم يجري بالضعف.
            'worked_minutes' => $day->worked_minutes,
            'break_minutes' => $day->break_minutes,
            // ⚠️ الليبل لحظي — لأي حاجة بتعرضه من غير حساب
            'worked_label' => AttendanceDay::hhmm($live),
            'break_label' => AttendanceDay::hhmm($day->break_minutes),
            // ⚠️ الأبلكيشن بيعدّ من الوقت ده محلياً — من غيره العدّاد
            // بيفضل واقف لحد الريكوست الجاي
            'open_since' => $day->openSince()?->toIso8601String(),
            'sessions' => $day->sessions,
            'first_in_at' => $day->first_in_at?->toIso8601String(),
            'last_out_at' => $day->last_out_at?->toIso8601String(),
            'punches' => $day->punches()->get()->map(fn ($p) => [
                'type' => $p->type,
                'label' => $p->typeLabel(),
                'icon' => $p->icon(),
                'at' => $p->at->toIso8601String(),
                'auto' => $p->auto,
                'lat' => $p->lat === null ? null : (float) $p->lat,
                'lng' => $p->lng === null ? null : (float) $p->lng,
            ])->values(),
        ];
    }
}
