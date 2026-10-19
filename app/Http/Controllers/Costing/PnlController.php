<?php

namespace App\Http\Controllers\Costing;

use App\Http\Controllers\Controller;
use App\Services\Costing\CostingSettings;
use App\Services\Costing\ProfitLoss;
use App\Support\Csv;
use App\Support\DateRange;
use Illuminate\Http\Request;

/**
 * قايمة الدخل الإدارية بالمستويات الخمسة (الخط ٩، مهمة ٨) — قراءة بس، محاسب
 * وأدمن (`role:admin,accountant` على المجموعة). الفترة على تاريخ قيد التكلفة
 * (افتراضي الشهر)، و`method` = batch|wavg (افتراضي الرسمية). `?export=1` = CSV
 * مسطّح بعمود `level` من نفس النتيجة.
 */
class PnlController extends Controller
{
    public function index(Request $request)
    {
        $range = DateRange::fromRequest($request, 'month');
        $m = (string) $request->query('method', '');
        $pnl = ProfitLoss::build($range, in_array($m, CostingSettings::METHODS, true) ? $m : null);

        if ($request->boolean('export')) {
            return Csv::download('costing-pnl-'.now()->format('Y-m-d-Hi').'.csv', [
                __('costing.col_level'), __('costing.col_name'), __('costing.col_revenue'), __('costing.col_cost'),
                __('costing.col_cost_alt'), __('costing.col_gross'), __('costing.col_expenses'), __('costing.col_net'),
            ], self::flat($pnl), null, Csv::meta(__('costing.pnl_title').' — '.__('costing.method_'.$pnl['method']), $pnl['from'], $pnl['to']));
        }

        return view('costing.pnl', ['pnl' => $pnl, 'range' => $range]);
    }

    /** @return list<list<string>> صف لكل مستوى بالترتيب المتداخل */
    private static function flat(array $pnl): array
    {
        $out = [];
        $line = fn (string $level, array $r) => [$level, $r['label'], Csv::money($r['revenue']), Csv::money($r['cost']),
            Csv::money($r['cost_alt']), Csv::money($r['gross']), Csv::money($r['expenses']), Csv::money($r['net'])];

        $out[] = $line('company', $pnl['company']);
        $out[] = $line('factory', $pnl['factory']);
        foreach ($pnl['factory']['children'] ?? [] as $l) {
            $out[] = $line('line', $l);
        }
        foreach ($pnl['branches'] as $b) {
            $out[] = $line('branch', $b);
            foreach ($b['children'] ?? [] as $f) {
                $out[] = $line('family', $f);
                foreach ($f['children'] ?? [] as $p) {
                    $out[] = $line('product', $p);
                }
            }
        }
        $out[] = $line('company_center', $pnl['company_center']);
        $out[] = ['elimination', __('costing.pnl_elimination'), '', '', '', '', '', Csv::money(-$pnl['elimination'])];

        return $out;
    }
}
