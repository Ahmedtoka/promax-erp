<?php

namespace App\Http\Controllers\Gl;

use App\Http\Controllers\Controller;
use App\Models\CostCenter;
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

    /**
     * فلتر مركز التكلفة (الخط ٣): id سليم أو null — قيمة مش موجودة بتتجاهل
     * بدل 500/422 على شاشة قراءة.
     *
     * @return array{0: ?CostCenter, 1: \Illuminate\Support\Collection}
     */
    private function center(Request $request): array
    {
        $centers = CostCenter::orderBy('code')->get();
        $id = (int) $request->query('cost_center_id', 0);

        return [$id > 0 ? $centers->firstWhere('id', $id) : null, $centers];
    }

    private function file(string $base, ?CostCenter $cc, string $date): string
    {
        return $base.($cc ? '-'.preg_replace('/[^A-Za-z0-9_-]+/', '', $cc->code) : '').'-'.$date.'.csv';
    }

    public function trialBalance(Request $request)
    {
        $range = DateRange::fromRequest($request, 'month');
        [$cc, $centers] = $this->center($request);
        $data = $this->reports->trialBalance($range->from, $range->to, $cc?->id);

        if ($request->boolean('export')) {
            $rows = [];
            foreach ($data['rows'] as $r) {
                $rows[] = [
                    $r['account']->code, $r['account']->displayName(),
                    Csv::money($r['opening']), Csv::money($r['debit']), Csv::money($r['credit']), Csv::money($r['closing']),
                ];
            }

            return Csv::download(
                $this->file('gl-trial-balance', $cc, now()->format('Y-m-d')),
                [__('gl.code'), __('gl.name'), __('gl.opening'), __('gl.debit'), __('gl.credit'), __('gl.closing')],
                $rows,
                ['', __('common.total'), '', Csv::money($data['totals']['debit']), Csv::money($data['totals']['credit']), ''],
            );
        }

        return view('gl.trial_balance', ['range' => $range, 'data' => $data, 'centers' => $centers, 'centerId' => $cc?->id]);
    }

    public function income(Request $request)
    {
        $range = DateRange::fromRequest($request, 'month');
        $from = $range->from ?? today()->startOfMonth();
        $to = $range->to ?? today();
        [$cc, $centers] = $this->center($request);
        $data = $this->reports->income($from, $to, $cc?->id);

        if ($request->boolean('export')) {
            $rows = [];
            foreach ($data['revenue'] as $r) {
                $rows[] = [__('gl.revenue'), $r['account']->code, $r['account']->displayName(), Csv::money($r['amount'])];
            }
            foreach ($data['expenses'] as $r) {
                $rows[] = [__('gl.expenses'), $r['account']->code, $r['account']->displayName(), Csv::money($r['amount'])];
            }

            return Csv::download(
                $this->file('gl-income', $cc, now()->format('Y-m-d')),
                [__('gl.type'), __('gl.code'), __('gl.name'), __('gl.amount')],
                $rows,
                ['', '', __('gl.net_income'), Csv::money($data['net'])],
            );
        }

        return view('gl.income', ['range' => $range, 'from' => $from, 'to' => $to, 'data' => $data, 'centers' => $centers, 'centerId' => $cc?->id]);
    }

    public function balanceSheet(Request $request)
    {
        // ⚠️ المركز المالي **لحظة** مش فترة — `from` مالهاش معنى هنا،
        // فبناخد `to` بس وبنسيب الفلتر خانة واحدة على الشاشة
        $range = DateRange::fromRequest($request, 'today');
        $asOf = $range->to ?? today();
        [$cc, $centers] = $this->center($request);
        $data = $this->reports->balanceSheet($asOf, $cc?->id);

        if ($request->boolean('export')) {
            $rows = [];
            foreach (['assets' => 'gl.assets', 'liabilities' => 'gl.liabilities', 'equity' => 'gl.equity'] as $bucket => $label) {
                foreach ($data[$bucket] as $r) {
                    $rows[] = [__($label), $r['account']->code, $r['account']->displayName(), Csv::money($r['amount'])];
                }
            }
            $rows[] = [__('gl.equity'), '', __('gl.retained_earnings'), Csv::money($data['retained'])];

            return Csv::download(
                $this->file('gl-balance-sheet', $cc, $asOf->format('Y-m-d')),
                [__('gl.type'), __('gl.code'), __('gl.name'), __('gl.amount')],
                $rows,
                ['', '', __('gl.assets'), Csv::money($data['total_assets'])],
            );
        }

        return view('gl.balance_sheet', ['asOf' => $asOf, 'data' => $data, 'centers' => $centers, 'centerId' => $cc?->id]);
    }
}
