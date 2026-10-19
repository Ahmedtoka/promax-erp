<?php

namespace App\Services\Gl\GoLive\Steps;

use App\Exceptions\Rejected;
use App\Models\Gl\GlRebuildRun;
use App\Models\Setting;
use App\Models\User;
use App\Services\Gl\GoLive\Step;
use App\Services\Gl\GoLive\StepState;
use App\Services\Gl\Ledger;
use App\Services\Gl\RebuildDiff;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * ٩ — التشغيل الفعلي (الخط ٩، المهمة ٩ — D13 · §3.1.1).
 *
 * قفل `golive_run` (تشغيل واحد بس في نفس الوقت) → ترانزاكشن واحد بره: قفل
 * صف المعاينة + تأكد إنها ماتنفذتش → `RebuildDiff::execute` على آخر معاينة
 * اتوافق عليها والدفتر شغّال **وقت التشغيل بس** (`Ledger::forceEnabled`) —
 * كل المستندات والتكلفة والإنتاج والتحميل والبيع الداخلي والإهلاك والمرتبات
 * من تاريخ البداية → الحالة لازم `ok`. بعد الـcommit بس: `gl_enabled=1` +
 * `gl_golive_run_at` (قبل فك القفل). أي فشل = rollback لكل حاجة، وبعده صف
 * تشغيل `failed` رسالته «المرحلة: السبب — الفحوص الفاشلة» عشان الخطوة تبان
 * حمرا بالسبب.
 *
 * ⚠️ مراجعة الجولة ١: `gl_enabled` مابيتكتبش جوه الترانزاكشن — كاش الإعدادات
 * (ملفات/redis) كان هيسرّب قيمة لسه ماتعملهاش commit لكل الطلبات التانية.
 *
 * التقدّم والقفل في كاش **الملفات** (`cache.golive_store`، الافتراضي `file`)
 * مش الكاش الافتراضي: لو الافتراضي `database` الكتابة هتبقى جوه الترانزاكشن
 * ومحدش هيشوفها لحد الـcommit (ولا بعد الـrollback). التيستات بتحطه `array`.
 */
class RunStep implements Step
{
    public const PROGRESS_KEY = 'golive_run_progress';

    public const LOCK_KEY = 'golive_run';

    public const PHASES = ['start', 'snapshot', 'rebuild', 'diff', 'checks', 'done'];

    /** «شغّال» أقدم من كده ومفيش قفل = تشغيل اتقطع */
    public const STALE_MINUTES = 15;

    public function number(): int
    {
        return 9;
    }

    public function key(): string
    {
        return 'run';
    }

    public function titleKey(): string
    {
        return 'golive.s9_title';
    }

    public static function cache(): Repository
    {
        return Cache::store((string) config('cache.golive_store', 'file'));
    }

    public static function lastExecute(): ?GlRebuildRun
    {
        return GlRebuildRun::where('mode', 'execute')->latest('id')->first();
    }

    /** آخر معاينة لو اتوافق عليها، وإلا null */
    public static function approvedPreview(): ?GlRebuildRun
    {
        $p = GlRebuildRun::where('mode', 'preview')->latest('id')->first();

        return $p !== null && $p->approved_at !== null ? $p : null;
    }

    private static function executedFor(int $previewId): bool
    {
        return GlRebuildRun::where('mode', 'execute')->where('preview_id', $previewId)->where('status', 'ok')->exists();
    }

    /** المعاينة المعتمدة لسه ماتنفذتش بنجاح */
    public static function canRun(): bool
    {
        $p = self::approvedPreview();

        return $p !== null && $p->status === 'ok' && ! self::executedFor($p->id);
    }

    public function state(): StepState
    {
        $last = self::lastExecute();
        // تشغيل ناجح = أخضر على طول؛ محاولة فاشلة بعده تبان معلومة مش أحمر
        if (RebuildDiff::executed()) {
            $ok = GlRebuildRun::where('mode', 'execute')->where('status', 'ok')->latest('id')->first();
            $msgs = [__('golive.s9_done_at', ['at' => $ok->created_at?->format('Y-m-d H:i')])];
            if ($last !== null && $last->status !== 'ok') {
                $msgs[] = __('golive.s9_failed_after', ['msg' => (string) $last->error]);
            }

            return new StepState('green', $msgs);
        }
        if ($last !== null) {
            return new StepState('red', [__('golive.s9_failed', ['msg' => (string) $last->error])]);
        }

        return new StepState('todo', [self::approvedPreview() !== null ? __('golive.s9_note_ready') : __('golive.s9_note_locked')]);
    }

    public function view(): string
    {
        return 'gl.golive.steps.run';
    }

    public function data(): array
    {
        $approved = self::approvedPreview() !== null;
        $last = self::lastExecute();

        return [
            'note' => $approved ? __('golive.s9_note_ready') : __('golive.s9_note_locked'),
            'enabled' => $approved,
            'canRun' => self::canRun(),
            'last' => $last,
            'lastOk' => RebuildDiff::executed() ? GlRebuildRun::where('mode', 'execute')->where('status', 'ok')->latest('id')->first() : null,
            'progress' => self::progress(),
            'phases' => self::PHASES,
        ];
    }

