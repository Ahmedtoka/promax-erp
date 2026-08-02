<?php

namespace App\Http\Controllers;

use App\Exceptions\Rejected;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierOrder;
use App\Models\SupplierPayment;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * ═══════════════════════════════════════════════════════════════
 * الموردين والمشتريات — الشاشات
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ الاتجاه هنا معكوس عن باقي السيستم: البضاعة داخلة والفلوس
 * خارجة. أي رقم بيتحرك هنا بيمرّ بنفس الدوكترين — الباتشات هي
 * مصدر المخزون، ودفتر المورد هو مصدر رصيده.
 */
class SupplierController extends Controller
{
    // ═══════════════════════ الموردين ═══════════════════════

    public function index(Request $request)
    {
        $q = Supplier::query();

        if ($s = $request->string('q')->trim()->value()) {
            $q->where(fn ($w) => $w->where('name', 'like', "%$s%")
                ->orWhere('name_en', 'like', "%$s%")
                ->orWhere('code', 'like', "%$s%")
                ->orWhere('phone', 'like', "%$s%"));
        }

        $suppliers = $q->withCount(['orders as open_orders' => fn ($w) => $w->whereIn('status', ['open'])])
            ->orderByDesc('balance')->orderBy('name')
            ->get();

        return view('erp.suppliers', [
            'suppliers' => $suppliers,
            'filters' => $request->only(['q']),
            'totalOwed' => (float) Supplier::where('balance', '>', 0)->sum('balance'),
        ]);
    }

    public function show(Supplier $supplier)
    {
        $supplier->load(['orders' => fn ($q) => $q->latest()->limit(20)]);

        return view('erp.supplier', [
            's' => $supplier,
            'orderCount' => $supplier->orders()->count(),
            'openCount' => $supplier->orders()->where('status', 'open')->count(),
            'txns' => $supplier->transactions()->with('source')
                ->orderByDesc('date')->orderByDesc('id')->paginate(50),
            'invoices' => $supplier->invoices()->latest('invoice_date')->limit(20)->get(),
            'payments' => $supplier->payments()->latest('paid_on')->limit(20)->get(),
        ]);
    }

