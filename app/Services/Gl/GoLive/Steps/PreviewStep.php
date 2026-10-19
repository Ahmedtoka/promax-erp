<?php

namespace App\Services\Gl\GoLive\Steps;

use App\Models\Gl\GlRebuildRun;
use App\Services\Gl\GoLive\Step;
use App\Services\Gl\GoLive\StepState;

/** ٨ — معاينة إعادة البناء بالفروق والفحوص، والموافقة عليها (D8/D9) */
class PreviewStep implements Step
{
    public function number(): int
    {
        return 8;
    }

    public function key(): string
    {
        return 'preview';
    }

    public function titleKey(): string
    {
        return 'golive.s8_title';
    }

    public function last(): ?GlRebuildRun
    {
        return GlRebuildRun::where('mode', 'preview')->latest('id')->first();
    }

    public function state(): StepState
    {
        $run = $this->last();
        if ($run === null) {
            return new StepState('todo', [__('golive.s8_none')]);
        }
        if ($run->status !== 'ok') {
            return new StepState('red', [__('golive.s8_'.$run->status).($run->error ? ' — '.$run->error : '')]);
        }
        $bad = collect($run->checks ?? [])->where('ok', false);
        if ($bad->isNotEmpty()) {
            return new StepState('red', $bad->map(fn ($c) => __('golive.s8_check_failed', ['check' => __($c['label_key'])]))->values()->all());
        }
        if ($run->approved_at === null) {
            return new StepState('yellow', [__('golive.s8_not_approved')]);
        }

        return new StepState('green');
    }

    public function view(): string
    {
        return 'gl.golive.steps.preview';
    }

    public function data(): array
    {
        $run = $this->last();
        $fresh = $run !== null && $run->created_at !== null && $run->created_at->gte(now()->subDay());
        $checksOk = $run !== null && collect($run->checks ?? [])->every(fn ($c) => $c['ok']);

        return [
            'run' => $run,
            'canApprove' => $run !== null && $run->status === 'ok' && $run->approved_at === null && $fresh && $checksOk,
        ];
    }
}
