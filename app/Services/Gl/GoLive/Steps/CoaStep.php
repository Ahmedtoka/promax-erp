<?php

namespace App\Services\Gl\GoLive\Steps;

use App\Models\Setting;
use App\Services\Gl\CoaAdopter;
use App\Services\Gl\CoaChecks;
use App\Services\Gl\GoLive\Step;
use App\Services\Gl\GoLive\StepState;

/** ٢ — شجرة المحاسب: فحوص أحمر/أصفر + الاعتماد (الشاشة نفسها `gl.coa`) */
class CoaStep implements Step
{
    private ?array $checks = null;

    public function number(): int
    {
        return 2;
    }

    public function key(): string
    {
        return 'coa';
    }

    public function titleKey(): string
    {
        return 'golive.s2_title';
    }

    private function checks(): array
    {
        return $this->checks ??= CoaChecks::run();
    }

    public function state(): StepState
    {
        $c = $this->checks();
        $msgs = [];
        if ($c['red'] > 0) {
            $msgs[] = __('golive.s2_red', ['n' => $c['red']]);
        }
        if (! CoaAdopter::adopted()) {
            $msgs[] = __('golive.s2_not_adopted');
        }
        if ($msgs !== []) {
            return new StepState('red', $msgs);
        }
        if ($c['yellow'] > 0) {
            return new StepState('yellow', [__('golive.s2_yellow', ['n' => $c['yellow']])]);
        }

        return new StepState('green');
    }

    public function view(): string
    {
        return 'gl.golive.steps.coa';
    }

    public function data(): array
    {
        $c = $this->checks();

        return [
            'red' => $c['red'],
            'yellow' => $c['yellow'],
            'accepted' => $c['accepted'],
            'adopted' => CoaAdopter::adopted(),
            'adoptedAt' => Setting::read('gl_coa_adopted_at'),
        ];
    }
}
