<?php

namespace App\Services\Gl\GoLive\Steps;

use App\Services\Gl\GoLive\Step;
use App\Services\Gl\GoLive\StepState;

/** ٧ — مكان إعدادات التكاليف. الخط ٩ بيستبدلها بـ`GoLiveWizard::register()` */
class CostingPlaceholderStep implements Step
{
    public function number(): int
    {
        return 7;
    }

    public function key(): string
    {
        return 'costing';
    }

    public function titleKey(): string
    {
        return 'golive.s7_title';
    }

    public function state(): StepState
    {
        return new StepState('todo', [__('golive.placeholder_lane9')]);
    }

    public function view(): string
    {
        return 'gl.golive.steps.placeholder';
    }

    public function data(): array
    {
        return ['note' => __('golive.s7_note')];
    }
}
