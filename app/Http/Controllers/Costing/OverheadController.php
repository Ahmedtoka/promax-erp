<?php

namespace App\Http\Controllers\Costing;

use App\Exceptions\Rejected;
use App\Http\Controllers\Controller;
use App\Models\CostMonth;
use App\Models\ProductionOrder;
use App\Services\Costing\CostMonths;
use App\Services\Costing\CostSync;
use App\Services\Costing\Overhead;
use App\Support\Csv;
use App\Support\DateRange;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * شاشة التكاليف غير المباشرة بالشهر (الخط ٩، مهمة ٦).
 *
 * أحواض الخطوط من `Overhead::pools` (بتتحسب من المستندات، فشغالة حتى والشهر
 * مفتوح)، وجدول الأوامر المقفولة بفلتر «من — إلى» على `closed_at` والتصدير
 * منه. قفل الشهر وإعادة الحساب أدمن بس (الراوت + `act.costing.admin`).
 */
class OverheadController extends Controller
{
    public function index(Request $request)
    {
        $month = $this->month($request->query('month'));
        $range = DateRange::fromRequest($request, 'month');

        $orders = ProductionOrder::query()
            ->with(['product', 'line'])
            ->where('status', 'closed')
            ->tap(fn ($q) => $range->apply($q, 'closed_at'))
            ->orderByDesc('closed_at')->orderByDesc('id')
            ->get();

        if ($request->boolean('export')) {
            return Csv::download('costing-overhead-'.now()->format('Y-m-d-Hi').'.csv', [
                __('costing.col_order'), __('costing.col_product'), __('costing.col_line'), __('costing.col_method'),
                __('costing.col_flags'), __('costing.col_kg'), __('costing.col_order_rate'), __('costing.col_overhead'),
                __('costing.col_unit_cost'), __('costing.col_closed_at'),
            ], $orders->map(fn (ProductionOrder $o) => [
                $o->number, $o->product?->displayName() ?? '', $o->line ? $this->centerName($o->line) : '',
                $o->overhead_method ? __('costing.oh_method_'.$o->overhead_method) : '',
                collect($this->flags($o))->map(fn ($f) => __('costing.flag_'.$f))->implode(' / '),
                $o->overhead_kg, $o->overhead_rate, Csv::money($o->overhead_cost),
                Csv::money($o->unit_cost), $o->closed_at?->format('Y-m-d') ?? '',
            ]), [
                __('common.total'), '', '', '', '', '', '', Csv::money($orders->sum('overhead_cost')), '', '',
            ], Csv::meta(__('costing.orders_title'), $range->fromValue() ?: null, $range->toValue() ?: null));
        }

        $pools = Overhead::pools($month);
        $unshared = (float) ($pools['factory_unshared'] ?? 0);
        unset($pools['factory_unshared']);

        $lines = [];
        foreach ($pools as $r) {
            $lines[] = $r + [
                'estimate' => Overhead::estimateRate($r['line'], $month),
                'variance' => round($r['pool'] - $r['applied'], 2),
            ];
        }

        return view('costing.overhead', [
            'month' => $month,
            'range' => $range,
            'lines' => $lines,
            'unshared' => $unshared,
            'orders' => $orders,
            'closedMonths' => CostMonth::with('closer')->where('status', 'closed')->orderByDesc('period')->limit(24)->get(),
            'thisMonthClosed' => CostMonth::where('period', $month->format('Y-m'))->where('status', 'closed')->exists(),
            'flagsOf' => fn (ProductionOrder $o) => $this->flags($o),
            'centerName' => fn ($c) => $this->centerName($c),
        ]);
    }

    public function close(Request $request)
    {
        $data = $request->validate(['period' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);
        try {
            CostMonths::close($data['period'], $request->user());
        } catch (Rejected $e) {
            return back()->withErrors(['period' => $e->getMessage()]);
        }

        return redirect()->route('costing.overhead', ['month' => $data['period']])
            ->with('ok', __('costing.month_closed_ok', ['period' => $data['period']]));
    }

    public function sync(Request $request)
    {
        $data = $request->validate(['from' => ['nullable', 'date']]);
        $from = ! empty($data['from'])
            ? Carbon::parse($data['from'])->startOfDay()
            : today()->subMonth()->startOfMonth();
        try {
            $r = CostSync::run($from, post: true);
        } catch (Rejected $e) {
            return back()->withErrors(['from' => $e->getMessage()]);
        }

        return back()->with('ok', __('costing.sync_done', [
            'synced' => (int) ($r['synced'] ?? 0), 'deleted' => (int) ($r['deleted'] ?? 0),
        ]));
    }

    /** `?month=Y-m` صالح وإلا الشهر الحالي */
    private function month(mixed $v): Carbon
    {
        if (is_string($v) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $v)) {
            return Carbon::createFromFormat('Y-m-d', $v.'-01')->startOfDay();
        }

        return today()->startOfMonth();
    }

    /** @return list<string> */
    private function flags(ProductionOrder $o): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $o->overhead_flag)),
            fn ($f) => in_array($f, Overhead::FLAGS, true)));
    }

    private function centerName($c): string
    {
        return (app()->getLocale() === 'en' && $c->name_en) ? $c->name_en : $c->name;
    }
}
