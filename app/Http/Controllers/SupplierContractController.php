<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierContract;
use App\Support\Csv;
use App\Support\DateRange;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * عقود الموردين — أسعار ثابتة وفترة سريان (لين ٥).
 * العقد بيحكم قاعدة موافقة أوامر الشراء (`PurchaseApproval`، تاسك ٣)؛
 * هنا الشاشات بس.
 */
class SupplierContractController extends Controller
{
    public function index(Request $request)
    {
        $q = SupplierContract::with('supplier')->withCount('items');

        if ($sup = $request->integer('supplier')) {
            $q->where('supplier_id', $sup);
        }

        // فلتر الحالة بالتاريخ والتفعيل — نفس معنى `statusKey()`
        $today = now()->toDateString();
        match ($request->string('status')->value()) {
            'live' => $q->where('active', true)->whereDate('starts_on', '<=', $today)->whereDate('ends_on', '>=', $today),
            'expired' => $q->where('active', true)->whereDate('ends_on', '<', $today),
            'future' => $q->where('active', true)->whereDate('starts_on', '>', $today),
            'inactive' => $q->where('active', false),
            default => null,
        };

        $range = DateRange::fromRequest($request);
        $range->apply($q, 'starts_on');

        if ($request->boolean('export')) {
            $all = (clone $q)->orderBy('starts_on')->orderBy('id')->get();

            return Csv::download(
                'supplier-contracts.csv',
                [__('common.number'), __('supplier.supplier'), __('supplier.contract_starts'),
                    __('supplier.contract_ends'), __('supplier.contract_items'), __('common.status')],
                $all->map(fn ($c) => [
                    $c->number, $c->supplier?->displayName() ?? '', $c->starts_on->format('Y-m-d'),
                    $c->ends_on->format('Y-m-d'), $c->items_count, $c->statusLabel(),
                ]),
                [__('common.total'), $all->count(), '', '', $all->sum('items_count'), ''],
                Csv::meta(__('supplier.contracts'), $range->fromValue() ?: null, $range->toValue() ?: null),
            );
        }

        return view('erp.supplier_contracts', [
            'contracts' => $q->orderByDesc('starts_on')->orderByDesc('id')->paginate(30)->withQueryString(),
            'range' => $range,
            'suppliers' => Supplier::where('active', true)->orderBy('name')->get(),
            'filters' => $request->only(['status', 'supplier']),
        ]);
    }

    public function show(SupplierContract $supplierContract)
    {
        $supplierContract->load(['supplier', 'items.product']);

        return view('erp.supplier_contract', [
            'c' => $supplierContract,
            // أي صنف نشط من أي نوع (لين ٢ D3: شاشات الموردين من غير فلتر)
            'products' => Product::where('active', true)->orderBy('code')->get(),
            'prices' => $supplierContract->items->pluck('unit_price', 'product_id'),
        ]);
    }

    private function rules(): array
    {
        return [
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'notes' => ['nullable', 'string'],
            'attachment' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'],
        ];
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules() + [
            'supplier_id' => ['required', 'exists:suppliers,id'],
        ]);

        $c = DB::transaction(function () use ($request, $data) {
            $path = $request->file('attachment')?->store('supplier-contracts', 'public');

            return SupplierContract::create([
                'number' => SupplierContract::nextNumber(),
                'supplier_id' => $data['supplier_id'],
                'starts_on' => $data['starts_on'],
                'ends_on' => $data['ends_on'],
                'notes' => $data['notes'] ?? null,
                'attachment_path' => $path,
                'active' => true,
                'created_by' => $request->user()->id,
            ]);
        });

        return redirect()->route('erp.supplier_contracts.show', $c)
            ->with('ok', __('supplier.contract_added', ['number' => $c->number]));
    }

    public function update(Request $request, SupplierContract $supplierContract)
    {
        $data = $request->validate($this->rules());

        $fill = [
            'starts_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'],
            'notes' => $data['notes'] ?? null,
            'active' => $request->boolean('active', true),
        ];
        if ($file = $request->file('attachment')) {
            $fill['attachment_path'] = $file->store('supplier-contracts', 'public');
        }
        $supplierContract->update($fill);

        return back()->with('ok', __('supplier.contract_updated'));
    }

    /** يستبدل مجموعة الأسعار كلها: `price[product_id]` — الفاضي = اتشال */
    public function items(Request $request, SupplierContract $supplierContract)
    {
        $request->validate([
            'price' => ['nullable', 'array'],
            'price.*' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
        ]);

        $prices = collect($request->input('price', []))
            ->filter(fn ($v) => $v !== null && $v !== '')
            ->only(Product::whereIn('id', array_keys($request->input('price', [])))->pluck('id')->all());

        DB::transaction(function () use ($supplierContract, $prices) {
            $supplierContract->items()->whereNotIn('product_id', $prices->keys()->all())->delete();
            foreach ($prices as $productId => $price) {
                $supplierContract->items()->updateOrCreate(
                    ['product_id' => (int) $productId],
                    ['unit_price' => round((float) $price, 2)],
                );
            }
        });

        return back()->with('ok', __('supplier.contract_items_saved'));
    }
}
