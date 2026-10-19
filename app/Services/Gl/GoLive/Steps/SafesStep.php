<?php

namespace App\Services\Gl\GoLive\Steps;

use App\Models\Gl\GlAccount;
use App\Models\Safe;
use App\Services\Gl\CoaAdopter;
use App\Services\Gl\GlMapping;
use App\Services\Gl\GoLive\Step;
use App\Services\Gl\GoLive\StepState;

/** ٤ — الخزن والبنوك: كل خزنة نشطة على حساب، وعهد المناديب مجموعة سليمة */
class SafesStep implements Step
{
    public function number(): int
    {
        return 4;
    }

    public function key(): string
    {
        return 'safes';
    }

    public function titleKey(): string
    {
        return 'golive.s4_title';
    }

    public function state(): StepState
    {
        $red = [];
        $unlinked = Safe::where('active', true)->whereNull('gl_account_id')->orderBy('code')->pluck('code')->all();
        if ($unlinked !== []) {
            $red[] = __('golive.s4_unlinked', ['list' => implode(' · ', $unlinked)]);
        }
        $rep = collect(GlMapping::keys())->firstWhere('key', 'rep_cash');
        if (! ($rep['ok'] ?? false)) {
            $red[] = __('golive.s4_rep_cash', ['why' => $rep['why'] ?? '']);
        }

        return $red === [] ? new StepState('green') : new StepState('red', $red);
    }

    public function view(): string
    {
        return 'gl.golive.steps.safes';
    }

    public function data(): array
    {
        $q = GlAccount::where('active', true)->where('is_postable', true)->whereNull('user_id')->where('type', 'asset');
        if (CoaAdopter::adopted()) {
            $q->whereNotNull('coa_draft_id');
        }

        return [
            'safes' => Safe::with(['costCenter', 'glAccount'])->orderBy('code')->get(),
            'accounts' => $q->orderBy('code')->get(),
            'repAccounts' => GlAccount::whereNotNull('user_id')->count(),
            'repCash' => collect(GlMapping::keys())->firstWhere('key', 'rep_cash'),
        ];
    }
}
