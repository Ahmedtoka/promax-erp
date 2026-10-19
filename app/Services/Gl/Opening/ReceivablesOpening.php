<?php

namespace App\Services\Gl\Opening;

use App\Contracts\Gl\OpeningProvider;
use App\Models\Gl\GlAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * سطر العملاء في قيد الافتتاح = صافي `transactions` لحد يوم قبل البداية
 * (من غير الأمانة) — نفس معادلة الفحص الثابت بس على الفترة اللي قبله،
 * فالفحص (اللي بيعدّ من البداية) مايتأثرش بالقيد ده خالص.
 */
class ReceivablesOpening implements OpeningProvider
{
    public function key(): string
    {
        return 'receivables';
    }

    public function label(): string
    {
        return __('golive.opening_src_receivables');
    }

    public function lines(Carbon $asOf): array
    {
        $net = round((float) DB::table('transactions')->whereDate('date', '<=', $asOf->toDateString())
            ->where('kind', '!=', 'consignment')
            ->selectRaw('COALESCE(SUM(debit - credit),0) v')->value('v'), 2);
        if (abs($net) < 0.005) {
            return [];
        }

        return [[
            'account_id' => GlAccount::findKey('receivables')->id,
            'cost_center_id' => null,
            'debit' => $net > 0 ? $net : 0.0,
            'credit' => $net < 0 ? -$net : 0.0,
            'memo' => $this->label(),
        ]];
    }
}
