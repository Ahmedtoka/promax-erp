<?php

namespace App\Services;

use App\Models\AttendanceDay;
use App\Models\AttendancePunch;
use App\Models\Employee;
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
        // ⚠️ (Lane 7) ملف الموظف بيتقري **وقت الإنشاء بس** (closure) — الأبلكيشن
        // بينده هنا مع كل فتحة، وكويري زيادة على كل نداء مالهاش لازمة
        return AttendanceDay::firstOrCreate(
            ['user_id' => $user->id, 'date' => $date ?? today()->toDateString()],
            fn () => ['status' => AttendanceDay::STATUS_OPEN, 'employee_id' => self::freeEmployeeId($user, $date)],
        );
    }

    /**
     * ملف الموظف لليوم الجديد — إلا لو العامل اتبصم له يوم النهارده قبل ما
     * ياخد لوجين (`unique(employee_id, date)` كان هيرمي 500 على أول بانش).
     */
    private static function freeEmployeeId(User $user, ?string $date): ?int
    {
        $emp = Employee::forUser($user);

        if ($emp === null) {
            return null;
        }

        $taken = AttendanceDay::where('employee_id', $emp->id)
            ->where('date', $date ?? today()->toDateString())->exists();

        return $taken ? null : $emp->id;
    }

    /**
     * يوم الموظف (Lane 7) — اللي ليه لوجين يومه هو `today($user)` بالظبط،
     * والعامل اللي مالوش لوجين يومه على `employee_id` بس (`user_id` null).
     */
    public static function todayForEmployee(Employee $e, ?string $date = null): AttendanceDay
    {
        if ($e->user_id !== null && $e->user !== null) {
            return self::today($e->user, $date);
        }

        return AttendanceDay::firstOrCreate(
            ['employee_id' => $e->id, 'date' => $date ?? today()->toDateString()],
            ['status' => AttendanceDay::STATUS_OPEN],
        );
    }

    /**
     * حالة النهارده لموظفين — **قراءة بس** زي `peekStates` (Lane 7). بتقرا
     * اليوم بـ`employee_id` (العامل من غير لوجين) أو بـ`user_id` (الموظف
     * اللي يومه اتفتح قبل ما ملفه يتعمل).
     *
     * @param  \Illuminate\Support\Collection<int, Employee>  $employees
     * @return array<int, string> [employee_id => working|break|off]
     */
    public static function peekEmployeeStates(\Illuminate\Support\Collection $employees): array
    {
        if ($employees->isEmpty()) {
            return [];
        }

        $userIds = $employees->pluck('user_id')->filter()->values();
        $days = AttendanceDay::with('punches')
            ->whereDate('date', today())
            ->where(fn ($q) => $q->whereIn('employee_id', $employees->pluck('id'))
                ->when($userIds->isNotEmpty(), fn ($w) => $w->orWhereIn('user_id', $userIds)))
            ->get();

        return $employees->mapWithKeys(function (Employee $e) use ($days) {
            $day = $days->first(fn ($d) => ($e->user_id !== null && (int) $d->user_id === (int) $e->user_id)
                || (int) $d->employee_id === $e->id);

            return [$e->id => $day?->state() ?? 'off'];
        })->all();
    }

    /** الانتقالات المسموحة من الحالة دي — لزراير الهيدر وشاشة المشرف */
    public static function nextTypes(string $state): array
    {
        return array_values(array_keys(array_filter(self::ALLOWED, fn ($from) => in_array($state, $from, true))));
    }

    /**
     * حالة النهارده لمجموعة موظفين — **قراءة بس** (٢٥/٩).
     *
     * ⚠️ **مش `state()` في لوب.** `state()` بتنده `today()` اللي بتعمل
     * `firstOrCreate` — فبورد عهد المناديب كان بيفتح يوم حضور فاضي لكل
     * مندوب بمجرد ما حد يبص عليه، وده عكس قاعدة شاشات العرض
     * (`JourneyController`). اليوم اللي مالوش صف = مفيش بانش = `off`،
     * وده نفس اللي `state()` كانت هترجّعه لصف لسه متعمل.
     *
     * @param  \Illuminate\Support\Collection<int, User>  $users
     * @return array<int, string> [user_id => working|break|off]
     */
    public static function peekStates(\Illuminate\Support\Collection $users): array
    {
        $days = AttendanceDay::with('punches')
            ->whereIn('user_id', $users->pluck('id'))
            ->whereDate('date', today())
            ->get()->keyBy('user_id');

        return $users->mapWithKeys(fn ($u) => [
            $u->id => $days->get($u->id)?->state() ?? 'off',
        ])->all();
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
        string $source = 'app',
        array $meta = [],
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

        // ═══ عكس حارس الحضور (قرار المالك ٩ أغسطس ٢٠٢٦) ═══
        //
        // زي ما «مش حاضر = مفيش شغل»، بقى «شغل مفتوح = مفيش انصراف».
        // زيارة مفتوحة، أمر توريد جاري تسليمه، أو عهدة النهارده لسه
        // مش مقفولة — كلهم بيمنعوا الانصراف اليدوي برسالة بتقول
        // بالظبط إيه المفتوح.
        //
        // ⚠️ **اليدوي بس** — القفل الأوتوماتيكي بعد منتصف الليل
        // (`autoClose`) بيعدّي من غير الحارس ده عن قصد: لو منعناه،
        // يوم فيه زيارة اتنست مفتوحة كان هيفضل مفتوح للأبد ويبوّظ
        // حساب الساعات، والزيارة المتعلّقة مشكلة تانية ليها علاجها.
        if ($type === AttendancePunch::OUT && ! $auto) {
            $open = self::openWork($user);

            if ($open !== []) {
                return [__('hr.block_out_intro').' '.implode(' · ', $open), $day];
            }
        }

        // يوم اتفتح قبل ملف الموظف — يتربط أول ما يبصم (Lane 7)
        if ($day->employee_id === null
            && ($empId = self::freeEmployeeId($user, $day->date->toDateString())) !== null) {
            $day->forceFill(['employee_id' => $empId])->save();
        }

        DB::transaction(function () use ($day, $user, $type, $lat, $lng, $auto, $source, $meta) {
            // ⚠️ **الانصراف بيقفل زيارة المخزن المفتوحة** (2026-08-08).
            // الزيارة المفتوحة هي إذن الاستلام — والموظف اللي انصرف
            // مش في المخزن بالتعريف. سيبانها مفتوحة كان معناه إن
            // الإذن بيفضل معاه بعد ما يمشي ويقدر يستلم من بيته.
            if ($type === AttendancePunch::OUT) {
                WarehouseVisits::closeOpenFor($user);
            }

            self::record($day, $user->id, $type, $lat, $lng, $auto, $source, $meta);

            self::recalculate($day->fresh());
        });

        return [null, $day->fresh()];
    }

    /**
     * المشرف بيسجّل للعامل اللي **مالوش لوجين** (Lane 7).
     *
     * ⚠️ نفس آلة الحالات (`ALLOWED`)، ومن غير حارس الشغل المفتوح — مفيش
     * لوجين يعني مفيش زيارات ولا أوامر توريد. الموظف اللي ليه لوجين بيبصم
     * بنفسه (أبلكيشن/داش بورد) — المشرف مايبصمش مكانه.
     *
     * @return array{0: ?string, 1: AttendanceDay} [الخطأ، اليوم]
     */
    public static function punchEmployee(Employee $e, string $type, User $by): array
    {
        if ($e->user_id !== null) {
            return [__('hrm.supervisor_has_login'), self::peekEmployeeDay($e)];
        }

        if (! $e->active) {
            return [__('hrm.employee_inactive'), self::peekEmployeeDay($e)];
        }

        if (! isset(self::ALLOWED[$type])) {
            return [__('hr.bad_punch'), self::peekEmployeeDay($e)];
        }

        $day = self::todayForEmployee($e);

        if (! in_array($day->state(), self::ALLOWED[$type], true)) {
            return [__('hr.bad_state_'.$type), $day];
        }

        DB::transaction(function () use ($day, $type, $by) {
            self::record($day, null, $type, null, null, false, 'supervisor', ['recorded_by' => $by->id]);

            self::recalculate($day->fresh());
        });

        return [null, $day->fresh()];
    }

    /** يوم العامل النهارده من غير ما يتعمل — للرفض (مفيش صف يتفتح على غلطة) */
    private static function peekEmployeeDay(Employee $e): AttendanceDay
    {
        return AttendanceDay::where('employee_id', $e->id)->whereDate('date', today())->first()
            ?? new AttendanceDay(['employee_id' => $e->id, 'date' => today()->toDateString(), 'status' => AttendanceDay::STATUS_OPEN]);
    }

    /**
     * سطر البانش نفسه — المكان الوحيد اللي بيعمل `AttendancePunch::create`
     * للبانش اليدوي. `$meta`: `device` · `ip` · `recorded_by`.
     */
    private static function record(
        AttendanceDay $day,
        ?int $userId,
        string $type,
        ?float $lat,
        ?float $lng,
        bool $auto,
        string $source,
        array $meta,
    ): AttendancePunch {
        $device = isset($meta['device']) ? trim(mb_substr((string) $meta['device'], 0, 190)) : null;

        return AttendancePunch::create([
            'attendance_day_id' => $day->id,
            'user_id' => $userId,
            'employee_id' => $day->employee_id,
            'type' => $type,
            'at' => now(),
            'lat' => $lat,
            'lng' => $lng,
            'auto' => $auto,
            'source' => in_array($source, AttendancePunch::SOURCES, true) ? $source : 'app',
            'device' => $device === '' ? null : $device,
            'ip' => isset($meta['ip']) ? mb_substr((string) $meta['ip'], 0, 45) : null,
            'recorded_by' => $meta['recorded_by'] ?? null,
        ]);
    }

    /**
     * الشغل المفتوح اللي بيمنع الانصراف (٩ أغسطس ٢٠٢٦).
     *
     * بترجع قايمة رسايل جاهزة للعرض — فاضية يعني اتفضل ينصرف.
     * الترتيب بترتيب «اقفل إيه الأول» الطبيعي: الزيارة اللي هو
     * واقف فيها، ثم زيارة الرف، ثم الأمر اللي في نص تسليم، ثم العهدة.
     *
     * ⚠️ **العهدة بتتقفل من الداش بورد** (`OpsController::closeCustody`
     * على شاشة المندوب) — يعني بلوك العهدة بيتفك لما المدير يقفل
     * العربية بعد ما المندوب يرجّع. لو ده عطّل التشغيل عملياً،
     * القرار عند المالك يشيل الشرط ده بس من القايمة.
     *
     * @return list<string>
     */
    public static function openWork(User $user): array
    {
        $open = [];

        // ١) زيارة بيع مفتوحة — نفس مرساة الفاتورة والتحصيل
        if ($visit = $user->openVisit()) {
            $open[] = __('hr.block_out_visit', [
                'client' => $visit->client?->displayName() ?? '—',
            ]);
        }

        // ٢) زيارة رف مفتوحة (البروموتر) — مقفولة = بالصورتين
        $merch = \App\Models\MerchVisit::where('user_id', $user->id)
            ->whereNull('checked_out_at')->latest()->first();
        if ($merch !== null) {
            $open[] = __('hr.block_out_merch', [
                'client' => $merch->client?->displayName() ?? '—',
            ]);
        }

        // ٣) أمر توريد في نص التسليم — عمل «وصول» وماسلّمش.
        // ⚠️ `pending` مش بيمنع عن قصد: أمر متسكّن لبكرة مايحبسش
        // السواق النهارده — اللي بيمنع هو اللي **ابتدى** فعلاً.
        $arrived = \App\Models\PurchaseOrder::where('assigned_to', $user->id)
            ->where('status', 'arrived')->pluck('number');
        foreach ($arrived as $number) {
            $open[] = __('hr.block_out_po', ['number' => $number]);
        }

        // ⚠️ **العهدة المفتوحة مابتمنعش الانصراف** (قرار المالك ١١/٨).
        // الفلو الطبيعي: المندوب يصفّي الفلوس آخر اليوم، والعربية
        // تبات بباقي البضاعة ويكملها بكرة (وممكن تتحمّل زيادة) —
        // العهدة بتتقفل «كل فين وفين» مش يومياً. حبس الانصراف عليها
        // كان بيناقض عقيدة currentCustody نفسها (العهدة بتعيش أيام).
        // اللي بيمنع فعلاً: زيارة مفتوحة، رف مفتوح، أمر توريد ابتدى.

        return $open;
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

            // ⚠️ **وزيارات العملاء المفتوحة كمان** (إصلاح ١١/٨ — حالة
            // «هيد باديل»): زيارة اتنست مفتوحة كانت بتمنع أي تشيك إن
            // تاني يوم — والبانر مش لاقيها لأنها مش في قوايم النهارده.
            // القفل التلقائي بيقفلها على آخر يوم الشيفت — نفس منطق
            // بانش الانصراف الوهمي بالظبط.
            //
            // ⚠️ (Lane 7) العامل اللي مالوش لوجين `user_id` null — و`where('user_id', null)`
            // في لارافيل = `whereNull`، يعني كان هيقفل أي زيارة يتيمة. مفيش لوجين = مفيش زيارات.
            if ($day->user_id !== null) {
                \App\Models\Visit::where('user_id', $day->user_id)
                    ->whereNull('checked_out_at')
                    ->update(['checked_out_at' => $day->date->copy()->endOfDay()]);
            }

            AttendancePunch::create([
                'attendance_day_id' => $day->id,
                'user_id' => $day->user_id,
                'employee_id' => $day->employee_id,
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

    /**
     * ═══ الانصراف الإداري من الداش بورد (١١ أغسطس ٢٠٢٦) ═══
     *
     * طلب المالك: «أعمل للناس من عندي تشيك أوت للشغل زي الزيارات
     * المفتوحة — وأنا بخرّجه بحدد عدد ساعات العمل».
     *
     * ⚠️ **بيعدّي من غير حارس `openWork` عن قصد** — زي `autoClose`
     * بالظبط: ده قرار إداري، والشغل المفتوح (زيارة/رف/أمر توريد)
     * ليه كروته الخاصة في نفس الشاشة وبيتعرض هنا كتنبيه بس.
     *
     * **إزاي الساعات المطلوبة بتتحقق:**
     *   • لو الموظف `working`: بانش الانصراف بيتحط على «أول حضور +
     *     الساعات المطلوبة + البريكات المقفولة» — فالمحسوب من السجل
     *     نفسه بيطلع بالظبط الرقم المطلوب. لو الوقت ده في المستقبل
     *     أو قبل آخر بانش (ساعات أقل من المشتغل فعلاً)، البانش
     *     بيتحط **دلوقتي** — السجل الخام مايتزوّرش أبداً.
     *   • وفي كل الحالات `approved_minutes` بتتظبط على المطلوب —
     *     ودي اللي بتتحاسب (`payableMinutes` بيغلّب المعتمد على
     *     المحسوب)، فرقم الأدمن هو اللي بيروح المرتبات مهما قال
     *     السجل الخام.
     *
     * حالة اليوم بتفضل زي الانصراف اليدوي (بتتقفل مع أمر منتصف
     * الليل) — فلو الموظف رجع سجّل حضور تاني نفس اليوم مفيش حاجة
     * بتتكسر.
     *
     * بترجع `null` لو تمام، أو رسالة الخطأ.
     */
    public static function forceOut(User $user, User $by, int $minutes, ?string $note = null): ?string
    {
        $day = self::today($user);
        $state = $day->state();

        if (! in_array($state, ['working', 'break'], true)) {
            return __('hr.force_out_not_in');
        }

        DB::transaction(function () use ($day, $user, $by, $minutes, $note, $state) {
            // نفس قاعدة أي انصراف — إذن استلام المخزن بيتقفل معاه
            WarehouseVisits::closeOpenFor($user);

            // البانش على الوقت اللي يخلّي المحسوب = المطلوب، لو ده
            // ممكن من غير بانش في المستقبل ولا قبل آخر بانش
            $outAt = now();

            if ($state === 'working' && $day->first_in_at !== null) {
                $target = $day->first_in_at->copy()
                    ->addMinutes($minutes + (int) $day->break_minutes);
                $last = $day->lastPunch()?->at;

                if ($last !== null && $target->gte($last) && $target->lte(now())) {
                    $outAt = $target;
                }
            }

            AttendancePunch::create([
                'attendance_day_id' => $day->id,
                'user_id' => $user->id,
                'employee_id' => $day->employee_id,
                'type' => AttendancePunch::OUT,
                'at' => $outAt,
                'auto' => false,
                'forced_by' => $by->id,
            ]);

            $fresh = self::recalculate($day->fresh());

            // رقم الأدمن هو المعتمد — بيغلب المحسوب في المرتبات،
            // والملاحظة بتوثّق إن القفلة إدارية ومين اللي عملها
            $stamp = __('hr.forced_out_note', ['by' => $by->displayName()]);

            self::approve($fresh, $by, $minutes,
                $note !== null && trim($note) !== '' ? $stamp.' — '.trim($note) : $stamp);
        });

        return null;
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
            // ⚠️ `(int) (… ?? 0)` — اليوم غير المحفوظ (موظف مالوش صف
            // النهارده: سواق/أمين مخزن/محاسب) بيرجّع null و`hhmm(int)`
            // كانت بترمي TypeError = 500 على البوت ستراب كله (١٥/٩)
            'break_label' => AttendanceDay::hhmm((int) ($day->break_minutes ?? 0)),
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
                // انصراف إداري (١١/٨) — الأبلكيشن بيتجاهل المفاتيح
                // الزيادة فالإضافة آمنة، وأي شاشة جاية تلاقيها جاهزة
                'forced' => $p->forced_by !== null,
                // Lane 7: app · dashboard · supervisor — الإضافة الوحيدة على الشكل
                'source' => $p->source,
                'lat' => $p->lat === null ? null : (float) $p->lat,
                'lng' => $p->lng === null ? null : (float) $p->lng,
            ])->values(),
        ];
    }
}
