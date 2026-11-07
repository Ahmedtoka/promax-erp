<?php

namespace App\Services\Fingerprint;

use App\Models\AppNotification;
use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Models\FpCommand;
use App\Models\FpDevice;
use App\Models\FpDeviceUser;
use App\Models\FpLog;
use App\Models\User;
use App\Services\Attendance;
use App\Services\Hr\HrSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * بروتوكول ZKTeco ADMS / «Push SDK» / iclock (٧/١١/٢٠٢٦).
 *
 * المكنة هي اللي بتكلّم السيرفر (HTTP عادي) — مفيش حاجة بتتفتح في راوتر المكتب:
 *
 *   GET  /iclock/cdata?SN=..&options=all   ← أول ما تشتغل: السيرفر يرد بالإعدادات (handshake)
 *   POST /iclock/cdata?SN=..&table=ATTLOG  ← البصمات: سطر لكل بصمة
 *        PIN \t YYYY-MM-DD HH:MM:SS \t status \t verify \t workcode \t ...
 *   POST /iclock/cdata?SN=..&table=OPERLOG ← اليوزرز وعمليات الإدارة (USER PIN=..\tName=..)
 *   GET  /iclock/getrequest?SN=..          ← كل ~١٠ ثواني: «فيه أوامر ليّا؟» (OK أو C:id:CMD)
 *   POST /iclock/devicecmd?SN=..           ← نتيجة الأمر: ID=..&Return=..&CMD=..
 *
 * المراجع: تطبيقات open-source (saifulcoder/adms-server-ZKTeco، tanemrahman/zkteco-adms)
 * — الوقت في السطر بتوقيت المكنة المحلي (القاهرة = نفس `APP_TIMEZONE`).
 *
 * ⚠️ **مكنة سيريالها مش متسجل = `null`** والكنترولر يرد 404 — مفيش مكنة غريبة
 * تقدر تبعت بصمات. وكل محاولة بتتسجل على الكاش عشان شاشة الأجهزة تعرض
 * «مكنة بتحاول تتصل وسيريالها مش متسجل» (أول مرة بتركّب المكنة).
 */
final class Adms
{
    /** سيريال مكنة مش متسجلة حاولت تتصل — الشاشة بتعرضه عشان التسجيل يبقى نسخ ولصق */
    public const UNKNOWN_CACHE_KEY = 'fp_unknown_serials';

    public static function device(?string $serial, ?string $ip = null): ?FpDevice
    {
        $serial = trim((string) $serial);

        if ($serial === '') {
            return null;
        }

        $device = FpDevice::where('serial', $serial)->where('active', true)->first();

        if ($device === null) {
            $seen = cache()->get(self::UNKNOWN_CACHE_KEY, []);
            $seen[mb_substr($serial, 0, 40)] = ['ip' => $ip, 'at' => now()->toDateTimeString()];
            cache()->put(self::UNKNOWN_CACHE_KEY, array_slice($seen, -10, null, true), now()->addDays(7));

            return null;
        }

        // ⚠️ كتابة واحدة كل ٣٠ ثانية بالكتير — المكنة بتعمل getrequest كل ١٠ ثواني
        if ($device->last_seen_at === null || $device->last_seen_at->lt(now()->subSeconds(30)) || $device->ip !== $ip) {
            $device->forceFill(['last_seen_at' => now(), 'ip' => $ip])->saveQuietly();
        }

        return $device;
    }

    /**
     * رد الـhandshake. `ATTLOGStamp` = آخر ختم رفعته المكنة — مكنة جديدة (null ⇒ 0)
     * بترفع كل اللي عندها، والقديم (قبل `apply_from`) بيتسجل خام بس.
     * مفيش `TimeZone`/`DateTime` — ساعة المكنة بتتظبط من المكنة نفسها.
     */
    public static function handshake(FpDevice $d): string
    {
        return implode("\n", [
            'GET OPTION FROM: '.$d->serial,
            'ATTLOGStamp='.($d->attlog_stamp ?: '0'),
            'OPERLOGStamp='.($d->operlog_stamp ?: '0'),
            'ATTPHOTOStamp=None',
            'ErrorDelay=30',
            'Delay=10',
            'TransTimes=00:00;14:05',
            'TransInterval=1',
            'TransFlag=TransData AttLog OpLog EnrollUser ChgUser',
            'Realtime=1',
            'Encrypt=None',
            'ServerVer=2.4.1',
        ])."\n";
    }