    /** قواعد فورم المورد — مصدر واحد للإضافة والتعديل */
    private function supplierRules(?Supplier $supplier = null): array
    {
        return [
            'name' => ['required', 'string', 'max:190'],
            'name_en' => ['nullable', 'string', 'max:190'],
            'phone' => ['nullable', 'string', 'max:30'],
            'contact_person' => ['nullable', 'string', 'max:190'],
            'address' => ['nullable', 'string', 'max:190'],
            'tax_id' => ['nullable', 'string', 'max:40'],
            'payment_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->supplierRules());

        $supplier = Supplier::create($data + [
            'code' => Supplier::nextCode(),
            'active' => true,
        ]);

        return redirect()->route('erp.suppliers.show', $supplier)
            ->with('ok', __('supplier.added', ['name' => $supplier->displayName()]));
    }

    public function update(Request $request, Supplier $supplier)
    {
        $data = $request->validate($this->supplierRules($supplier));

        $supplier->update($data + ['active' => $request->boolean('active', true)]);

        return back()->with('ok', __('supplier.updated'));
    }

    /**
     * رصيد أول المدة — اللي كان علينا للمورد قبل السيستم.
     *
     * ⚠️ **بيستبدل الافتتاحي القديم** زي رصيد العميل بالظبط — لو
     * اتزوّد، أول تصحيح يخلّي الرصيد ضعف الحقيقي.
     */
    public function opening(Request $request, Supplier $supplier)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'between:-99999999,99999999'],
            'date' => ['required', 'date', 'before_or_equal:today'],
        ]);

        DB::transaction(function () use ($supplier, $data) {
            $supplier->transactions()->where('kind', 'opening')->delete();

            $amount = (float) $data['amount'];

            // صفر = مسح الافتتاحي وخلاص — قيد 0/0 مالوش معنى
            if (abs($amount) < 0.005) {
                $supplier->recalculate();

                return;
            }

            // موجب = علينا له (دائن) · سالب = دفعنا له مقدم (مدين)
            $supplier->post(
                'opening',
                $data['date'],
                $amount < 0 ? abs($amount) : 0,
                $amount > 0 ? $amount : 0,
                __('supplier.opening_memo'),
            );
        });

        return back()->with('ok', __('supplier.opening_saved'));
    }

    /** دفعة للمورد */
    public function pay(Request $request, Supplier $supplier)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'method' => ['required', Rule::in(SupplierPayment::METHODS)],
            'reference' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string'],
        ]);

        SupplierPayment::record([
            'supplier_id' => $supplier->id,
            'paid_on' => $data['paid_on'],
            'amount' => $data['amount'],
            'method' => $data['method'],
            'reference' => $data['reference'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return back()->with('ok', __('supplier.payment_saved'));
    }

    // ═══════════════════════ أوامر الشراء ═══════════════════════

    public function orders(Request $request)
    {
        $q = SupplierOrder::with(['supplier', 'warehouse']);

        if ($st = $request->string('status')->value()) {
            $q->where('status', $st);
        }
        if ($sup = $request->integer('supplier')) {
            $q->where('supplier_id', $sup);
        }

        return view('erp.supplier_orders', [
            'orders' => $q->latest()->paginate(30)->withQueryString(),
            'suppliers' => Supplier::where('active', true)->orderBy('name')->get(),
            'filters' => $request->only(['status', 'supplier']),
            'openCount' => SupplierOrder::where('status', 'open')->count(),
        ]);
    }

    public function createOrder()
    {
        return view('erp.supplier_order_form', [
            'suppliers' => Supplier::where('active', true)->orderBy('name')->get(),
            'warehouses' => Warehouse::where('active', true)->orderBy('code')->get(),
            'products' => Product::where('active', true)->orderBy('code')->get(),
        ]);
    }

    public function storeOrder(Request $request)
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'ordered_on' => ['required', 'date'],
            'expected_on' => ['nullable', 'date', 'after_or_equal:ordered_on'],
            'notes' => ['nullable', 'string'],
            'qty' => ['required', 'array'],
            'qty.*' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'cost' => ['required', 'array'],
            'cost.*' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
        ]);

        // ⚠️ الأصناف الحقيقية بس، والكمية صفر بتتشال — فورم كامل
        // بكل الكتالوج بيتبعت وأغلبه فاضي
        $known = Product::whereIn('id', array_keys($data['qty']))
            ->where('active', true)->pluck('id')->all();
        $lines = [];

        foreach ($known as $pid) {
            $qty = (int) ($data['qty'][$pid] ?? 0);

            if ($qty > 0) {
                $lines[$pid] = [
                    'qty' => $qty,
                    'unit_cost' => round((float) ($data['cost'][$pid] ?? 0), 2),
                ];
            }
        }

        if ($lines === []) {
            return back()->withErrors(['qty' => __('supplier.no_lines')])->withInput();
        }

        $order = DB::transaction(function () use ($data, $lines, $request) {
            $order = SupplierOrder::create([
                'number' => SupplierOrder::nextNumber(),
                'supplier_id' => $data['supplier_id'],
                'warehouse_id' => $data['warehouse_id'],
                'status' => 'open',
                'ordered_on' => $data['ordered_on'],
                'expected_on' => $data['expected_on'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()->id,
                'total' => collect($lines)->sum(fn ($l) => $l['qty'] * $l['unit_cost']),
            ]);

            foreach ($lines as $pid => $line) {
                $order->items()->create([
                    'product_id' => $pid,
                    'qty' => $line['qty'],
                    'unit_cost' => $line['unit_cost'],
                ]);
            }

            return $order;
        });

        return redirect()->route('erp.purchasing.show', $order)
            ->with('ok', __('supplier.order_created', ['number' => $order->number]));
    }

    public function order(SupplierOrder $supplierOrder)
    {
        $supplierOrder->load([
            'supplier', 'warehouse', 'creator',
            'items.product', 'receipts', 'invoices',
        ]);

        return view('erp.supplier_order', ['o' => $supplierOrder]);
    }

    /** استلام بضاعة على الأمر — إذن استلام حقيقي وباتشات */
    /**
     * ⚠️ أمين المخزن بيستلم في مخزنه هو وبس — نفس حارس شاشات `wh.`.
     * من غيره أمين المعادي بيستلم بضاعة في مخزن العاشر، والفرق بيطلع
     * بعد أسبوع في تسوية محدش عارف مصدرها.
     */
    private function guardWarehouse(Request $request, ?int $warehouseId): void
    {
        $user = $request->user();

        if (! $user?->isWarehouseKeeper() || ! $user->warehouse_id) {
            return;
        }

        if ($warehouseId !== null && (int) $warehouseId !== (int) $user->warehouse_id) {
            abort(403);
        }
    }

    public function receiveOrder(Request $request, SupplierOrder $supplierOrder)
    {
        $this->guardWarehouse($request, $supplierOrder->warehouse_id);

        $data = $request->validate([
            'lines' => ['required', 'array'],
            'lines.*.qty' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'lines.*.batch_no' => ['nullable', 'string', 'max:60'],
            'lines.*.produced_on' => ['nullable', 'date'],
            'lines.*.expires_on' => ['nullable', 'date'],
        ]);

        $lines = array_filter($data['lines'], fn ($l) => (int) ($l['qty'] ?? 0) > 0);

        if ($lines === []) {
            return back()->withErrors(['lines' => __('supplier.no_lines')])->withInput();
        }

        try {
            $receipt = $supplierOrder->receive($lines, $request->user());
        } catch (Rejected $e) {
            return back()->withErrors(['lines' => $e->getMessage()])->withInput();
        }

        return back()->with('ok', __('supplier.received_ok', [
            'number' => $receipt->number,
        ]));
    }

    /** فاتورة المورد على الأمر — بالمستلَم فعلاً */
    public function invoiceOrder(Request $request, SupplierOrder $supplierOrder)
    {
        $data = $request->validate([
            'supplier_ref' => ['nullable', 'string', 'max:60'],
            'invoice_date' => ['required', 'date', 'before_or_equal:today'],
            'tax' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'notes' => ['nullable', 'string'],
        ]);

        try {
            DB::transaction(function () use ($supplierOrder, $data, $request) {
                // ⚠️ **القفل قبل الحساب.** دبل كليك = ريكوستين بيقروا
                // نفس «المتفوتر» القديم ويعدّوا من الحارس مع بعض —
                // فاتورتين لنفس البضاعة والمستحق بيتضاعف.
                $order = SupplierOrder::whereKey($supplierOrder->id)->lockForUpdate()->first();

                // ⚠️ **الفاتورة بالمستلَم مش بالمطلوب.** المورد بيفوتر
                // اللي وصل فعلاً — فوترة الأمر كله وهو نص مستلم بتسجل
                // علينا فلوس بضاعة لسه ماجتش.
                $items = $order->items()->get();

                if ($items->sum('received_qty') === 0) {
                    throw new Rejected(__('supplier.nothing_received'));
                }

                $subtotal = $items->sum(fn ($i) => round($i->received_qty * (float) $i->unit_cost, 2));
                $alreadyInvoiced = (float) $order->invoices()->sum('subtotal');

                if ($alreadyInvoiced + 0.01 >= $subtotal) {
                    throw new Rejected(__('supplier.already_invoiced'));
                }

                $tax = round((float) ($data['tax'] ?? 0), 2);
                $net = round($subtotal - $alreadyInvoiced, 2);

                SupplierInvoice::record([
                    'supplier_id' => $order->supplier_id,
                    'supplier_order_id' => $order->id,
                    'supplier_ref' => $data['supplier_ref'] ?? null,
                    'invoice_date' => $data['invoice_date'],
                    'due_on' => $order->supplier->payment_days
                        ? now()->parse($data['invoice_date'])->addDays($order->supplier->payment_days)->toDateString()
                        : null,
                    'subtotal' => $net,
                    'tax' => $tax,
                    'total' => round($net + $tax, 2),
                    'notes' => $data['notes'] ?? null,
                    'created_by' => $request->user()->id,
                ]);
            });
        } catch (Rejected $e) {
            return back()->withErrors(['invoice_date' => $e->getMessage()]);
        }

        return back()->with('ok', __('supplier.invoice_saved'));
    }

    public function cancelOrder(SupplierOrder $supplierOrder)
    {
        if (! $supplierOrder->isOpen()) {
            return back()->withErrors(['status' => __('supplier.order_not_open')]);
        }

        // ⚠️ **اللي عليه استلام مايتلغيش** — البضاعة دخلت المخزن
        // خلاص، والإلغاء كان هيسيب باتشات مصدرها أمر «ملغي».
        if ($supplierOrder->items()->where('received_qty', '>', 0)->exists()) {
            return back()->withErrors(['status' => __('supplier.cannot_cancel_received')]);
        }

        $supplierOrder->update(['status' => 'cancelled']);

        return back()->with('ok', __('supplier.order_cancelled'));
    }

    public function closeOrder(SupplierOrder $supplierOrder)
    {
        if (! $supplierOrder->isOpen()) {
            return back()->withErrors(['status' => __('supplier.order_not_open')]);
        }

        // القفل = خلاص مش مستنيين باقي البضاعة — الفرق بيتساب
        $supplierOrder->update(['status' => 'closed']);

        return back()->with('ok', __('supplier.order_closed'));
    }
}
