<?php

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Models\Client;
use App\Models\ClientRequest;
use App\Models\Invoice;
use App\Models\PickOrder;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\TrackEvent;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\Zone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * العمليات الميدانية — المناديب، العهدة، أوامر التوريد، موافقات العملاء، التراكينج
 */
class OpsController extends Controller
{
    // ================= لوحة العمليات =================

    public function dashboard()
    {
        // ⚠️ سكوب الفرع على لوحة العمليات
        $field = \App\Models\Branch::scope(
            User::whereIn('role', User::FIELD_ROLES)->with('zone'),
        )->get();

        return view('ops.dashboard', [
            'field' => $field->map(fn ($u) => $this->userStats($u)),
            'todaySales' => Invoice::whereDate('created_at', today())->sum('total'),
            'todayPos' => PurchaseOrder::whereDate('delivered_at', today())->sum('total'),
            'openRequests' => ClientRequest::whereIn('status', ['pending', 'review'])->count(),
            'visitsDone' => DB::table('visits')->whereDate('created_at', today())
                ->whereNotNull('checked_out_at')->count(),
            'events' => TrackEvent::with('user')->whereDate('happened_at', today())
                ->orderByDesc('happened_at')->take(30)->get(),
        ]);
    }

    private function userStats(User $u): array
    {
        $custody = $u->todayCustody();
        $custody?->load('items.product');

        return [
            'user' => $u,
            'custody' => $custody,
            'remaining' => $custody?->remainingUnits() ?? 0,
            'remainingValue' => $custody?->remainingValue($u->isDriver() ? 'old' : 'new') ?? 0,
            'sales' => Invoice::where('user_id', $u->id)->whereDate('created_at', today())->sum('total'),
            'visits' => $u->visits()->whereDate('created_at', today())->count(),
            'visitsDone' => $u->visits()->whereDate('created_at', today())->whereNotNull('checked_out_at')->count(),
            'pos' => PurchaseOrder::where('assigned_to', $u->id)->whereDate('created_at', today())->count(),
            'posDone' => PurchaseOrder::where('assigned_to', $u->id)->where('status', 'delivered')
                ->whereDate('delivered_at', today())->count(),
            // ⚠️ «قيمة التسليمات» = اللي السواق حصّله فعلاً، فبالإجمالي
            // شامل الضريبة. الصافي مكانه تقارير المبيعات.
            'posValue' => PurchaseOrder::where('assigned_to', $u->id)->where('status', 'delivered')
                ->whereDate('delivered_at', today())->sum('grand_total'),
            'openVisit' => $u->openVisit(),
        ];
    }

    public function rep(Request $request, User $user)
    {
        // ⚠️ نفس القاعدة: الشاشة بتوري عهدة المندوب وفواتيره وتحركاته
        abort_unless($request->user()->canSeeBranch($user->branch_id), 403);

        $custody = $user->todayCustody();
        $custody?->load('items.product');

        return view('ops.rep', [
            'u' => $user,
            'stats' => $this->userStats($user),
            'custody' => $custody,
            'invoices' => Invoice::with('client')->where('user_id', $user->id)
                ->latest()->take(30)->get(),
            'visits' => $user->visits()->with('client')->take(30)->get(),
            'events' => $user->trackEvents()->whereDate('happened_at', today())->get(),
            'products' => Product::orderBy('code')->get(),
        ]);
    }

    // ================= العهدة =================

    // ⚠️ `loadVan` (التحميل المباشر) **اتشال** (قرار المالك 2026-08-03):
    // كان بيجهّز ويسلّم في نفس الثانية من غير استلام المندوب من
    // الأبلكيشن — التحميل الرسمي بقى من فلو تسليم العهدة:
    // CustodyHandoutController::store ← تجهيز الطلبات ← تأكيد ← استلام.

    public function closeCustody(User $user)
    {
        $custody = $user->todayCustody();
        $custody?->update(['status' => 'closed', 'closed_at' => now()]);

        return back()->with('ok', __('flash.van_closed'));
    }

    // ================= أوامر التوريد =================

    public function purchaseOrders(Request $request)
    {
        $q = PurchaseOrder::with(['client', 'courier', 'items']);
        if ($status = $request->string('status')->value()) {
            $q->where('status', $status);
        }

        return view('ops.pos', [
            'pos' => $q->latest()->paginate(30)->withQueryString(),
            'couriers' => User::where('role', 'driver')->get(),
            'clients' => Client::orderBy('name')->get(['id', 'name']),
            'products' => Product::orderBy('code')->get(),
            'filters' => $request->only('status'),
        ]);
    }

