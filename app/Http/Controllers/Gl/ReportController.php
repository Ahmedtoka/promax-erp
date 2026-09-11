<?php

namespace App\Http\Controllers\Gl;

use App\Http\Controllers\Controller;
use App\Services\Gl\Reports;
use App\Support\Csv;
use App\Support\DateRange;
use Illuminate\Http\Request;

/**
 * ═══════════════════════════════════════════════════════════════
 * التقارير المالية — ميزان المراجعة وقائمة الدخل والمركز المالي
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **قراءة بس.** كل رقم هنا بيتحسب من `gl_lines` وقت العرض — مافيش
 * جدول ملخّص بيتحدّث، عشان التقرير مايفضلش عارض رقم قديم بعد تصحيح
 * قيد أو إعادة بناء.
 */
class ReportController extends Controller
{
    public function __construct(private Reports $reports)
    {
    }

    public function trialBalance(Request $request)
    {
        $range = DateRange::fromRequest($request, 'month');
        $data = $this->reports->trialBalance($range->from, $range->to);

        if ($request->boolean('export')) {
            $rows = [];
            foreach ($data['rows'] as $r) {
                $rows[] = [
                    $r['account']->code, $r['account']->displayName(),
                    Csv::money($r['opening']), Csv::money($r['debit']), Csv::money($r['credit']), Csv::money($r['closing']),
                ];
            }

            return Csv::download(
                'gl-trial-balance-'.now()->format('Y-m-d').'.csv',
                [__('gl.code'), __('gl.name'), __('gl.opening'), __('gl.debit'), __('gl.credit'), __('gl.closing')],
                $rows,
                ['', __('common.total'), '', Csv::money($data['totals']['debit']), Csv::money($data['totals']['credit']), ''],
            );
        }

        return view('gl.trial_balance', ['range' => $range, 'data' => $data]);
    }

    public function income(Request $request)
    {
        $range = DateRange::fromRequest($request, 'month');
        $from = $range->from ?? today()->startOfMonth();
        $to = $range->to ?? today();
        $data = $this->reports->income($from, $to);

        if ($request->boolean('export')) {
            $rows = [];
            foreach ($data['revenue'] as $r) {
                $rows[] = [__('gl.revenue'), $r['account']->code, $r['account']->displayName(), Csv::money($r['amount'])];
            }
            foreach ($data['expenses'] as $r) {
                $rows[] = [__('gl.expenses'), $r['account']->code, $r['account']->displayName(), Csv::money($r['amount'])];
            }

            return Csv::download(
                'gl-income-'.now()->format('Y-m-d').'.csv',
                [__('gl.type'), __('gl.code'), __('gl.name'), __('gl.amount')],
                $rows,
                ['', '', __('gl.net_income'), Csv::money($data['net'])],
            );
        }

        return view('gl.income', ['range' => $range, 'from' => $from, 'to' => $to, 'data' => $data]);
    }

    public function balanceSheet(Request $request)
    {
        // ⚠️ المركز المالي **لحظة** مش فترة — `from` مالهاش معنى هنا،
        // فبناخد `to` بس وبنسيب الفلتر خانة واحدة على الشاشة
        $range = DateRange::fromRequest($request, 'today');
        $asOf = $range->to ?? today();
        $data = $this->reports->balanceSheet($asOf);

        if ($request->boolean('export')) {
            $rows = [];
            foreach (['assets' => 'gl.assets', 'liabilities' => 'gl.liabilities', 'equity' => 'gl.equity'] as $bucket => $label) {
                foreach ($data[$bucket] as $r) {
                    $rows[] = [__($label), $r['account']->code, $r['account']->displayName(), Csv::money($r['amount'])];
                }
            }
            $rows[] = [__('gl.equity'), '', __('gl.retained_earnings'), Csv::money($data['retained'])];

            return Csv::download(
                'gl-balance-sheet-'.$asOf->format('Y-m-d').'.csv',
                [__('gl.type'), __('gl.code'), __('gl.name'), __('gl.amount')],
                $rows,
                ['', '', __('gl.assets'), Csv::money($data['total_assets'])],
            );
        }

        return view('gl.balance_sheet', ['asOf' => $asOf, 'data' => $data]);
    }
}
