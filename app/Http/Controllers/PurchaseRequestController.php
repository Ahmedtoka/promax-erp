<?php

namespace App\Http\Controllers;

use App\Exceptions\Rejected;
use App\Models\CostCenter;
use App\Models\Product;
use App\Models\PurchaseQuote;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Support\Csv;
use App\Support\DateRange;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * طلبات الشراء وعروض الأسعار والمقارنة (لين ٥).
 * أمين المخزن/المدير بيطلب خامة، المدير بيجمع عروض، والأدمن بس
 * بيعتمد عرض فيتفتح أمر شراء بسعره (`PurchaseQuote::approve`).
 */
class PurchaseRequestController extends Controller
{
    public function index(Request $request)
    {
        $q = PurchaseRequest::with(['product', 'requester', 'costCenter'])->withCount('quotes');

        if ($st = $request->string('status')->value()) {
            $q->where('status', $st);
        }
        if ($pid = $request->integer('product')) {
            $q->where('product_id', $pid);
        }

        // الفترة على تاريخ الطلب نفسه
        $range = DateRange::fromRequest($request);
        $range->apply($q, 'created_at');

        if ($request->boolean('export')) {
            $all = (clone $q)->orderBy('created_at')->orderBy('id')->get();

            return Csv::download(
                'purchase-requests.csv',
                [__('common.number'), __('stock.product'), __('common.qty'), __('supplier.needed_by'),
                    __('supplier.requested_by'), __('supplier.cost_center'), __('supplier.quotes_count'), __('common.status')],
                $all->map(fn ($r) => [
                    $r->number, $r->product?->displayName() ?? '', $r->qty, $r->needed_by?->format('Y-m-d') ?? '',
                    $r->requester?->name ?? '', $r->costCenter?->displayName() ?? '', $r->quotes_count, $r->statusLabel(),
                ]),
                [__('common.total'), $all->count(), $all->sum('qty'), '', '', '', $all->sum('quotes_count'), ''],
                Csv::meta(__('supplier.purchase_requests'), $range->fromValue() ?: null, $range->toValue() ?: null),
            );
        }

        return view('erp.purchase_requests', [
            'requests' => $q->orderByDesc('id')->paginate(30)->withQueryString(),
            'range' => $range,
            'filters' => $request->only(['status', 'product']),
            // أي صنف نشط من أي نوع (خامة/تعبئة/منتج تام)
            'products' => Product::where('active', true)->orderBy('code')->get(),
            'costCenters' => CostCenter::where('active', true)->orderBy('code')->get(),
        ]);
    }

    public function show(PurchaseRequest $purchaseRequest)
    {
        $purchaseRequest->load(['product', 'requester', 'costCenter', 'order', 'quotes.supplier']);

        $quotes = $purchaseRequest->quotes->sortBy([['unit_price', 'asc'], ['id', 'asc']])->values();
        $cheapest = $quotes->min('unit_price');
        $fastest = $quotes->whereNotNull('lead_days')->min('lead_days');

        return view('erp.purchase_request', [
            'r' => $purchaseRequest,
            'quotes' => $quotes,
            'cheapest' => $cheapest,
            'fastest' => $fastest,
            'suppliers' => Supplier::where('active', true)->orderBy('name')->get(),
            'warehouses' => Warehouse::where('active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'product_id' => ['required', Rule::exists('products', 'id')->where('active', true)],
            'qty' => ['required', 'integer', 'min:1', 'max:99999999'],
            'needed_by' => ['nullable', 'date'],
            'cost_center_id' => ['nullable', Rule::exists('cost_centers', 'id')->where('active', true)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $r = DB::transaction(fn () => PurchaseRequest::create([
            'number' => PurchaseRequest::nextNumber(),
            'product_id' => $data['product_id'],
            'qty' => $data['qty'],
            'needed_by' => $data['needed_by'] ?? null,
            'requested_by' => $request->user()->id,
            'cost_center_id' => $data['cost_center_id'] ?? null,
            'status' => 'open',
            'notes' => $data['notes'] ?? null,
        ]));

        return redirect()->route('erp.purchase_requests.show', $r)
            ->with('ok', __('supplier.pr_added', ['number' => $r->number]));
    }

    public function cancel(Request $request, PurchaseRequest $purchaseRequest)
    {
        $user = $request->user();
        // صاحب الطلب بيلغي طلبه، والإدارة بتلغي أي طلب
        abort_unless(
            $purchaseRequest->requested_by === $user->id || in_array($user->role, ['admin', 'manager'], true),
            403,
        );

        $done = DB::transaction(function () use ($purchaseRequest) {
            $r = PurchaseRequest::whereKey($purchaseRequest->id)->lockForUpdate()->first();
            if (! $r->isActive()) {
                return false;
            }
            $r->update(['status' => 'cancelled']);

            return true;
        });

        if (! $done) {
            return back()->withErrors(['status' => __('supplier.request_not_active')]);
        }

        return back()->with('ok', __('supplier.pr_cancelled'));
    }

    public function storeQuote(Request $request, PurchaseRequest $purchaseRequest)
    {
        $data = $request->validate([
            'supplier_id' => [
                'required',
                Rule::exists('suppliers', 'id')->where('active', true),
                Rule::unique('purchase_quotes', 'supplier_id')->where('purchase_request_id', $purchaseRequest->id),
            ],
            'unit_price' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'lead_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'payment_terms' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'attachment' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'],
        ], [
            'supplier_id.unique' => __('supplier.quote_duplicate'),
        ]);

        $added = DB::transaction(function () use ($request, $purchaseRequest, $data) {
            $r = PurchaseRequest::whereKey($purchaseRequest->id)->lockForUpdate()->first();
            if (! $r->isActive()) {
                return false;
            }

            PurchaseQuote::create([
                'purchase_request_id' => $r->id,
                'supplier_id' => $data['supplier_id'],
                'unit_price' => round((float) $data['unit_price'], 2),
                'lead_days' => $data['lead_days'] ?? null,
                'payment_terms' => $data['payment_terms'] ?? null,
                'attachment_path' => $request->file('attachment')?->store('purchase-quotes', 'public'),
                'notes' => $data['notes'] ?? null,
                'status' => 'pending',
                'created_by' => $request->user()->id,
            ]);

            if ($r->status === 'open') {
                $r->update(['status' => 'quoting']);
            }

            return true;
        });

        if (! $added) {
            return back()->withErrors(['status' => __('supplier.request_not_active')]);
        }

        return back()->with('ok', __('supplier.quote_added'));
    }

    /** الأدمن بس: اعتماد عرض = أمر شراء بسعره */
    public function approveQuote(Request $request, PurchaseQuote $purchaseQuote)
    {
        $data = $request->validate([
            'warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where('active', true)],
        ]);

        try {
            $order = $purchaseQuote->approve($request->user(), (int) $data['warehouse_id']);
        } catch (Rejected $e) {
            return back()->withErrors(['quote' => $e->getMessage()]);
        }

        return redirect()->route('erp.purchasing.show', $order)
            ->with('ok', __('supplier.quote_approved', ['number' => $order->number]));
    }
}
