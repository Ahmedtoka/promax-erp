<?php

namespace App\Http\Controllers\Mfg;

use App\Exceptions\Rejected;
use App\Http\Controllers\Controller;
use App\Models\CostCenter;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\Warehouse;
use App\Services\Mfg\Orders;
use App\Services\Mfg\PurchaseLink;
use App\Services\Mfg\Requirement;
use App\Support\Csv;
use App\Support\DateRange;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * أوامر الإنتاج (التصنيع ١٩/١٠، spec §3.5) — القايمة والإنشاء والصفحة
 * والبدء والانتهاء والإلغاء وطلب الشراء للعجز، واحتياج الخامات.
 *
 * الكنترولر رفيع: الدورة في `App\Services\Mfg\Orders` (نفس الخدمة اللي
 * الأبلكيشن بيندهها)، والرفض (`Rejected`) بيرجع فلاش خطأ على `order`.
 *
 * ⚠️ الفلتر على `planned_on` (العمود التجاري)، والتصدير من نفس الكويري
 * المفلترة قبل الباجينيشن (promax-system §7).
 */
class OrderController extends Controller
{
    public function index(Request $request)
    {
        // قيمة مش معروفة = من غير فلتر (مفيش 422 على شاشة GET)
        $picked = $request->string('status')->value();
        $filters = [
            'status' => in_array($picked, ProductionOrder::STATUSES, true) ? $picked : null,
            'line_id' => $request->integer('line_id') ?: null,
            'product_id' => $request->integer('product_id') ?: null,
        ];

        $q = ProductionOrder::query()->with(['product', 'line']);

        if ($status = $filters['status']) {
            $q->where('status', $status);
        }

        if ($lid = $filters['line_id']) {
            $q->where('line_id', $lid);
        }

        if ($pid = $filters['product_id']) {
            $q->where('product_id', $pid);
        }

        $range = DateRange::fromRequest($request);
        $range->apply($q, 'planned_on');

        $q->orderByDesc('planned_on')->orderByDesc('id');

        if ($request->boolean('export')) {
            $rows = (clone $q)->get();

            return Csv::download('production-orders-'.now()->format('Y-m-d-Hi').'.csv', [
                __('mfg.col_number'), __('mfg.col_product'), __('mfg.col_line'), __('mfg.col_batch_no'),
                __('mfg.col_planned_on'), __('mfg.col_planned_qty'), __('mfg.col_output_qty'), __('mfg.col_status'),
                __('mfg.col_hours'),
            ], $rows->map(fn (ProductionOrder $o) => [
                $o->number, $o->product?->displayName() ?? '', $o->line?->displayName() ?? '', $o->batch_no,
                $o->planned_on?->format('Y-m-d') ?? '', $o->planned_qty, $o->output_qty ?? '', $o->statusLabel(),
                $o->hours ?? '',
            ]), null, Csv::meta(__('mfg.orders_title'), $range->fromValue() ?: null, $range->toValue() ?: null));
        }

        return view('mfg.orders', [
            'orders' => $q->paginate(50)->withQueryString(),
            'filters' => $filters,
            'range' => $range,
            'lines' => CostCenter::query()->where('kind', 'line')->orderByDesc('active')->orderBy('code')->get(),
            'products' => Product::query()->where('kind', Product::KIND_FINISHED)
                ->whereIn('id', ProductionOrder::query()->select('product_id'))
                ->orderBy('name')->get(),
            // اختيارات أمر جديد: منتج تام نشط له BOM معتمدة، وخط نشط، ومخزن نشط
            'orderable' => Product::query()->where('kind', Product::KIND_FINISHED)->where('active', true)
                ->whereHas('boms', fn ($b) => $b->where('status', 'approved'))
                ->orderBy('name')->get(),
            'warehouses' => Warehouse::query()->where('active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'line_id' => ['required', 'integer', 'exists:cost_centers,id'],
            'planned_qty' => ['required', 'integer', 'min:1', 'max:10000000'],
            'batch_no' => ['required', 'string', 'max:60'],
            'planned_on' => ['required', 'date'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'raw_warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $order = Orders::create($data, $request->user());
        } catch (Rejected $e) {
            return back()->withErrors(['order' => $e->getMessage()])->withInput();
        }

        return redirect()->route('mfg.orders.show', $order)
            ->with('ok', __('mfg.flash_order_created', ['number' => $order->number]));
    }

    public function show(ProductionOrder $order)
    {
        $order->load(['product', 'bom', 'line', 'warehouse', 'rawWarehouse', 'creator', 'lines.product']);

        return view('mfg.order', [
            'order' => $order,
            'req' => Requirement::forOrder($order),
        ]);
    }

    public function start(Request $request, ProductionOrder $order): RedirectResponse
    {
        return $this->run(fn () => Orders::start($order, $request->user()), 'mfg.flash_order_started');
    }

    public function finish(Request $request, ProductionOrder $order): RedirectResponse
    {
        $data = $request->validate(['output_qty' => ['required', 'integer', 'min:1', 'max:10000000']]);

        return $this->run(fn () => Orders::finish($order, $request->user(), (int) $data['output_qty']), 'mfg.flash_order_finished');
    }

    public function cancel(Request $request, ProductionOrder $order): RedirectResponse
    {
        return $this->run(fn () => Orders::cancel($order, $request->user()), 'mfg.flash_order_cancelled');
    }

    /** طلب شراء للعجز (D8) — الأدمن بيتبلّغ في الحالتين */
    public function shortage(Request $request, ProductionOrder $order): RedirectResponse
    {
        try {
            $id = PurchaseLink::raise($order, $request->user());
        } catch (Rejected $e) {
            return back()->withErrors(['order' => $e->getMessage()]);
        }

        return back()->with('ok', $id
            ? __('mfg.flash_shortage_request', ['id' => $id])
            : __('mfg.flash_shortage_notified'));
    }

    /** احتياج الخامات للأوامر المفتوحة (مخطط/جاري) — الفترة على `planned_on` */
    public function requirements(Request $request)
    {
        $range = DateRange::fromRequest($request);
        $rows = Requirement::forOpenOrders($range);

        if ($request->boolean('export')) {
            return Csv::download('material-requirement-'.now()->format('Y-m-d-Hi').'.csv', [
                __('mfg.col_material'), __('mfg.col_unit'), __('mfg.col_orders'), __('mfg.col_std_qty_planned'),
                __('mfg.col_issued_net'), __('mfg.col_remaining'), __('mfg.col_available'), __('mfg.col_shortage'),
            ], $rows->map(fn (array $r) => [
                $r['product']->code.' — '.$r['product']->displayName(), $r['product']->unitLabel(), $r['orders'],
                $r['std_qty'], $r['issued_net'], $r['remaining'], $r['available'], $r['shortage'],
            ]), null, Csv::meta(__('mfg.requirements_title'), $range->fromValue() ?: null, $range->toValue() ?: null));
        }

        return view('mfg.requirements', ['rows' => $rows, 'range' => $range]);
    }

    private function run(callable $fn, string $ok): RedirectResponse
    {
        try {
            $fn();
        } catch (Rejected $e) {
            return back()->withErrors(['order' => $e->getMessage()]);
        }

        return back()->with('ok', __($ok));
    }
}
