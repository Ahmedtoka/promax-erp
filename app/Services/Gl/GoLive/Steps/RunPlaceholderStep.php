<?php

namespace App\Services\Gl\GoLive\Steps;

use App\Models\Gl\GlRebuildRun;
use App\Services\Gl\GoLive\Step;
use App\Services\Gl\GoLive\StepState;

/** ٩ — مكان التشغيل الفعلي. الخط ٩ بيستبدلها؛ لحد ساعتها مقفولة لحد ما المعاينة تتوافق */
class RunPlaceholderStep implements Step
{
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
        $approved = GlRebuildRun::where('mode', 'preview')->latest('id')->value('approved_at') !== null;

        return ['note' => $approved ? __('golive.s9_note_ready') : __('golive.s9_note_locked'), 'enabled' => $approved];
    }
}
