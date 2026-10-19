<?php

namespace App\Http\Controllers\Costing;

use App\Exceptions\Rejected;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\ProductTransferPrice;
use App\Services\Costing\CostCard;
use App\Services\Costing\TransferPrice;
use App\Support\Csv;
use App\Support\DateRange;
use Illuminate\Http\Request;

/**
 * كارت التكلفة وسعر التحويل المقترح (الخط ٩، مهمة ٧). القراءة محاسب وأدمن،
 * والاعتماد أدمن بس (الراوت + `act.costing.admin`). تبويب `?tab=history` =
 * سجل اعتمادات سعر التحويل بفلتر «من — إلى» على `approved_at`.
 */
class CardController extends Controller
{
    public function index(Request $request)
    {
        if ($request->query('tab') === 'history') {
            return $this->history($request);
        }

        $status = (string) $request->query('status', '');
        $family = (string) $request->query('family', '');
        $all = CostCard::all();
        $zero = $all->where('unit', 0.0)->count();
        $cards = $all
            ->when(in_array($status, ['ok', 'near', 'above', 'none'], true), fn ($c) => $c->where('status', $status))
            ->when($family !== '', fn ($c) => $c->filter(fn ($r) => $r['product']->family === $family))
            ->values();

        if ($request->boolean('export')) {
            return Csv::download('costing-cards-'.now()->format('Y-m-d-Hi').'.csv', [
                __('costing.col_product'), __('costing.col_family'), __('costing.col_raw'), __('costing.col_packaging'),
                __('costing.col_overhead'), __('costing.col_unit_batch'), __('costing.col_unit_wavg'), __('costing.col_unit'),
                __('costing.col_suggested'), __('costing.col_tp'), __('costing.col_status'),
            ], $cards->map(fn ($r) => [
                $r['product']->displayName(), $r['product']->familyLabel(), Csv::money($r['raw']), Csv::money($r['packaging']),
                Csv::money($r['overhead']), Csv::money($r['unit_batch']), Csv::money($r['unit_wavg']), Csv::money($r['unit']),
                Csv::money($r['suggested']), $r['tp'] ? Csv::money($r['tp']->price) : '', __('costing.status_'.$r['status']),
            ]), null, Csv::meta(__('costing.cards_title')));
        }

        return view('costing.cards', [
            'tab' => 'cards', 'cards' => $cards, 'zero' => $zero, 'status' => $status, 'family' => $family,
            'families' => ProductFamily::query()->get(),
        ]);
    }

    private function history(Request $request)
    {
        $range = DateRange::fromRequest($request, 'month');
        $rows = ProductTransferPrice::query()->with(['product', 'approver'])
            ->tap(fn ($q) => $range->apply($q, 'approved_at'))
            ->orderByDesc('approved_at')->orderByDesc('id')->get();

        if ($request->boolean('export')) {
            return Csv::download('costing-tp-history-'.now()->format('Y-m-d-Hi').'.csv', [
                __('costing.col_product'), __('costing.col_tp'), __('costing.col_unit_cost'), __('costing.col_markup'),
                __('costing.col_effective'), __('costing.col_approved_by'), __('costing.col_approved_at'), __('costing.col_note'),
            ], $rows->map(fn ($t) => [
                $t->product?->displayName() ?? '', Csv::money($t->price), $t->unit_cost, $t->markup_pct ?? '',
                $t->effective_from?->format('Y-m-d'), $t->approver?->name ?? '', $t->approved_at?->format('Y-m-d H:i'), $t->note ?? '',
            ]), null, Csv::meta(__('costing.history_title'), $range->fromValue() ?: null, $range->toValue() ?: null));
        }

        return view('costing.cards', ['tab' => 'history', 'rows' => $rows, 'range' => $range]);
    }

    public function approve(Request $request, Product $product)
    {
        abort_unless($product->kind === Product::KIND_FINISHED, 404);
        $data = $request->validate(['price' => ['required', 'numeric', 'gt:0', 'max:9999999'], 'note' => ['nullable', 'string', 'max:255']]);
        $card = CostCard::for($product);
        try {
            TransferPrice::approve($product, (float) $data['price'], $request->user(), $card['unit'], $card['markup_pct'], $data['note'] ?? null);
        } catch (Rejected $e) {
            return back()->withErrors(['price' => $e->getMessage()]);
        }

        return back()->with('ok', __('costing.tp_approved_ok', ['product' => $product->displayName()]));
    }
}