    public function storePurchaseOrder(Request $request)
    {
        $data = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'source' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:190'],
            'assigned_to' => ['nullable', 'exists:users,id'],
            'price_mode' => ['required', 'in:channel,old,new'],
            'due_date' => ['nullable', 'date'],
            'qty' => ['required', 'array'],
            'qty.*' => ['nullable', 'integer', 'min:0'],
        ]);

        DB::transaction(function () use ($data) {
            // العميل محتاجينه عشان نحسب تسعيرته لو الوضع channel
            $client = Client::findOrFail($data['client_id']);

            $po = PurchaseOrder::create([
                'number' => PurchaseOrder::nextNumber(),
                'client_id' => $client->id,
                'source' => $data['source'] ?? null,
                'address' => $data['address'] ?? null,
                'assigned_to' => $data['assigned_to'] ?? null,
                // ⚠️ channel معناها إن السطور اتسعّرت بتسعيرة العميل (بخصمه)،
                // فالـ PO نفسه بيتسجل بالقائمة اللي العميل عليها عشان
                // مايعيدش الحساب بسعر قائمة عند التسليم.
                'price_mode' => $data['price_mode'] === 'channel'
                    ? $client->priceList()
                    : $data['price_mode'],
                'due_date' => $data['due_date'] ?? null,
                'status' => 'pending',
                'total' => 0,
            ]);

            $total = 0;
            $rows = [];
            foreach ($data['qty'] as $productId => $qty) {
                $qty = (int) $qty;
                if ($qty <= 0) {
                    continue;
                }
                $product = Product::find($productId);
                if (! $product) {
                    continue;
                }
                // ⚠️ channel = سعر العميل بخصمه (زي الفاتورة بالظبط).
                // old/new = سعر قائمة بدون خصم، وده مقصود لبعض السلاسل
                // اللي بتتحاسب بسعر قائمة صافي متفق عليه في العقد.
                $price = $data['price_mode'] === 'channel'
                    ? $client->priceFor($product)
                    : $product->priceFor($data['price_mode']);

                $lineTotal = round($qty * $price, 2);

                // ⚠️ الضريبة سطر بسطر من `Tax` — نفس قاعدة الفاتورة بالظبط.
                // الأمر ممكن يجمع صنف خاضع وصنف معفى.
                $taxRate = \App\Services\Tax::rate($client, $product);
                $lineTax = \App\Services\Tax::on($lineTotal, $client, $product);

                PurchaseOrderItem::create([
                    'purchase_order_id' => $po->id,
                    'product_id' => $product->id,
                    'qty' => $qty,
                    'price' => $price,
                    'total' => $lineTotal,
                    'tax_rate' => $taxRate,
                    'tax' => $lineTax,
                ]);

                $rows[] = ['total' => $lineTotal, 'tax' => $lineTax];
                $total += $lineTotal;
            }

            // ⚠️ `total` صافي المبيعات، و `grand_total` اللي العميل بيدفعه —
            // وهو اللي بيتقيّد في كشف الحساب عند التسليم.
            $sums = \App\Services\Tax::totals($rows);
            $total = $sums['net'];

            $po->update([
                'total' => $sums['net'],
                'tax_total' => $sums['tax'],
                'grand_total' => $sums['grand'],
            ]);

            if ($po->assigned_to) {
                AppNotification::send(
                    User::find($po->assigned_to),
                    fn () => __('field.notif_po_new_title', ['number' => $po->number]),
                    fn () => __('field.notif_po_new_body', [
                        'client' => $po->client->displayName(),
                        // ⚠️ المبلغ اللي السواق هيحصّله، مش الصافي
                        'amount' => number_format($po->fresh()->payable()),
                    ]),
                );
            }
        });

        return back()->with('ok', __('flash.po_created'));
    }

    public function assignPurchaseOrder(Request $request, PurchaseOrder $purchaseOrder)
    {
        $data = $request->validate(['assigned_to' => ['required', 'exists:users,id']]);
        $purchaseOrder->update($data);

        AppNotification::send(
            User::find($data['assigned_to']),
            fn () => __('field.notif_po_assigned_title', ['number' => $purchaseOrder->number]),
            fn () => $purchaseOrder->client->displayName(),
        );

        return back()->with('ok', __('flash.po_assigned'));
    }

    // ================= موافقات العملاء الجدد =================

    public function requests(Request $request)
    {
        $q = ClientRequest::with(['rep', 'zone', 'client']);
        if ($status = $request->string('status')->value()) {
            $q->where('status', $status);
        }

        return view('ops.requests', [
            'requests' => $q->latest()->paginate(30)->withQueryString(),
            'zones' => Zone::orderBy('code')->get(),
            'filters' => $request->only('status'),
        ]);
    }

    public function decideRequest(Request $request, ClientRequest $clientRequest)
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approved,review,rejected'],
            'zone_id' => ['nullable', 'exists:zones,id'],
            'discount' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        DB::transaction(function () use ($data, $clientRequest, $request) {
            $clientRequest->status = $data['decision'];
            $clientRequest->decided_by = $request->user()->id;
            $clientRequest->decided_at = now();
            $clientRequest->decision_note = $data['note'] ?? null;

            if ($data['decision'] === 'approved') {
                $client = Client::create([
                    'code' => Client::nextCode(),
                    'name' => $clientRequest->name,
                    'phone' => $clientRequest->phone,
                    'address' => $clientRequest->address,
                    'zone_id' => $data['zone_id'] ?? $clientRequest->zone_id,
                    'category' => 'grow',
                    'status' => 'active',
                    'discount' => ($data['discount'] ?? 0) / 100,
                    'is_new' => true,
                    'has_docs' => $clientRequest->has_docs,
                    'photo_path' => $clientRequest->photo_path,
                    'docs_path' => $clientRequest->docs_path,
                    'docs_type' => $clientRequest->docs_type,
                    'created_by' => $clientRequest->created_by,
                ]);
                $clientRequest->client_id = $client->id;

                AppNotification::send(
                    $clientRequest->rep,
                    fn () => __('field.notif_client_approved_title', ['name' => $clientRequest->name]),
                    fn () => __('field.notif_client_approved_body'),
                );
            } elseif ($data['decision'] === 'review') {
                AppNotification::send(
                    $clientRequest->rep,
                    fn () => __('field.notif_client_review_title', ['name' => $clientRequest->name]),
                    fn () => $data['note'] ?? __('field.notif_client_review_body'),
                );
            } else {
                AppNotification::send(
                    $clientRequest->rep,
                    fn () => __('field.notif_client_rejected_title', ['name' => $clientRequest->name]),
                    fn () => $data['note'] ?? __('field.notif_client_rejected_body'),
                    false,
                );
            }

            $clientRequest->save();
        });

        return back()->with('ok', __('flash.decision_recorded'));
    }

    // ================= التراكينج =================

    public function tracking(Request $request)
    {
        $userId = $request->integer('user');
        $date = $request->date('date') ?? today();

        $q = TrackEvent::with('user')->whereDate('happened_at', $date);
        if ($userId) {
            $q->where('user_id', $userId);
        }

        return view('ops.tracking', [
            'events' => $q->orderByDesc('happened_at')->get(),
            'field' => User::whereIn('role', User::FIELD_ROLES)->get(),
            'userId' => $userId,
            'date' => $date->toDateString(),
        ]);
    }

    // ================= الفواتير =================

    public function invoices(Request $request)
    {
        $q = Invoice::with(['client', 'user']);
        if ($userId = $request->integer('user')) {
            $q->where('user_id', $userId);
        }
        if ($from = $request->string('from')->value()) {
            $q->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->string('to')->value()) {
            $q->whereDate('created_at', '<=', $to);
        }

        return view('ops.invoices', [
            'invoices' => $q->latest()->paginate(40)->withQueryString(),
            'field' => User::whereIn('role', User::FIELD_ROLES)->get(),
            'filters' => $request->only(['user', 'from', 'to']),
            'sum' => (clone $q)->sum('total'),
        ]);
    }

    public function invoice(Invoice $invoice)
    {
        abort_unless(
            request()->user()->canSeeBranch($invoice->client->branch_id), 403,
        );

        $invoice->load(['items.product', 'client', 'user', 'visit']);

        // ⚠️ الفاتورة ممكن تجمع نسب مختلفة (صنف بـ 14% وصنف معفى).
        // لو النسب اتعددت مانكتبش نسبة واحدة جنب سطر الضريبة — كتابة
        // نسبة واحدة على فاتورة مختلطة رقم غلط على مستند رسمي.
        $rates = $invoice->items
            ->filter(fn ($i) => (float) $i->tax > 0)
            ->pluck('tax_rate')->map(fn ($r) => round((float) $r, 4))->unique();

        return view('ops.invoice', [
            'inv' => $invoice,
            'taxRateLabel' => $rates->count() === 1 ? \App\Services\Tax::label($rates->first()) : '',
            'companyTaxId' => \App\Models\Setting::read('company_tax_id'),
        ]);
    }

    /** تسجيل تحصيل نقدي من عميل */
    public function collect(Request $request, Client $client)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'memo' => ['nullable', 'string', 'max:190'],
            'date' => ['nullable', 'date'],
        ]);

        Transaction::create([
            'client_id' => $client->id,
            'date' => $data['date'] ?? today(),
            'memo' => $data['memo'] ?? __('flash.memo_cash_collection'),
            'debit' => 0,
            'credit' => $data['amount'],
            'kind' => 'collection',
        ]);

        $client->recalculate();

        return back()->with('ok', __('flash.collection_recorded'));
    }
}
