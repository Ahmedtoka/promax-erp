<?php

namespace App\Http\Controllers\Mfg;

use App\Exceptions\Rejected;
use App\Http\Controllers\Controller;
use App\Models\CostCenter;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\Warehouse;
use App\Services\Mfg\Closing;
use App\Services\Mfg\Issues;
use App\Services\Mfg\Orders;
use App\Services\Mfg\PurchaseLink;
use App\Services\Mfg\Requirement;
use App\Support\Csv;
use App\Support\DateRange;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

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
        $order->load(['product', 'bom', 'line', 'warehouse', 'rawWarehouse', 'creator', 'lines.product', 'fgBatch']);

        return view('mfg.order', [
            'order' => $order,
            'req' => Requirement::forOrder($order),
            'issues' => $order->issues()->with(['product', 'batch', 'user'])->get(),
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

    /**
     * قفل الأمر المنتهي ← باتش تام في الحجر + التكلفة والانحراف (D6).
     * الإنتاج بيتصحح هنا لو العدّ النهائي اختلف عن اللي اتسجل عند الانتهاء.
     */
    public function close(Request $request, ProductionOrder $order): RedirectResponse
    {
        $data = $request->validate([
            'output_qty' => ['required', 'integer', 'min:1', 'max:10000000'],
            'produced_on' => ['required', 'date', 'before_or_equal:today'],
        ]);

        try {
            $closed = Closing::close($order, (int) $data['output_qty'], $request->user(), Carbon::parse($data['produced_on']));
        } catch (Rejected $e) {
            return back()->withErrors(['order' => $e->getMessage()])->withInput();
        }

        return back()->with('ok', __('mfg.flash_order_closed', ['batch' => $closed->batch_no]));
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

    /** صرف خامة للأمر — صرف عادي أو صرف زيادة فوق المعياري (D5) */
    public function issue(Request $request, ProductionOrder $order): RedirectResponse
    {
        $data = $request->validate([
            'material_id' => ['required', 'integer', 'exists:products,id'],
            'qty' => ['required', 'numeric', 'gt:0', 'max:10000000'],
            'kind' => ['required', 'in:'.implode(',', Issues::ISSUE_KINDS)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $rows = Issues::issue($order, Product::query()->findOrFail($data['material_id']), (float) $data['qty'],
                $request->user(), $data['kind'], $data['note'] ?? null);
        } catch (Rejected $e) {
            return back()->withErrors(['issue' => $e->getMessage()])->withInput();
        }

        return back()->with('ok', __('mfg.flash_issued', ['count' => $rows->count()]));
    }

    /** صرف الباقي من المعياري لكل السطور مرة واحدة */
    public function issueStandard(Request $request, ProductionOrder $order): RedirectResponse
    {
        try {
            $n = Issues::issueStandard($order, $request->user());
        } catch (Rejected $e) {
            return back()->withErrors(['issue' => $e->getMessage()]);
        }

        if ($n === 0) {
            return back()->withErrors(['issue' => __('mfg.issue_nothing_left')]);
        }

        return back()->with('ok', __('mfg.flash_issued_standard', ['count' => $n]));
    }

    /** مرتجع للمخزن — لنفس الباتشات اللي الأمر سحب منها (D4) */
    public function returnToStore(Request $request, ProductionOrder $order): RedirectResponse
    {
        $data = $request->validate([
            'material_id' => ['required', 'integer', 'exists:products,id'],
            'qty' => ['required', 'numeric', 'gt:0', 'max:10000000'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            Issues::returnToStore($order, Product::query()->findOrFail($data['material_id']), (float) $data['qty'],
                $request->user(), $data['note'] ?? null);
        } catch (Rejected $e) {
            return back()->withErrors(['issue' => $e->getMessage()])->withInput();
        }

        return back()->with('ok', __('mfg.flash_returned'));
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
