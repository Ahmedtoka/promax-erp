<?php

namespace App\Services\Gl\GoLive\Steps;

use App\Models\CostCenter;
use App\Services\Gl\GoLive\Step;
use App\Services\Gl\GoLive\StepState;

/** ٣ — مراكز التكلفة: الشركة والمعادي إجباري، والمصنع من غير خطوط تنبيه */
class CostCentersStep implements Step
{
    public function number(): int
    {
        return 3;
    }

    public function key(): string
    {
        return 'cost_centers';
    }

    public function titleKey(): string
    {
        return 'golive.s3_title';
    }

    public function state(): StepState
    {
        $codes = CostCenter::whereIn('code', [CostCenter::CODE_COMPANY, CostCenter::CODE_MAADI])->pluck('code')->all();
        $red = [];
        if (! in_array(CostCenter::CODE_COMPANY, $codes, true)) {
            $red[] = __('golive.s3_no_company');
        }
        if (! in_array(CostCenter::CODE_MAADI, $codes, true)) {
            $red[] = __('golive.s3_no_maadi');
        }
        if ($red !== []) {
            return new StepState('red', $red);
        }

        $factories = CostCenter::where('kind', 'factory')->pluck('id');
        $withLines = CostCenter::where('kind', 'line')->whereIn('parent_id', $factories)->distinct()->pluck('parent_id');
        if ($factories->isEmpty() || $withLines->count() < $factories->count()) {
            return new StepState('yellow', [__('golive.s3_factory_no_lines')]);
        }

        return new StepState('green');
    }

    public function view(): string
    {
        return 'gl.golive.steps.cost_centers';
    }

    public function data(): array
    {
        $all = CostCenter::orderBy('code')->get();
        $children = $all->groupBy(fn ($c) => (int) $c->parent_id);
        $tree = [];
        // حارس حلقة: مركز بيشاور على حفيده مايعلّقش الصفحة
        $walk = function (int $parent, int $depth, array $seen) use (&$walk, &$tree, $children) {
            foreach ($children->get($parent, collect()) as $c) {
                if (isset($seen[$c->id])) {
                    continue;
                }
                $tree[] = ['center' => $c, 'depth' => $depth];
                $walk($c->id, $depth + 1, $seen + [$c->id => true]);
            }
        };
        $walk(0, 0, []);

        return ['tree' => $tree, 'parents' => $all->whereIn('kind', ['company', 'factory'])->values()];
    }
}
