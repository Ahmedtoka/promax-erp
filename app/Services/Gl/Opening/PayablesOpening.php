<?php

namespace App\Services\Gl\Opening;

use App\Contracts\Gl\OpeningProvider;
use App\Models\Gl\GlAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** سطر الموردين = صافي `supplier_transactions` (دائن − مدين) لحد يوم قبل البداية */
class PayablesOpening implements OpeningProvider
{
    public function key(): string
    {
        return 'payables';
    }

    public function label(): string
    {
        return __('golive.opening_src_payables');
    }

    public function lines(Carbon $asOf): array
    {
        $net = round((float) DB::table('supplier_transactions')->whereDate('date', '<=', $asOf->toDateString())
            ->selectRaw('COALESCE(SUM(credit - debit),0) v')->value('v'), 2);
        if (abs($net) < 0.005) {
            return [];
        }

        return [[
            'account_id' => GlAccount::findKey('payables')->id,
            'cost_center_id' => null,
            'debit' => $net < 0 ? -$net : 0.0,
            'credit' => $net > 0 ? $net : 0.0,
            'memo' => $this->label(),
        ]];
    }
}
