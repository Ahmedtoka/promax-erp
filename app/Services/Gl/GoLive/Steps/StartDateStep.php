<?php

namespace App\Services\Gl\GoLive\Steps;

use App\Models\Gl\GlPeriod;
use App\Models\Setting;
use App\Services\Gl\GoLive\Step;
use App\Services\Gl\GoLive\StepState;

/** ١ — تاريخ البداية وسياسة قفل الشهور (D11) */
class StartDateStep implements Step
{
    public function number(): int
    {
        return 1;
    }

    public function key(): string
    {
        return 'start';
    }

    public function titleKey(): string
    {
        return 'golive.s1_title';
    }

    public function state(): StepState
    {
        if (! Setting::read('gl_start_date')) {
            return new StepState('red', [__('golive.s1_no_start')]);
        }
        if (! in_array(Setting::read('gl_close_policy'), ['manual', 'auto'], true)) {
            return new StepState('yellow', [__('golive.s1_no_policy')]);
        }

        return new StepState('green');
    }

    public function view(): string
    {
        return 'gl.golive.steps.start';
    }

    public function data(): array
    {
        return [
            'start' => Setting::read('gl_start_date'),
            'policy' => Setting::read('gl_close_policy'),
            'afterDays' => (int) (Setting::read('gl_close_after_days') ?: 10),
            'closed' => GlPeriod::where('status', 'closed')->orderBy('key')->pluck('key')->all(),
        ];
    }
}
