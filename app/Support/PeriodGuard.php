<?php

namespace App\Support;

use App\Models\Gl\GlPeriod;
use App\Observers\Gl\PeriodGuardObserver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * ═══ حارس الشهر المقفول (الخط ٣، ١٩/١٠/٢٠٢٦ — قرار D7) ═══
 *
 * الحارس **مود على مستوى الطلب** مش قاعدة ثابتة:
 *   • `reject` — طلبات الويب: المستند بتاريخ جوه شهر مقفول بيترفض
 *     برسالة بتسمّي الشهر (`ValidationException` على مفتاح `period`).
 *   • `flag` — طلبات الـAPI (الموبايل): المزامنة المتأخرة بتتقبل،
 *     و`Ledger::post` بيعلّم قيدها `closed_period` + `late_sync`.
 *   • `off` — الكونسول والتيست المباشر: سلوك النهاردة بالظبط (بيتسجل
 *     ويتعلّم `needs_review`). ده الديفولت.
 *
 * الميدلوير `PeriodGuardMode` بيحط المود أول الطلب ويرجّعه في `finally`.
 * الموديلات المراقبة (`watch`) بيحرسها `PeriodGuardObserver` على
 * creating/updating/deleting. المسارات اللي بتكتب بـQuery Builder
 * (تحديث/مسح جماعي) بتنادي `assertOpen()` بنفسها قبل أي كتابة.
 */
final class PeriodGuard
{
    public const MODE_OFF = 'off';

    public const MODE_REJECT = 'reject';

    public const MODE_FLAG = 'flag';

    private static string $mode = self::MODE_OFF;

    /** @var array<string,true>|null مفاتيح الشهور المقفولة — كاش للطلب */
    private static ?array $closed = null;

    /** @var array<class-string, array{date: string|callable, ignore: array<int,string>}> */
    private static array $watched = [];

    public static function mode(): string
    {
        return self::$mode;
    }

    public static function setMode(string $mode): void
    {
        if (! in_array($mode, [self::MODE_OFF, self::MODE_REJECT, self::MODE_FLAG], true)) {
            throw new \InvalidArgumentException("Unknown period guard mode [{$mode}]");
        }
        self::$mode = $mode;
        self::flush();
    }

    /** يشغّل `$fn` بمود معيّن ويرجّع القديم مهما حصل */
    public static function using(string $mode, callable $fn): mixed
    {
        $prev = self::$mode;
        self::setMode($mode);
        try {
            return $fn();
        } finally {
            self::setMode($prev);
        }
    }

    /** بيتنادى لما فترة تتقفل/تتفتح، ومع كل تغيير مود */
    public static function flush(): void
    {
        self::$closed = null;
    }

    /** @return array<string,true> */
    public static function closedKeys(): array
    {
        if (self::$closed === null) {
            self::$closed = Schema::hasTable('gl_periods')
                ? array_fill_keys(GlPeriod::where('status', 'closed')->pluck('key')->all(), true)
                : [];
        }

        return self::$closed;
    }

    public static function isClosed(\DateTimeInterface|string|null $date): bool
    {
        $d = self::toCarbon($date);

        return $d !== null && isset(self::closedKeys()[GlPeriod::keyFor($d)]);
    }

    /** «سبتمبر 2026» / "September 2026" — بلغة الطلب الحالية */
    public static function label(\DateTimeInterface|string $date): string
    {
        return self::toCarbon($date)->copy()->locale(app()->getLocale())->translatedFormat('F Y');
    }

    public static function message(\DateTimeInterface|string $date): string
    {
        return __('gl.period_closed_reject', ['month' => self::label($date)]);
    }

    /**
     * في مود `reject` بس: التاريخ جوه شهر مقفول = `ValidationException`
     * على `period` (و`$what` كمان لو اتبعت — عشان الخانة نفسها تتعلّم).
     */
    public static function assertOpen(\DateTimeInterface|string|null $date, ?string $what = null): void
    {
        if (self::$mode !== self::MODE_REJECT || ! self::isClosed($date)) {
            return;
        }
        $msg = self::message($date);
        $errors = ['period' => $msg];
        if ($what !== null && $what !== 'period') {
            $errors[$what] = $msg;
        }

        throw ValidationException::withMessages($errors);
    }

    /**
     * يحط موديل تحت الحراسة. `$dateOf` اسم عمود أو دالة `fn(Model): mixed`
     * (null = المستند مالوش تاريخ محاسبي دلوقتي، زي أمر توريد لسه ماتسلّمش).
     * `$ignore` أعمدة التعديل عليها لوحدها مش تعديل محاسبي.
     */
    public static function watch(string $modelClass, string|callable $dateOf, array $ignore = []): void
    {
        $first = ! isset(self::$watched[$modelClass]);
        self::$watched[$modelClass] = ['date' => $dateOf, 'ignore' => array_values(array_unique(array_merge($ignore, ['updated_at'])))];
        if ($first) {
            $modelClass::observe(PeriodGuardObserver::class);
        }
    }

    /** @return array<class-string, array{date: string|callable, ignore: array<int,string>}> */
    public static function watched(): array
    {
        return self::$watched;
    }

    public static function registerDefaults(): void
    {
        // ⚠️ بيبدأ من الصفر مع كل boot للأبلكيشن — التيستات بتعمل أبلكيشن
        // جديد بديسباتشر جديد، والقايمة الستاتيك القديمة كانت هتمنع
        // تسجيل الأوبزرفر عليه. `watch()` من الخطوط التانية بييجي بعدها.
        self::$watched = [];
        self::$mode = self::MODE_OFF;
        self::flush();
        self::watch(\App\Models\Transaction::class, 'date', ['proof_path']);
        self::watch(\App\Models\SupplierTransaction::class, 'date');
        self::watch(\App\Models\Expense::class, 'date');
        self::watch(\App\Models\CashMovement::class, 'date');
        self::watch(\App\Models\RepSettlement::class, fn (Model $m) => $m->to_at ?? $m->created_at);
        // ⚠️ أعمدة الضرائب الإلكترونية بتتحدّث بعد الشهر بكتير (التصدير
        // والرفع) — دي مش تعديل على المستند
        self::watch(\App\Models\Invoice::class, 'created_at', ['eta_status', 'eta_uuid', 'eta_submitted_at']);
        self::watch(\App\Models\PurchaseOrder::class, fn (Model $m) => $m->status === 'delivered' ? ($m->delivered_at ?? $m->created_at) : null);
        self::watch(\App\Models\ClientReturn::class, 'created_at');
    }

    /** تاريخ المستند — `$original` = قبل التعديل */
    public static function dateFor(Model $m, bool $original = false): ?Carbon
    {
        $cfg = self::$watched[$m::class] ?? null;
        if ($cfg === null) {
            return null;
        }
        if ($original) {
            $m = (clone $m)->setRawAttributes($m->getRawOriginal(), true);
        }
        $raw = is_string($cfg['date']) ? $m->getAttribute($cfg['date']) : ($cfg['date'])($m);

        return self::toCarbon($raw);
    }

    /** التعديل لمس أعمدة محاسبية (مش بس المتجاهلة) */
    public static function touchesGuarded(Model $m): bool
    {
        $ignore = self::$watched[$m::class]['ignore'] ?? ['updated_at'];

        return array_diff(array_keys($m->getDirty()), $ignore) !== [];
    }

    private static function toCarbon(mixed $d): ?Carbon
    {
        if ($d === null || $d === '') {
            return null;
        }

        return $d instanceof \DateTimeInterface ? Carbon::instance($d) : Carbon::parse($d);
    }
}