    /** @return array{phase:string, done:int, total:int, status:string, message:string, started_at:?int, stale:bool} */
    public static function progress(): array
    {
        $idle = ['phase' => 'start', 'done' => 0, 'total' => 4, 'status' => 'idle', 'message' => '', 'started_at' => null];
        $p = self::cache()->get(self::PROGRESS_KEY);
        $p = is_array($p) ? $p + $idle : $idle;
        $p['stale'] = false;
        if ($p['status'] === 'running' && (int) ($p['started_at'] ?? 0) < now()->subMinutes(self::STALE_MINUTES)->getTimestamp()) {
            // قديم — اتأكد إن مفيش تشغيل ماسك القفل فعلاً
            $probe = self::cache()->lock(self::LOCK_KEY, 5);
            if ($probe->get()) {
                $probe->release();
                $p['stale'] = true;
            }
        }

        return $p;
    }

    private static function tick(string $phase, int $done, int $total, string $status, int $startedAt, string $message = ''): void
    {
        self::cache()->put(self::PROGRESS_KEY, ['phase' => $phase, 'done' => $done, 'total' => $total,
            'status' => $status, 'message' => $message, 'started_at' => $startedAt], 3600);
    }

    /** خطأ التشغيل + الفحوص الفاشلة بالمتوقع والفعلي (مترجمة) */
    public static function failureMessage(GlRebuildRun $run): string
    {
        $parts = [$run->error ?: __('golive.s8_'.$run->status)];
        foreach ($run->checks ?? [] as $c) {
            if (! ($c['ok'] ?? true)) {
                $parts[] = __('golive.s9_check_line', ['check' => __($c['label_key']),
                    'expected' => number_format((float) $c['expected'], 2), 'actual' => number_format((float) $c['actual'], 2)]);
            }
        }

        return implode(' — ', $parts);
    }

    /**
     * التشغيل الفعلي. بيرجّع صف التشغيل (`ok` أو `failed`) — مابيرميش إلا لو
     * التشغيل مرفوض من أوله.
     *
     * @throws \InvalidArgumentException مفيش معاينة معتمدة لسه ماتنفذتش، أو في تشغيل شغّال
     */
    public static function run(User $by): GlRebuildRun
    {
        if (! self::canRun()) {
            throw new \InvalidArgumentException(__('golive.s9_note_locked'));
        }
        $lock = self::cache()->lock(self::LOCK_KEY, 3600);
        if (! $lock->get()) {
            throw new \InvalidArgumentException(__('golive.s9_busy'));
        }

        try {
            return self::runLocked($by);
        } finally {
            $lock->release();
        }
    }

    private static function runLocked(User $by): GlRebuildRun
    {
        $preview = self::approvedPreview();
        if ($preview === null || $preview->status !== 'ok' || self::executedFor($preview->id)) {
            throw new \InvalidArgumentException(__('golive.rebuild_already_executed'));
        }
        $phase = 'start';
        $total = 4;
        $startedAt = now()->getTimestamp();
        self::tick($phase, 0, $total, 'running', $startedAt);

        try {
            Ledger::forceEnabled(true);
            try {
                $run = DB::transaction(function () use ($preview, $by, &$phase, &$total, $startedAt) {
                    // تبويبين/أدمنين: التاني يستنى هنا ويلاقيها اتنفذت
                    $locked = GlRebuildRun::whereKey($preview->id)->lockForUpdate()->first();
                    if ($locked === null || self::executedFor($locked->id)) {
                        throw new Rejected(__('golive.rebuild_already_executed'));
                    }
                    $run = app(RebuildDiff::class)->execute($locked, $by, function (int $done, int $tot, string $p) use (&$phase, &$total, $startedAt) {
                        $phase = $p;
                        $total = $tot;
                        self::tick($p, $done, $tot, 'running', $startedAt);
                    });
                    if ($run->status !== 'ok') {
                        throw new \RuntimeException(self::failureMessage($run));
                    }

                    return $run;
                });
            } finally {
                Ledger::forceEnabled(false);
            }
        } catch (Rejected $e) {
            self::tick($phase, 0, $total, 'failed', $startedAt, $e->getMessage());
            throw new \InvalidArgumentException($e->getMessage());
        } catch (\Throwable $e) {
            Setting::flushCache();
            $msg = mb_substr(__('golive.s9_phase_'.$phase).': '.$e->getMessage(), 0, 500);
            report($e);
            $failed = GlRebuildRun::create([
                'mode' => 'execute', 'from_date' => (RebuildDiff::from() ?? today())->toDateString(),
                'preview_id' => $preview->id, 'status' => 'failed', 'error' => $msg, 'created_by' => $by->id,
            ]);
            self::tick($phase, 0, $total, 'failed', $startedAt, $msg);

            return $failed;
        }

        // بعد الـcommit بس — وقبل فك القفل
        Setting::writeMany(['gl_enabled' => '1', 'gl_golive_run_at' => now()->toDateTimeString()]);
        Setting::flushCache();
        self::tick('done', $total, $total, 'ok', $startedAt, __('golive.s9_ran_ok'));

        return $run;
    }
}
