<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\Models\Product;
use App\Models\RawStockMove;
use App\Models\Warehouse;
use App\Support\Csv;
use App\Support\DateRange;
use Illuminate\Http\Request;

/**
 * مخزن الخامات والتعبئة: باتشات FEFO وسجل الصرف.
 * Raw/packaging store screen: FEFO batches + issue log. Read-only.
 */
class RawStoreController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $keeper = $user?->isWarehouseKeeper() && $user->warehouse_id;

        // أمين المخزن مقفول على مخزنه زي باقي شاشات `wh.`
        $warehouseId = $keeper ? (int) $user->warehouse_id : $request->integer('warehouse');
        $warehouses = Warehouse::where('active', true)
            ->when($keeper, fn ($q) => $q->where('id', $user->warehouse_id))
            ->orderBy('type')->get();

        $tab = $request->query('tab') === 'moves' ? 'moves' : 'batches';
        $kind = in_array($request->query('kind'), ['raw', 'packaging'], true) ? $request->query('kind') : '';
        $search = trim($request->string('q')->value());
        $range = DateRange::fromRequest($request);

        $productFilter = function ($q) use ($kind, $search) {
            $q->whereIn('kind', $kind ? [$kind] : ['raw', 'packaging'])
                ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('name_en', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")));
        };

        $data = compact('tab', 'kind', 'search', 'range', 'warehouses', 'warehouseId');

        if ($tab === 'batches') {
            $rows = Batch::query()
                ->where('qty_remaining', '>', 0)
                ->whereHas('product', $productFilter)
                ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
                ->tap(fn ($q) => $range->apply($q, 'expires_on'))
                ->with(['product', 'warehouse'])
                ->orderBy('expires_on')->orderBy('id')
                ->get();

            $kpi = [
                'lots' => $rows->count(),
                'qty' => (int) $rows->sum('qty_remaining'),
                'value' => (float) $rows->sum(fn (Batch $b) => $b->qty_remaining * (float) $b->cost),
            ];

            if ($request->boolean('export')) {
                return Csv::download(
                    'raw-store-batches-'.now()->format('Y-m-d').'.csv',
                    [__('stock.item'), __('stock.raw_kind'), __('stock.warehouse'), __('stock.raw_lot'), __('stock.expires_on'), __('stock.raw_qty'), __('stock.raw_unit_cost'), __('stock.raw_value')],
                    $rows->map(fn (Batch $b) => [
                        $b->product?->displayName(),
                        $b->product?->kindLabel(),
                        $b->warehouse?->displayName(),
                        $b->batch_no,
                        $b->expires_on?->toDateString(),
                        $b->qty_remaining,
                        number_format((float) $b->cost, 2, '.', ''),
                        number_format($b->qty_remaining * (float) $b->cost, 2, '.', ''),
                    ]),
                    [__('common.total'), '', '', '', '', $kpi['qty'], '', number_format($kpi['value'], 2, '.', '')],
                    Csv::meta(__('stock.raw_tab_batches'), $range->fromValue() ?: null, $range->toValue() ?: null),
                );
            }

            return view('wh.raw', $data + ['rows' => $rows, 'kpi' => $kpi]);
        }

        $moves = RawStockMove::query()
            ->whereHas('product', $productFilter)
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->tap(fn ($q) => $range->apply($q, 'moved_at'))
            ->with(['product', 'warehouse', 'batch', 'for', 'creator'])
            ->orderByDesc('moved_at')->orderByDesc('id')
            ->get();

        $forLabel = function (RawStockMove $m): string {
            $doc = $m->for;
            if ($doc === null) {
                return $m->for_type ? class_basename($m->for_type).' #'.$m->for_id : '—';
            }

            return class_basename($doc).' '.($doc->getAttribute('number') ? $doc->getAttribute('number') : '#'.$doc->getKey());
        };

        if ($request->boolean('export')) {
            return Csv::download(
                'raw-store-moves-'.now()->format('Y-m-d').'.csv',
                [__('stock.raw_moved_at'), __('stock.item'), __('stock.warehouse'), __('stock.raw_lot'), __('stock.raw_qty'), __('stock.raw_unit_cost'), __('stock.raw_for'), __('stock.raw_by')],
                $moves->map(fn (RawStockMove $m) => [
                    $m->moved_at?->format('Y-m-d H:i'),
                    $m->product?->displayName(),
                    $m->warehouse?->displayName(),
                    $m->batch?->batch_no,
                    $m->qty,
                    number_format((float) $m->unit_cost, 2, '.', ''),
                    $forLabel($m),
                    $m->creator?->name,
                ]),
                null,
                Csv::meta(__('stock.raw_tab_moves'), $range->fromValue() ?: null, $range->toValue() ?: null),
            );
        }

        return view('wh.raw', $data + ['moves' => $moves, 'forLabel' => $forLabel]);
    }
}
