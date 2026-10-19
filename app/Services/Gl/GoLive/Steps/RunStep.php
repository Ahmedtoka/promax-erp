<?php

namespace App\Services\Gl\GoLive\Steps;

use App\Models\Gl\GlRebuildRun;
use App\Models\Setting;
use App\Models\User;
use App\Services\Gl\GoLive\Step;
use App\Services\Gl\GoLive\StepState;
use App\Services\Gl\RebuildDiff;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * ٩ — التشغيل الفعلي (الخط ٩، المهمة ٩ — D13 · §3.1.1).
 *
 * ترانزاكشن واحد بره: سويتش الدفتر = 1 → `RebuildDiff::execute` على آخر
 * معاينة اتوافق عليها (كل المستندات والتكلفة والإنتاج والتحميل والبيع
 * الداخلي والإهلاك والمرتبات من تاريخ البداية) → الحالة لازم `ok` →
 * `gl_golive_run_at`. أي فشل = rollback لكل حاجة، وبعده (بره الترانزاكشن)
 * صف تشغيل `failed` رسالته «المرحلة: السبب» عشان الخطوة تبان حمرا بالسبب.
 *
 * التقدّم في كاش **الملفات** (`golive_run_progress`) مش الكاش الافتراضي:
 * لو الافتراضي `database` الكتابة هتبقى جوه الترانزاكشن ومحدش هيشوفها
 * لحد الـcommit (ولا بعد الـrollback).
 */
class RunStep implements Step
{
    public const PROGRESS_KEY = 'golive_run_progress';

    public const PHASES = ['start', 'snapshot', 'rebuild', 'diff', 'checks', 'done'];

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

    /** المعاينة المعتمدة لسه ماتنفذتش بنجاح */
    public static function canRun(): bool
    {
        $p = self::approvedPreview();

        return $p !== null && $p->status === 'ok'
            && ! GlRebuildRun::where('mode', 'execute')->where('preview_id', $p->id)->where('status', 'ok')->exists();
    }

    public function state(): StepState
    {
        $last = self::lastExecute();
        if ($last !== null && $last->status === 'ok') {
            return new StepState('green', [__('golive.s9_done_at', ['at' => $last->created_at?->format('Y-m-d H:i')])]);
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

        return [
            'note' => $approved ? __('golive.s9_note_ready') : __('golive.s9_note_locked'),
            'enabled' => $approved,
            'canRun' => self::canRun(),
            'last' => self::lastExecute(),
            'progress' => self::progress(),
            'phases' => self::PHASES,
        ];
    }

    /** @return array{phase:string, done:int, total:int, status:string, message:string} */
    public static function progress(): array
    {
        $p = Cache::store('file')->get(self::PROGRESS_KEY);

        return is_array($p) ? $p + ['phase' => 'start', 'done' => 0, 'total' => 4, 'status' => 'idle', 'message' => '']
            : ['phase' => 'start', 'done' => 0, 'total' => 4, 'status' => 'idle', 'message' => ''];
    }

    private static function tick(string $phase, int $done, int $total, string $status, string $message = ''): void
    {
        Cache::store('file')->put(self::PROGRESS_KEY,
            ['phase' => $phase, 'done' => $done, 'total' => $total, 'status' => $status, 'message' => $message], 3600);
    }

    /**
     * التشغيل الفعلي. بيرجّع صف التشغيل (`ok` أو `failed`) — مابيرميش.
     *
     * @throws \InvalidArgumentException مفيش معاينة معتمدة لسه ماتنفذتش
     */
    public static function run(User $by): GlRebuildRun
    {
        if (! self::canRun()) {
            throw new \InvalidArgumentException(__('golive.s9_note_locked'));
        }
        $preview = self::approvedPreview();
        $phase = 'start';
        $total = 4;
        self::tick($phase, 0, $total, 'running');

        try {
            $run = DB::transaction(function () use ($preview, $by, &$phase, &$total) {
                Setting::write('gl_enabled', '1');
                $run = app(RebuildDiff::class)->execute($preview, $by, function (int $done, int $tot, string $p) use (&$phase, &$total) {
                    $phase = $p;
                    $total = $tot;
                    self::tick($p, $done, $tot, 'running');
                });
                if ($run->status !== 'ok') {
                    throw new \RuntimeException($run->error ?: __('golive.s8_'.$run->status));
                }
                Setting::write('gl_golive_run_at', now()->toDateTimeString());

                return $run;
            });
        } catch (\Throwable $e) {
            // الـrollback رجّع السويتش في الداتابيز — الكاش لازم يتمسح عشان يتقري تاني
            Setting::flushCache();
            $msg = mb_substr(__('golive.s9_phase_'.$phase).': '.$e->getMessage(), 0, 500);
            report($e);
            $failed = GlRebuildRun::create([
                'mode' => 'execute', 'from_date' => (RebuildDiff::from() ?? today())->toDateString(),
                'preview_id' => $preview->id, 'status' => 'failed', 'error' => $msg, 'created_by' => $by->id,
            ]);
            self::tick($phase, 0, $total, 'failed', $msg);

            return $failed;
        }

        Setting::flushCache();
        self::tick('done', $total, $total, 'ok', __('golive.s9_ran_ok'));

        return $run;
    }
}
