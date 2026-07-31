<?php

namespace App\Http\Controllers;

use App\Exceptions\Rejected;
use App\Models\StockCount;
use App\Models\Warehouse;
use App\Services\StockCounting;
use Illuminate\Http\Request;

/**
 * الجرد الفعلي.
 *
 * ⚠️ الاعتماد بيحرّك مخزون، فهو **أدمن ومدير بس**. العد نفسه مفتوح
 * لأي حد مسجّل — أمين المخزن بيدخّل الأرقام والمسؤول بيعتمد.
 */
class StockCountController extends Controller
{
    public function index(Request $request)
    {
        $counts = StockCount::with(['warehouse', 'startedBy', 'approvedBy'])
            ->when($request->filled('warehouse'), fn ($q) => $q->where('warehouse_id', $request->input('warehouse')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return view('wh.counts', [
            'counts' => $counts,
            'warehouses' => Warehouse::where('active', true)->orderBy('code')->get(),
            'openCount' => StockCount::whereIn('status', ['draft', 'counting'])->count(),
            'filters' => [
                'warehouse' => $request->input('warehouse'),
                'status' => $request->input('status'),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'count_date' => ['nullable', 'date'],
            'include_zero' => ['nullable', 'boolean'],
        ]);

        try {
            $count = StockCounting::open(
                Warehouse::findOrFail($data['warehouse_id']),
                $request->user(),
                $data['count_date'] ?? null,
                $request->boolean('include_zero'),
            );
        } catch (Rejected $e) {
            return back()->withErrors(['warehouse_id' => $e->getMessage()]);
        }

        if ($count->lines === 0) {
            return redirect()->route('wh.count', $count)
                ->withErrors(['warehouse_id' => __('count.warehouse_empty')]);
        }

        return redirect()->route('wh.count', $count)->with('ok', __('count.opened', [
            'number' => $count->number,
            'lines' => $count->lines,
        ]));
    }

    public function show(StockCount $stockCount)
    {
        $stockCount->load(['warehouse', 'startedBy', 'approvedBy', 'items.product', 'items.batch']);

        $items = $stockCount->items->sortBy([
            fn ($a, $b) => strcmp($a->product->code ?? '', $b->product->code ?? ''),
            fn ($a, $b) => strcmp((string) $a->expiryLabel(), (string) $b->expiryLabel()),
        ])->values();

        // ⚠️ **الجرد بيكتب `qty_remaining` مطلقاً، مش بيجمع فرق.**
        // فلو فيه شحنة خرجت من المخزن (اتخصمت من الرصيد) والكراتين
        // لسه على الأرض مستنية العربية، اللي بيعدّ هيعدّها ويرجّعها
        // للرصيد — وتتعدّ في المخزنين. التحذير ده بيقوله قبل ما يعدّ.
        $openTransfers = \App\Models\StockTransfer::where('status', 'sent')
            ->where('from_warehouse_id', $stockCount->warehouse_id)
            ->count();

        return view('wh.count', [
            'openTransfers' => $openTransfers,
            'count' => $stockCount,
            'items' => $items,
            'reasons' => StockCount::REASONS,
            'pending' => $items->filter(fn ($i) => $i->notCounted())->count(),
            'diffs' => $items->filter(fn ($i) => ! $i->notCounted() && $i->difference !== 0)->count(),
        ]);
    }

    public function record(Request $request, StockCount $stockCount)
    {
        $data = $request->validate([
            'counted' => ['array'],
            'counted.*' => ['nullable', 'integer', 'min:0', 'max:9999999'],
            'reason' => ['array'],
            'reason.*' => ['nullable', 'in:'.implode(',', StockCount::REASONS)],
        ]);

        $entries = [];
        foreach ($data['counted'] ?? [] as $id => $qty) {
            $entries[$id] = [
                'counted' => $qty,
                'reason' => $data['reason'][$id] ?? null,
                'notes' => null,
            ];
        }

        try {
            $saved = StockCounting::record($stockCount, $entries);
        } catch (Rejected $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return back()->with('ok', __('count.saved', ['count' => $saved]));
    }

    public function approve(Request $request, StockCount $stockCount)
    {
        try {
            $count = StockCounting::approve($stockCount, $request->user());
        } catch (Rejected $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return back()->with('ok', __('count.approved', [
            'lines' => $count->diff_lines,
            'value' => number_format((float) $count->value_diff, 2),
        ]));
    }

    public function cancel(StockCount $stockCount)
    {
        try {
            StockCounting::cancel($stockCount);
        } catch (Rejected $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return redirect()->route('wh.counts')->with('ok', __('count.cancelled'));
    }
}
