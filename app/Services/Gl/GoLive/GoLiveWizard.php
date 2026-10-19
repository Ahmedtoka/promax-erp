<?php

namespace App\Services\Gl\GoLive;

use App\Models\Gl\GlGoLiveStep;
use App\Models\Setting;
use App\Models\User;
use App\Services\Gl\GoLive\Steps\CoaStep;
use App\Services\Gl\GoLive\Steps\CostCentersStep;
use App\Services\Gl\GoLive\Steps\CostingPlaceholderStep;
use App\Services\Gl\GoLive\Steps\MappingStep;
use App\Services\Gl\GoLive\Steps\OpeningStep;
use App\Services\Gl\GoLive\Steps\PreviewStep;
use App\Services\Gl\GoLive\Steps\RunPlaceholderStep;
use App\Services\Gl\GoLive\Steps\SafesStep;
use App\Services\Gl\GoLive\Steps\StartDateStep;

/**
 * ═══ معالج تشغيل الدفتر — ٩ خطوات (الخط ٣، ١٩/١٠/٢٠٢٦) ═══
 *
 * ١ البداية وسياسة القفل · ٢ الشجرة · ٣ مراكز التكلفة · ٤ الخزن · ٥ الربط ·
 * ٦ الافتتاحي · ٧ التكاليف (الخط ٩) · ٨ المعاينة · ٩ التشغيل (الخط ٩).
 *
 * مكمّل من مكان ما وقف: `gl_golive_current` + `gl_golive_steps`.
 */
class GoLiveWizard
{
    /** @var array<int, Step> استبدالات الخطوط التانية بالرقم */
    private static array $registered = [];

    public static function register(Step $step): void
    {
        self::$registered[$step->number()] = $step;
    }

    /** للتيستات: يرجّع الخطوات الأصلية */
    public static function forget(int $number): void
    {
        unset(self::$registered[$number]);
    }

    /** @return array<int, Step> مترتبة ١..٩ */
    public static function steps(): array
    {
        $steps = [];
        foreach ([
            new StartDateStep, new CoaStep, new CostCentersStep, new SafesStep, new MappingStep,
            new OpeningStep, new CostingPlaceholderStep, new PreviewStep, new RunPlaceholderStep,
        ] as $s) {
            $steps[$s->number()] = self::$registered[$s->number()] ?? $s;
        }
        foreach (self::$registered as $n => $s) {
            $steps[$n] ??= $s;
        }
        ksort($steps);

        return $steps;
    }

    public static function step(int $n): Step
    {
        $steps = self::steps();
        if (! isset($steps[$n])) {
            throw new \OutOfRangeException('No go-live step '.$n);
        }

        return $steps[$n];
    }

    public static function current(): int
    {
        $n = (int) (Setting::read('gl_golive_current') ?: 1);

        return isset(self::steps()[$n]) ? $n : 1;
    }

    /** @throws \InvalidArgumentException الخطوة فيها أحمر */
    public static function confirm(int $n, User $by): void
    {
        $step = self::step($n);
        $state = $step->state();
        if ($state->blocks()) {
            throw new \InvalidArgumentException(__('golive.confirm_blocked', ['msg' => implode(' · ', $state->messages)]));
        }
        $row = GlGoLiveStep::for($step->key());
        $row->forceFill(['status' => 'done', 'done_at' => now(), 'done_by' => $by->id])->save();

        $next = collect(array_keys(self::steps()))->first(fn ($k) => $k > $n);
        if ($next !== null && self::current() <= $n) {
            Setting::write('gl_golive_current', (string) $next);
        }
    }

    public static function reopen(int $n): void
    {
        $step = self::step($n);
        GlGoLiveStep::for($step->key())->forceFill(['status' => 'todo', 'done_at' => null, 'done_by' => null])->save();
        Setting::write('gl_golive_current', (string) $n);
    }

    public static function done(Step $step): bool
    {
        return GlGoLiveStep::where('key', $step->key())->value('status') === 'done';
    }
}