    /**
     * سطور ATTLOG ⇒ `fp_logs` + الحضور. بيرجع عدد السطور اللي اتقبلت (`OK: n`).
     *
     * ⚠️ **idempotent**: `unique(device, pin, punched_at)` — المكنة لو رفعت نفس
     * السطر تاني (ماوصلهاش الـOK) مابيتكررش ولا بيعمل بانش تاني.
     */
    public static function attlog(FpDevice $d, string $body, ?string $stamp = null): int
    {
        $accepted = 0;

        foreach (preg_split('/\r\n|\r|\n/', $body) as $line) {
            $row = self::parseAttLine($line);

            if ($row === null) {
                continue;
            }

            $accepted++;

            $log = FpLog::firstOrCreate(
                ['fp_device_id' => $d->id, 'pin' => $row['pin'], 'punched_at' => $row['at']->toDateTimeString()],
                ['status' => $row['status'], 'verify' => $row['verify'], 'workcode' => $row['workcode'], 'result' => FpLog::UNMAPPED],
            );

            if ($log->wasRecentlyCreated) {
                self::apply($log, $d);
            }
        }

        $last = FpLog::where('fp_device_id', $d->id)->max('punched_at');
        $d->forceFill(array_filter([
            'attlog_stamp' => $stamp !== null && $stamp !== '' ? mb_substr($stamp, 0, 20) : null,
            'last_punch_at' => $last,
            'last_seen_at' => now(),
        ], fn ($v) => $v !== null))->saveQuietly();

        return $accepted;
    }

    /** `PIN\tTIME\tSTATUS\tVERIFY\tWORKCODE...` — سطر بايظ = null (بيتساب) */
    public static function parseAttLine(string $line): ?array
    {
        $line = trim($line);

        if ($line === '') {
            return null;
        }

        $f = str_contains($line, "\t") ? explode("\t", $line) : preg_split('/\s{2,}/', $line);
        $pin = trim($f[0] ?? '');

        if ($pin === '' || mb_strlen($pin) > 20 || ! isset($f[1])) {
            return null;
        }

        try {
            $at = Carbon::createFromFormat('Y-m-d H:i:s', trim($f[1]));
        } catch (\Throwable) {
            return null;
        }

        if ($at === false || $at === null) {
            return null;
        }

        return [
            'pin' => $pin,
            'at' => $at,
            'status' => (int) ($f[2] ?? 0),
            'verify' => (int) ($f[3] ?? 0),
            'workcode' => ($w = trim($f[4] ?? '')) !== '' && $w !== '0' ? mb_substr($w, 0, 20) : null,
        ];
    }

    /**
     * بصمة خام ⇒ بانش حضور. بتتنادى أول ما البصمة توصل، وتاني لما رقم المكنة
     * يتربط بموظف (`reapplyPin`).
     */
    public static function apply(FpLog $log, ?FpDevice $d = null): string
    {
        $d ??= $log->device;

        if ($d->apply_from !== null && $log->punched_at->lt($d->apply_from)) {
            $log->update(['result' => FpLog::OLD]);

            return FpLog::OLD;
        }

        $e = Employee::where('fp_pin', $log->pin)->first();

        if ($e === null) {
            $log->update(['result' => FpLog::UNMAPPED]);
            self::notifyUnmapped($log, $d);

            return FpLog::UNMAPPED;
        }

        if (! $e->active) {
            $log->update(['result' => FpLog::INACTIVE, 'employee_id' => $e->id]);

            return FpLog::INACTIVE;
        }

        $type = $d->mode === 'toggle' ? null : (FpLog::STATUS_MAP[$log->status] ?? null);

        [$result, $punch] = Attendance::punchFromDevice($e, $type, $log->punched_at, [
            'device' => $d->name.' · '.$d->serial,
        ]);

        $log->update([
            'result' => $result,
            'employee_id' => $e->id,
            'attendance_punch_id' => $punch?->id,
            'punch_type' => $punch?->type,
        ]);

        if ($punch !== null) {
            self::notifyPunch($e, $punch, $log);
            self::retryLater($e, $log);
        }

        return $result;
    }

    /**
     * ⚠️ **الترتيب بعد انقطاع النت.** الانصراف ممكن يوصل قبل الحضور (إعادة رفع، مكنتين)
     * فيترفض «مش حاضر». أول ما بانش يتسجل، البصمات اللي بعده في نفس اليوم واترفضت
     * بتتجرب تاني بالترتيب — المكررة بجد بتفضل مكررة.
     */
    private static function retryLater(Employee $e, FpLog $log): void
    {
        FpLog::where('employee_id', $e->id)->where('result', FpLog::DUPLICATE)
            ->whereKeyNot($log->id)
            ->where('punched_at', '>', $log->punched_at)
            ->where('punched_at', '<=', $log->punched_at->copy()->endOfDay())
            ->orderBy('punched_at')->orderBy('id')
            ->get()
            ->each(fn (FpLog $l) => self::apply($l));
    }

