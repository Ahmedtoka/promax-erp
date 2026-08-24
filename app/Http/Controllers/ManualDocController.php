<?php

namespace App\Http\Controllers;

use App\Exceptions\Rejected;
use App\Exceptions\StockShortage;
use App\Models\AppNotification;
use App\Models\Client;
use App\Models\ClientReturn;
use App\Models\GiftHandout;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\TrackEvent;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Scope;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * المستند اليدوي — فاتورة/مرتجع/هدية باسم المندوب (٢١ أغسطس ٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * طلب المالك بالنص: «ادخل اختار المندوب واختار العميل واختار تاريخ
 * الفاتورة واحط الرقم المسلسل واختار المنتجات واعمل البيعة وتنزل
 * عند المندوب وعند العميل وتسمع في كل مكان زيها زي الفاتورة العادية
 * كأن المندوب عملها بالظبط» — عشان الفواتير الورقية القديمة اللي
 * اتعملت وقت الباج من غير ما يلف موبايل موبايل.
 *
 * ⚠️ **نفس عقيدة فلو الأبلكيشن بالحرف**: الخصم من عهدة المندوب
 * بالـFEFO جوه نفس الترانزاكشن، التسعير من `Pricing::quote` بشروط
 * العميل (مش سعر بإيد حد)، الضريبة سطر بسطر، القيد بالإجمالي
 * الشامل، والكاش بقيد تحصيل مقابل. الفرق الوحيد: مفيش زيارة
 * (المرساة هنا هي الأدمن الفاعل — واسمه متسجل في الميمو)، والتاريخ
 * بيتكتب بتاريخ الورقية مش النهاردة.
 *
 * ⚠️ **المرتجع من `App\Services\Returns` نفسها** — نفس الخدمة اللي
 * الأبلكيشن بينده عليها، بمصدر `erp` وبعدين بنأرّخ المستند وقيوده.
 */
class ManualDocController extends Controller
{
    /** مناديب الميدان المتاحين للفاعل — نفس سكوب خطط السير */
    private function reps(Request $request)
    {
        return User::fieldVisibleTo(\App\Models\Branch::scope(User::query()))
            ->whereIn('role', User::FIELD_WORK_ROLES)
            ->where('active', true)
            ->orderBy('name')
            ->get();
    }

    public function index(Request $request)
    {
        return view('ops.manual_doc', [
            'reps' => $this->reps($request),
            // ⚠️ المحافظة والمنطقة والعنوان معانا (٢٤/٨) — البحث بيلاقيهم
            // والليستة بتعرضهم عشان المالك يتأكد إنه ماسك الفرع الصح
            'clients' => Client::visibleTo(Client::query()->where('status', 'active'), $request->user())
                ->with(['group', 'zone'])
                ->orderBy('name')
                ->get(['id', 'name', 'name_en', 'group_id', 'payment_terms',
                    'zone_id', 'governorate', 'address']),
        ]);
    }

    /**
     * GET /ops/manual/data?user_id=&client_id=
     *
     * داتا الفورم بعد اختيار المندوب والعميل: الأصناف بسعر العميل
     * المعروض + المتاح في عهدة المندوب + رصيد هداياه + سياسات
     * المرتجع المسموحة + شروط الدفع.
     */
    public function data(Request $request)
    {
        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'client_id' => ['required', 'exists:clients,id'],
        ]);

        $rep = User::findOrFail($data['user_id']);
        $client = Client::findOrFail($data['client_id']);

        Scope::assertRep($request->user(), $rep);
        Scope::assertClient($request->user(), $client);

        $custody = $rep->currentCustody();

        $remaining = [];
        $giftLeft = [];

        if ($custody !== null) {
            $custody->loadMissing('items.product');

            foreach ($custody->items as $i) {
                $remaining[$i->product_id] = ($remaining[$i->product_id] ?? 0) + $i->remaining();
                $giftLeft[$i->product_id] = ($giftLeft[$i->product_id] ?? 0) + $i->giftLeft();
            }
        }

        $disc = $client->effectiveDiscount();

        $row = function ($p) use ($client, $disc, $remaining, $giftLeft) {
            $list = \App\Services\Pricing::listPriceFor($client, $p);

            return [
                'id' => $p->id,
                'code' => $p->code,
                'name' => $p->displayName(),
                'unit' => $p->unitLabel(),
                'image' => $p->imageSrc(),
                // للعرض بس — الحفظ بيسعّر من Pricing::quote بالحرف
                'price' => round($list * (1 - $disc), 2),
                'list_price' => $list,
                'have' => (int) ($remaining[$p->id] ?? 0),
                'gift_left' => (int) ($giftLeft[$p->id] ?? 0),
                // للإضافة بالعائلة (٢٢/٨): زرار العائلة بينزّل كل
                // أصنافها، كل صنف بعلبة كاملة (box_units — وإلا 12)
                'family' => (string) ($p->family ?? ''),
                'family_label' => $p->familyLabel(),
                'box' => (int) ($p->box_units ?: 12),
            ];
        };

        // ⚠️ **البيع والهدية من عهدة المندوب بس** (بلاغ المالك ٢١/٨) —
        // «مش عاوز غير المنتجات اللي في عهدته». المرتجع كتالوج العميل
        // كله زي الأبلكيشن: العميل بيرجّع بضاعة عنده هو مش في العربية.
        $custodyProducts = collect($remaining)
            ->filter(fn ($q) => $q > 0)
            ->keys()
            ->merge(collect($giftLeft)->filter(fn ($q) => $q > 0)->keys())
            ->unique()
            ->values();

        $products = \App\Models\Product::sellable()
            ->whereIn('id', $custodyProducts)
            ->orderBy('name')
            ->get()
            ->map($row)
            ->filter(fn ($r) => $r['list_price'] > 0)
            ->values();

        $retProducts = \App\Models\Product::sellable()
            ->orderBy('name')
            ->get()
            ->map($row)
            ->filter(fn ($r) => $r['list_price'] > 0)
            ->values();

        return response()->json([
            'custody_open' => $custody !== null && $custody->status !== 'closed',
            'terms' => $client->paymentTerms(),
            'pay_choice' => $client->paymentIsChoice(),
            'discount_pct' => round($disc * 100, 1),
            'policies' => array_map(fn ($p) => [
                'code' => $p,
                'label' => __('field.return_policy_'.$p),
            ], $client->returnPolicies()),
            'products' => $products,
            'ret_products' => $retProducts,
        ]);
    }

    /** الفاعل + المندوب + العميل + التاريخ — الفحوصات المشتركة */
    private function anchors(Request $request, array $data): array
    {
        $rep = User::findOrFail($data['user_id']);
        $client = Client::findOrFail($data['client_id']);

        Scope::assertRep($request->user(), $rep);
        Scope::assertClient($request->user(), $client);

        // ⚠️ نص النهار مش منتصف الليل — فروق التوقيت ماتنقلش
        // المستند ليوم تاني في أي عرض
        $date = Carbon::parse($data['doc_date'])->setTime(12, 0);

        return [$rep, $client, $date];
    }

    /** POST /ops/manual/invoice */
    public function storeInvoice(Request $request)
    {
        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'client_id' => ['required', 'exists:clients,id'],
            'doc_date' => ['required', 'date', 'before_or_equal:today'],
            // ⚠️ السيريال إجباري — ده أصلاً سبب الشاشة: مطابقة
            // الورقيات القديمة (نفس قرار إجباريته في الأبلكيشن)
            'paper_ref' => ['required', 'string', 'max:30'],
            'payment' => ['nullable', 'in:cash,credit'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', new \App\Rules\SellableProduct],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:9999'],
        ]);

        [$rep, $client, $date] = $this->anchors($request, $data);

        $custody = $rep->currentCustody();

        if ($custody === null || $custody->status === 'closed') {
            return back()->withErrors(['items' => __('ops.md_no_custody')])->withInput();
        }

        // ⚠️ نفس ورقية مسجلة قبل كده لنفس العميل = غالباً دبل إدخال
        if (Invoice::where('client_id', $client->id)
            ->where('paper_ref', $data['paper_ref'])->exists()) {
            return back()->withErrors(['paper_ref' => __('ops.md_paper_dup')])->withInput();
        }

        // ⚠️ كاش/آجل من تعريف العميل — نفس حارس الأبلكيشن بالحرف
        $terms = $client->paymentTerms();
        $payment = $terms === Client::PAY_BOTH
            ? (in_array($data['payment'] ?? null, [Client::PAY_CASH, Client::PAY_CREDIT], true)
                ? $data['payment'] : Client::PAY_CASH)
            : $terms;

        $qtyByProduct = [];
        foreach ($data['items'] as $i) {
            $qtyByProduct[$i['product_id']] = ($qtyByProduct[$i['product_id']] ?? 0) + (int) $i['qty'];
        }

        try {
            $invoice = DB::transaction(function () use ($data, $rep, $client, $custody, $qtyByProduct, $payment, $date, $request) {
                $deduction = $custody->deductWithBatches($qtyByProduct);

                if ($deduction['error']) {
                    throw new StockShortage($deduction['error']);
                }

                $subtotal = 0;
                $costTotal = 0;
                $rows = [];
                $priceList = $client->priceList();

                foreach ($deduction['lines'] as $line) {
                    /** @var \App\Models\CustodyItem $item */
                    $item = $line['item'];
                    $qty = (int) $line['qty'];

                    $quote = \App\Services\Pricing::quote($client, $item->product, $item->batch, $qty);

                    if ($quote['list_price'] <= 0) {
                        throw new Rejected(__('api.product_not_priced', [
                            'product' => $item->product->displayName(),
                        ]));
                    }

                    $taxRate = \App\Services\Tax::rate($client, $item->product);
                    $lineTax = \App\Services\Tax::on($quote['line_total'], $client, $item->product);

                    $rows[] = [
                        'product_id' => $item->product_id,
                        'batch_id' => $item->batch_id,
                        'qty' => $qty,
                        'list_price' => $quote['list_price'],
                        'price' => $quote['unit_price'],
                        'unit_cost' => $quote['unit_cost'],
                        'total' => $quote['line_total'],
                        'tax_rate' => $taxRate,
                        'tax' => $lineTax,
                    ];

                    $subtotal += round($quote['list_price'] * $qty, 2);
                    $costTotal += $quote['line_cost'];
                }

                $sums = \App\Services\Tax::totals($rows);
                $net = $sums['net'];
                $taxTotal = $sums['tax'];
                $grandTotal = $sums['grand'];

                $invoice = Invoice::create([
                    'number' => Invoice::nextNumber(),
                    ...(\Illuminate\Support\Facades\Schema::hasColumn('invoices', 'paper_ref')
                        ? ['paper_ref' => $data['paper_ref']] : []),
                    'client_id' => $client->id,
                    // ⚠️ **باسم المندوب مش الأدمن** — تسمع في مبيعاته
                    // وتصفيته وحوافزه زي ما لو هو اللي عملها
                    'user_id' => $rep->id,
                    'visit_id' => null,
                    'payment' => $payment,
                    'price_list' => $priceList,
                    'subtotal' => $subtotal,
                    'discount_pct' => $client->effectiveDiscount(),
                    'discount_source' => $client->discountSourceKey(),
                    'discount' => round($subtotal - $net, 2),
                    'total' => $net,
                    'tax_total' => $taxTotal,
                    'grand_total' => $grandTotal,
                    'eta_status' => $taxTotal > 0 ? 'ready' : 'none',
                    'cost_total' => round($costTotal, 2),
                ]);

                // ⚠️ **بتاريخ الورقية — على مستوى الداتابيز مباشرة**
                // (بلاغ ٢١/٨: التاريخ ماكانش بيتسجل). تعديل الموديل
                // بعد الإنشاء ممكن يتداس عليه — الكويري المباشرة
                // مفيش حاجة تعكسها.
                Invoice::whereKey($invoice->id)->update(['created_at' => $date]);

                foreach ($rows as $r) {
                    InvoiceItem::create($r + ['invoice_id' => $invoice->id]);
                }

                $consigned = $client->isConsignment();

                $tx = Transaction::create([
                    'client_id' => $client->id,
                    'date' => $date->toDateString(),
                    'memo' => ($consigned
                        ? __('flash.memo_consignment', [
                            'number' => $invoice->number,
                            'amount' => number_format($grandTotal),
                        ])
                        : __('flash.memo_invoice', [
                            'number' => $invoice->number,
                            'user' => $rep->displayName(),
                        ])).' — '.__('ops.md_by_admin', ['user' => $request->user()->displayName()]),
                    'debit' => $consigned ? 0 : $grandTotal,
                    'credit' => 0,
                    'tax' => $consigned ? 0 : $taxTotal,
                    'kind' => $consigned ? 'consignment' : 'sale',
                    'source_type' => Invoice::class,
                    'source_id' => $invoice->id,
                ]);
                Transaction::whereKey($tx->id)->update(['created_at' => $date]);

                if ($payment === 'cash' && ! $consigned) {
                    $tx2 = Transaction::create([
                        'client_id' => $client->id,
                        'date' => $date->toDateString(),
                        'memo' => __('flash.memo_cash_with_invoice', ['number' => $invoice->number]),
                        'debit' => 0,
                        'credit' => $grandTotal,
                        'tax' => $taxTotal,
                        'kind' => 'collection',
                        'source_type' => Invoice::class,
                        'source_id' => $invoice->id,
                    ]);
                    Transaction::whereKey($tx2->id)->update(['created_at' => $date]);
                }

                $client->recalculate();

                return $invoice;
            });
        } catch (StockShortage|Rejected $e) {
            return back()->withErrors(['items' => $e->getMessage()])->withInput();
        }

        // حدث على تايم لاين المندوب + إشعار — يعرف إن فيه فاتورة
        // اتسجلت عليه من المكتب
        TrackEvent::log($rep, 'sale',
            __('field.event_invoice', ['number' => $invoice->number, 'client' => $client->displayName()]),
            __('ops.md_by_admin', ['user' => $request->user()->displayName()]));

        AppNotification::send(
            $rep,
            fn () => __('field.notif_manual_invoice_title', ['number' => $invoice->number]),
            fn () => __('field.notif_manual_invoice_body', [
                'client' => $client->displayName(),
                'amount' => number_format((float) $invoice->grand_total, 2),
                'date' => $date->format('Y-m-d'),
            ]),
        );

        // ⚠️ **تحويل على صفحة الفاتورة نفسها** (بلاغ ٢١/٨: «قالي
        // اتعمل ومظهرتش») — بدل رسالة والمستخدم يدوّر في الليستة
        // على فاتورة بتاريخ قديم مدفونة صفحات ورا.
        return redirect()->route('ops.invoice', $invoice)
            ->with('ok', __('flash.md_invoice_done', [
                'number' => $invoice->number,
                'amount' => number_format((float) $invoice->grand_total, 2),
            ]));
    }

    /** POST /ops/manual/return */
    public function storeReturn(Request $request)
    {
        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'client_id' => ['required', 'exists:clients,id'],
            'doc_date' => ['required', 'date', 'before_or_equal:today'],
            'policy' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', new \App\Rules\SellableProduct],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:9999'],
            'items.*.condition' => ['nullable', 'in:good,damaged'],
        ]);

        [$rep, $client, $date] = $this->anchors($request, $data);

        try {
            // ⚠️ نفس خدمة الأبلكيشن بالحرف — السياسة والسقف والسعر
            // من سطور الفواتير الأصلية والقيود والعهدة كله جواها
            $doc = \App\Services\Returns::create(
                client: $client,
                items: $data['items'],
                policy: $data['policy'],
                rep: $rep,
                visit: null,
                note: trim(($data['note'] ?? '').' — '.__('ops.md_by_admin', [
                    'user' => $request->user()->displayName(),
                ])),
                idemKey: null,
                actor: $request->user(),
                source: 'erp',
            );

            // بتاريخ الورقية — المستند وقيوده، بكويري مباشرة (نفس
            // إصلاح تأريخ الفاتورة ٢١/٨)
            DB::transaction(function () use ($doc, $date) {
                ClientReturn::whereKey($doc->id)->update(['created_at' => $date]);

                Transaction::where('source_type', ClientReturn::class)
                    ->where('source_id', $doc->id)
                    ->update([
                        'date' => $date->toDateString(),
                        'created_at' => $date,
                    ]);
            });
        } catch (Rejected $e) {
            return back()->withErrors(['items' => $e->getMessage()])->withInput();
        }

        TrackEvent::log($rep, 'return',
            __('field.event_return', ['client' => $client->displayName()]),
            __('ops.md_by_admin', ['user' => $request->user()->displayName()]));

        AppNotification::send(
            $rep,
            fn () => __('field.notif_manual_return_title', ['number' => $doc->number]),
            fn () => __('field.notif_manual_return_body', [
                'client' => $client->displayName(),
                'date' => $date->format('Y-m-d'),
            ]),
        );

        return back()->with('ok', __('flash.md_return_done', ['number' => $doc->number]));
    }

    /** POST /ops/manual/gift */
    public function storeGift(Request $request)
    {
        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'client_id' => ['required', 'exists:clients,id'],
            'doc_date' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:300'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', new \App\Rules\SellableProduct],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:9999'],
        ]);

        [$rep, $client, $date] = $this->anchors($request, $data);

        $custody = $rep->currentCustody();

        if ($custody === null || $custody->status === 'closed') {
            return back()->withErrors(['items' => __('ops.md_no_custody')])->withInput();
        }

        try {
            DB::transaction(function () use ($data, $custody, $rep, $client, $date, $request) {
            foreach ($data['items'] as $row) {
                // ⚠️ نفس قاعدة الأبلكيشن بالحرف: القفل قبل الفحص،
                // والصف اللي **فيه هدايا فعلاً** مش أول صف
                $line = $custody->items()
                    ->where('product_id', $row['product_id'])
                    ->orderByRaw('(gift_assigned - gift_given) DESC')
                    ->lockForUpdate()
                    ->first();

                if ($line === null || $line->giftLeft() < (int) $row['qty']) {
                    // ⚠️ الرمي بيرجّع الترانزاكشن كلها — مفيش نص هدية
                    throw new Rejected(__('field.gift_not_enough', [
                        'left' => $line?->giftLeft() ?? 0,
                    ]));
                }

                $line->increment('gift_given', (int) $row['qty']);

                $handout = GiftHandout::create([
                    'custody_id' => $custody->id,
                    'user_id' => $rep->id,
                    'product_id' => (int) $row['product_id'],
                    'client_id' => $client->id,
                    'batch_id' => $line->batch_id,
                    'qty' => (int) $row['qty'],
                    'reason' => __('ops.md_gift_reason'),
                    'note' => trim(($data['note'] ?? '').' — '.__('ops.md_by_admin', [
                        'user' => $request->user()->displayName(),
                    ])),
                ]);

                GiftHandout::whereKey($handout->id)->update(['created_at' => $date]);
            }
            });
        } catch (Rejected $e) {
            return back()->withErrors(['items' => $e->getMessage()])->withInput();
        }

        TrackEvent::log($rep, 'gift',
            __('ops.md_gift_event', ['client' => $client->displayName()]),
            __('ops.md_by_admin', ['user' => $request->user()->displayName()]));

        AppNotification::send(
            $rep,
            fn () => __('field.notif_manual_gift_title'),
            fn () => __('field.notif_manual_gift_body', [
                'client' => $client->displayName(),
                'date' => $date->format('Y-m-d'),
            ]),
        );

        return back()->with('ok', __('flash.md_gift_done'));
    }
}
