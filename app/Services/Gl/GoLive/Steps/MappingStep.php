<?php

namespace App\Services\Gl\GoLive\Steps;

use App\Services\Gl\GlMapping;
use App\Services\Gl\GoLive\Step;
use App\Services\Gl\GoLive\StepState;

/** ٥ — الربط: أي مفتاح/خانة مش مظبوطة = أحمر (الشاشة نفسها `gl.mapping`) */
class MappingStep implements Step
{
    public function number(): int
    {
        return 5;
    }

    public function key(): string
    {
        return 'mapping';
    }

    public function titleKey(): string
    {
        return 'golive.s5_title';
    }

    public function state(): StepState
    {
        $problems = GlMapping::problems();

        return $problems === [] ? new StepState('green') : new StepState('red', array_slice($problems, 0, 10));
    }

    public function view(): string
    {
        return 'gl.golive.steps.mapping';
    }

    public function data(): array
    {
        $keys = GlMapping::keys();

        return [
            'problems' => GlMapping::problems(),
            'keysOk' => collect($keys)->where('ok', true)->count(),
            'keysTotal' => count($keys),
        ];
    }
}