    /** رقم المكنة اتربط بموظف ⇒ بصماته اللي كانت مستنية تتطبّق بالترتيب */
    public static function reapplyPin(string $pin): int
    {
        $n = 0;

        FpLog::where('pin', $pin)->where('result', FpLog::UNMAPPED)
            ->orderBy('punched_at')->orderBy('id')
            ->each(function (FpLog $log) use (&$n) {
                if (self::apply($log) === FpLog::APPLIED) {
                    $n++;
                }
            });

        return $n;
    }

    /** OPERLOG: `USER PIN=1\tName=Ahmed\tPri=0\tPasswd=\tCard=\tGrp=1...` ⇒ fp_device_users */
    public static function operlog(FpDevice $d, string $body, ?string $stamp = null): int
    {
        $n = 0;

        foreach (preg_split('/\r\n|\r|\n/', $body) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $n++;

            if (! str_starts_with($line, 'USER ')) {
                continue;
            }

            $kv = [];
            foreach (explode("\t", substr($line, 5)) as $pair) {
                [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
                $kv[trim($k)] = trim($v);
            }

            if (($kv['PIN'] ?? '') === '') {
                continue;
            }

            FpDeviceUser::updateOrCreate(
                ['fp_device_id' => $d->id, 'pin' => mb_substr($kv['PIN'], 0, 20)],
                [
                    'name' => mb_substr($kv['Name'] ?? '', 0, 80) ?: null,
                    'card' => mb_substr($kv['Card'] ?? '', 0, 30) ?: null,
                    'privilege' => (int) ($kv['Pri'] ?? 0),
                ],
            );
        }

        if ($stamp !== null && $stamp !== '') {
            $d->forceFill(['operlog_stamp' => mb_substr($stamp, 0, 20)])->saveQuietly();
        }

        return $n;
    }

    /**
     * `INFO=Ver 6.60 Apr 2020,12,30,4500,192.168.1.201,...` (getrequest أول مرة)
     * = firmware, users, fingerprints, logs, ip.
     */
    public static function info(FpDevice $d, ?string $info): void
    {
        if ($info === null || trim($info) === '') {
            return;
        }

        $f = array_map('trim', explode(',', $info));
        $d->forceFill([
            'firmware' => mb_substr($f[0] ?? '', 0, 60) ?: $d->firmware,
            'users_count' => isset($f[1]) && is_numeric($f[1]) ? (int) $f[1] : $d->users_count,
            'fp_count' => isset($f[2]) && is_numeric($f[2]) ? (int) $f[2] : $d->fp_count,
            'logs_count' => isset($f[3]) && is_numeric($f[3]) ? (int) $f[3] : $d->logs_count,
        ])->saveQuietly();
    }

    /** الأوامر المستنية ⇒ `C:<id>:<cmd>` سطر لكل أمر، أو `OK` */
    public static function pendingCommands(FpDevice $d): string
    {
        // أمر اتبعت ومارجعش نتيجته في ٣٠ دقيقة بيتبعت تاني
        FpCommand::where('fp_device_id', $d->id)->where('status', 'sent')
            ->where('sent_at', '<', now()->subMinutes(30))->update(['status' => 'pending']);

        $cmds = FpCommand::where('fp_device_id', $d->id)->where('status', 'pending')
            ->orderBy('id')->limit(10)->get();

        if ($cmds->isEmpty()) {
            return 'OK';
        }

        FpCommand::whereKey($cmds->pluck('id'))->update(['status' => 'sent', 'sent_at' => now()]);

        return $cmds->map(fn (FpCommand $c) => 'C:'.$c->id.':'.$c->command)->implode("\n")."\n";
    }

    /** `ID=12&Return=0&CMD=DATA` سطر لكل أمر — Return >= 0 تمام */
    public static function commandResults(FpDevice $d, string $body): void
    {
        foreach (preg_split('/\r\n|\r|\n/', $body) as $line) {
            parse_str(trim($line), $kv);
            $kv = array_change_key_case($kv, CASE_LOWER);

            if (! isset($kv['id']) || ! is_numeric($kv['id'])) {
                continue;
            }

            $ret = (int) ($kv['return'] ?? 0);
            FpCommand::where('fp_device_id', $d->id)->whereKey((int) $kv['id'])->update([
                'status' => $ret >= 0 ? 'done' : 'failed',
                'return_code' => $ret,
                'done_at' => now(),
            ]);
        }
    }

    /** أمر جديد للمكنة — هتاخده في أول getrequest (~١٠ ثواني) */
    public static function queue(FpDevice $d, string $command, ?User $by = null): FpCommand
    {
        return FpCommand::create([
            'fp_device_id' => $d->id,
            'command' => mb_substr(str_replace(["\r", "\n"], ' ', $command), 0, 500),
            'created_by' => $by?->id,
        ]);
    }

    /** إعادة رفع البصمات من تاريخ — للبصمات اللي اتبصمت والنت قاطع أو قبل التسجيل */
    public static function queueResync(FpDevice $d, Carbon $from, Carbon $to, ?User $by = null): FpCommand
    {
        return self::queue($d, "DATA QUERY ATTLOG\tStartTime=".$from->format('Y-m-d 00:00:00')
            ."\tEndTime=".$to->format('Y-m-d 23:59:59'), $by);
    }

    /** أسماء الموظفين المربوطين ⇒ المكنة (الاسم بيظهر على الشاشة وقت البصمة) */
    public static function queueUsers(FpDevice $d, ?User $by = null): int
    {
        $n = 0;

        Employee::whereNotNull('fp_pin')->where('active', true)->orderBy('id')
            ->each(function (Employee $e) use ($d, $by, &$n) {
                // الاسم الإنجليزي — شاشة المكنة غالباً مابتعرضش عربي
                $name = preg_replace('/[\t\r\n=]/', ' ', (string) ($e->name_en ?: $e->code));
                self::queue($d, "DATA UPDATE USERINFO PIN={$e->fp_pin}\tName=".mb_substr($name, 0, 24)."\tPri=0", $by);
                $n++;
            });

        return $n;
    }

    // ═══ الإشعارات ═══

    /** مين بيستلم إشعارات البصمة: الأدمن والموارد البشرية */
    public static function recipients(): \Illuminate\Support\Collection
    {
        return User::whereIn('role', ['admin', 'hr'])->where('active', true)->get();
    }

    /**
     * إشعار البصمة: **الحضور المتأخر دايماً**، وكل بصمة لو الإعداد `fp_notify_all` = 1.
     * ⚠️ البصمات القديمة (اترفعت بعد انقطاع نت أكتر من ساعة) مابتعملش إشعار — دوشة.
     */
    private static function notifyPunch(Employee $e, AttendancePunch $p, FpLog $log): void
    {
        if ($log->punched_at->lt(now()->subHour())) {
            return;
        }

        $late = $p->type === AttendancePunch::IN ? self::lateMinutes($log->punched_at) : 0;
        $all = (string) \App\Models\Setting::read('fp_notify_all', '0') === '1';

        if ($late <= 0 && ! $all) {
            return;
        }

        $link = route('hr.attendance.daily', ['date' => $log->punched_at->toDateString()]);

        foreach (self::recipients() as $u) {
            AppNotification::send($u,
                fn () => $late > 0
                    ? __('fp.notify_late_title', ['name' => $e->displayName(), 'min' => $late])
                    : __('fp.notify_punch_title', ['name' => $e->displayName(), 'type' => $p->typeLabel()]),
                fn () => __('fp.notify_body', ['time' => $log->punched_at->format('H:i'), 'device' => $log->device?->name ?? '']),
                $late <= 0,
                $link,
            );
        }
    }

    /** بصمة لرقم مش مربوط — إشعار واحد لكل رقم في اليوم */
    private static function notifyUnmapped(FpLog $log, FpDevice $d): void
    {
        $key = 'fp_unmapped_'.$d->id.'_'.$log->pin.'_'.now()->toDateString();

        if ($log->punched_at->lt(now()->subHour()) || ! cache()->add($key, 1, now()->endOfDay())) {
            return;
        }

        foreach (self::recipients() as $u) {
            AppNotification::send($u,
                fn () => __('fp.notify_unmapped_title', ['pin' => $log->pin]),
                fn () => __('fp.notify_unmapped_body', ['device' => $d->name]),
                false,
                route('hr.fingerprint'),
            );
        }
    }

    /** دقايق التأخير عن بداية الشيفت لو عدّت السماح — نفس قاعدة المرتبات */
    public static function lateMinutes(Carbon $at): int
    {
        [$h, $m] = array_map('intval', explode(':', HrSettings::get('hr_shift_start').':0'));
        $mins = (int) round(($at->getTimestamp() - $at->copy()->setTime($h, $m)->getTimestamp()) / 60);

        return $mins > HrSettings::num('hr_late_grace_minutes') ? $mins : 0;
    }
}
