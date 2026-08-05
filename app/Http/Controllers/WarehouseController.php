<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\Models\BatchLocation;
use App\Models\GoodsReceipt;
use App\Models\Location;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * المخازن: الاستلام، الترصيف على الأرفف، التحويلات، وتقرير الصلاحية.
 */
class WarehouseController extends Controller
{
    // ==================== نظرة عامة على المخزن ====================

    public function index(Request $request)
    {
        $warehouse = $this->currentWarehouse($request);

        if ($warehouse === null) {
            return view('wh.none');
        }

        // البضاعة المستلمة اللي لسه مترصّفتش — دي أهم حاجة أمين المخزن يشوفها
        $pending = $warehouse->batches()
            ->with(['product', 'receipt'])
            ->where('qty_remaining', '>', 0)
            ->get()
            ->filter(fn (Batch $b) => $b->unshelvedQty() > 0)
            ->sortBy('expires_on');

        return view('wh.index', [
            'warehouse' => $warehouse,
            'warehouses' => $this->visibleWarehouses($request),
            'pending' => $pending,
            'expiring' => $warehouse->batches()->expiringWithin(Batch::WARN_DAYS)
                ->with('product')->orderBy('expires_on')->get(),
            'expired' => $warehouse->batches()->expired()->with('product')->get(),
            'incoming' => $warehouse->incomingTransfers()->where('status', 'sent')
                ->with(['fromWarehouse', 'items'])->get(),
            'receipts' => $warehouse->receipts()->with('batches.product')->take(10)->get(),
            'locationCount' => $warehouse->locations()->count(),
            'availableUnits' => $warehouse->availableUnits(),
        ]);
    }

    // ==================== الاستلام ====================

    public function receipts(Request $request)
    {
        $warehouse = $this->currentWarehouse($request);

        return view('wh.receipts', [
            'warehouse' => $warehouse,
            'warehouses' => $this->visibleWarehouses($request),
            'receipts' => GoodsReceipt::where('warehouse_id', $warehouse?->id)
                ->with(['batches.product', 'creator', 'sourceWarehouse'])
                ->latest()->paginate(20)->withQueryString(),
            'products' => Product::where('active', true)->orderBy('code')->get(),
        ]);
    }

    /** إذن استلام جديد — بند لكل باتش */
    public function storeReceipt(Request $request)
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'received_on' => ['required', 'date'],
            'supplier' => ['nullable', 'string', 'max:150'],
            'reference' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'exists:products,id'],
            'lines.*.batch_no' => ['required', 'string', 'max:60'],
            'lines.*.produced_on' => ['nullable', 'date'],
            'lines.*.expires_on' => ['nullable', 'date'],
            'lines.*.unit' => ['nullable', 'in:piece,box,case'],
            'lines.*.qty' => ['required', 'integer', 'min:1'],
            'lines.*.cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        // ⚠️ أمين المخزن مايكتبش في مخزن غير بتاعه — الفاليديشن بتفحص
        // إن المخزن موجود، مش إنه بتاعه.
        $this->guardWarehouse($request, $data['warehouse_id']);

        $receipt = DB::transaction(function () use ($data, $request) {
            $receipt = GoodsReceipt::create([
                'number' => GoodsReceipt::nextNumber(),
                'warehouse_id' => $data['warehouse_id'],
                'received_on' => $data['received_on'],
                'status' => 'posted',
                'supplier' => $data['supplier'] ?? null,
                'reference' => $data['reference'] ?? null,
                'created_by' => $request->user()->id,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($data['lines'] as $line) {
                $product = Product::find($line['product_id']);
                $produced = $line['produced_on'] ?? null;

                // ⚠️ **وحدة الإدخال بتتضرب هنا مش في الجافاسكريبت.**
                // «5 كراتين اسبريد» بتتخزن 60 قطعة — والمخزون كله
                // بالقطعة زي ما هو. وحدة مش معرّفة للصنف = رفض،
                // مش افتراض إنها قطعة (الفرق بين 5 و360 في المخزن).
                $factor = $product->unitFactor($line['unit'] ?? 'piece');

                if ($factor === null) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'lines' => __('stock.unit_not_for_product', ['name' => $product->displayName()]),
                    ]);
                }

                $qtyPieces = (int) $line['qty'] * $factor;

                // تاريخ الانتهاء: اللي المستخدم كتبه، وإلا محسوب من الإنتاج + مدة الصلاحية
                $expires = $line['expires_on']
                    ?? ($produced ? $product->expiryFrom($produced)->toDateString() : null)
                    ?? now()->addMonths($product->shelfLife())->toDateString();

                $batch = Batch::firstOrNew([
                    'product_id' => $product->id,
                    'batch_no' => $line['batch_no'],
                    'warehouse_id' => $data['warehouse_id'],
                ]);

                $batch->fill([
                    'goods_receipt_id' => $receipt->id,
                    'produced_on' => $produced,
                    'expires_on' => $expires,
                    'cost' => $line['cost'] ?? $batch->cost,
                ]);
                // الكمية بتتزوّد — نفس الباتش ممكن يوصل على أكتر من شحنة
                $batch->qty_received = (int) $batch->qty_received + $qtyPieces;
                $batch->qty_remaining = (int) $batch->qty_remaining + $qtyPieces;
                $batch->save();

                // ⚠️ **الدوكترين: أي حركة باتش يتبعها resync.** من غير
                // السطر ده الاستلام بيزوّد الباتشات وتجميعة `stocks`
                // بتفضل صفر — وصفحة «المخازن» (اللي بتقرا التجميعة)
                // بتوري المخزن فاضي وهو مليان (اتشاف فعلاً 2026-08-04).
                \App\Services\StockCounting::resync($product->id, (int) $data['warehouse_id']);
            }

            return $receipt;
        });

        return redirect()
            ->route('wh.receipt', $receipt)
            ->with('ok', __('stock.receipt_saved', [
                'number' => $receipt->number,
                'count' => $receipt->batches()->count(),
            ]));
    }

    /**
     * تصدير إذن الاستلام CSV — باك أب قابل لإعادة الاستيراد.
     *
     * ⚠️ **الأعمدة بنفس أسماء مستورد المخزون بالظبط** (StockImporter)
     * — الملف ده بيترفع من شاشة الاستيراد (نوع «المخزون») زي ما هو
     * ويرجّع الرصيد كرصيد أول مدة بعد التفضية. أي عمود زيادة
     * (اسم الصنف، التجميعة) المستورد بيطنشه.
     */
    public function exportReceipt(Request $request, GoodsReceipt $receipt)
    {
        $this->guardWarehouse($request, $receipt->warehouse_id);

        $receipt->load(['batches.product', 'batches.locations.location', 'warehouse']);

        $rows = [];
        $rows[] = ['كود الصنف', 'الباركود', 'اسم الصنف', 'رقم الباتش', 'تاريخ الإنتاج',
            'تاريخ الصلاحية', 'الكمية', 'التجميعة', 'التكلفة', 'المخزن', 'الرف', 'محجوز'];

        foreach ($receipt->batches as $b) {
            $rows[] = [
                $b->product?->code,
                $b->product?->barcode,
                $b->product?->displayName(),
                $b->batch_no,
                $b->produced_on?->toDateString(),
                $b->expires_on?->toDateString(),
                (int) $b->qty_received,
                $b->product?->packBreakdown((int) $b->qty_received),
                (float) $b->cost,
                $receipt->warehouse?->name,
                // أول رف اترصّف عليه — المستورد بيرصّف عليه تاني
                $b->locations->first()?->location?->code,
                $b->blocked ? 1 : '',
            ];
        }

        $filename = $receipt->number.'-'.now()->format('Ymd-Hi').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            // ⚠️ الـBOM لازم — من غيره إكسل بيفتح العربي شخابيط
            fwrite($out, "\xEF\xBB\xBF");
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** صفحة إذن الاستلام — ومنها الترصيف */
    public function receipt(Request $request, GoodsReceipt $receipt)
    {
        // ⚠️ إذن استلام مخزن تاني مابيتفتحش. من غير السطر ده، أمين
        // المخزن بيفتح `/wh/receipts/<id>` لأي رقم ويشوف بضاعة مش
        // بتاعته — ويرصّفها كمان.
        $this->guardWarehouse($request, $receipt->warehouse_id);

        $receipt->load(['batches.product', 'batches.locations.location', 'warehouse', 'creator']);

        return view('wh.receipt', [
            'receipt' => $receipt,
            'locations' => Location::where('warehouse_id', $receipt->warehouse_id)
                ->where('active', true)->orderBy('stand')->orderBy('level')->get(),
        ]);
    }

    // ==================== الترصيف ====================

    /** ترصيف كمية من باتش على رف */
    public function putAway(Request $request, Batch $batch)
    {
        // ⚠️ الرف بيتحدد من `$batch->warehouse_id` مش من الطلب — يعني
        // أمين مخزن المعادي اللي بيفتح باتش المصنع كان بيرصّفه على
        // أرفف المصنع. وشاشة الإذن نفسها مفتوحة بالـid.
        $this->guardWarehouse($request, $batch->warehouse_id);

        $data = $request->validate([
            'location_code' => ['required', 'string', 'max:20'],
            'qty' => ['required', 'integer', 'min:1'],
        ]);

        $location = Location::where('warehouse_id', $batch->warehouse_id)
            ->where('code', strtoupper(trim($data['location_code'])))
            ->first();

        if ($location === null) {
            return back()->withErrors(['location_code' => __('stock.location_not_found', [
                'code' => $data['location_code'],
            ])]);
        }

        if ($error = BatchLocation::putAway($batch, $location, (int) $data['qty'])) {
            return back()->withErrors(['qty' => $error]);
        }

        return back()->with('ok', __('stock.put_away_done', [
            'qty' => $data['qty'],
            'location' => $location->code,
        ]));
    }

    /** نقل بضاعة من رف لرف */
    public function moveStock(Request $request, BatchLocation $batchLocation)
    {
        $this->guardWarehouse($request, $batchLocation->location->warehouse_id);

        $data = $request->validate([
            'location_code' => ['required', 'string', 'max:20'],
            'qty' => ['required', 'integer', 'min:1'],
        ]);

        $target = Location::where('warehouse_id', $batchLocation->location->warehouse_id)
            ->where('code', strtoupper(trim($data['location_code'])))
            ->first();

        if ($target === null) {
            return back()->withErrors(['location_code' => __('stock.location_not_found', [
                'code' => $data['location_code'],
            ])]);
        }

        if ($error = $batchLocation->moveTo($target, (int) $data['qty'])) {
            return back()->withErrors(['qty' => $error]);
        }

        return back()->with('ok', __('stock.moved_done', [
            'qty' => $data['qty'],
            'location' => $target->code,
        ]));
    }

    // ==================== الأرفف والتقرير ====================

    public function locations(Request $request)
    {
        $warehouse = $this->currentWarehouse($request);

        $locations = Location::where('warehouse_id', $warehouse?->id)
            ->with(['batchLocations.batch.product', 'batchLocations.product'])
            ->orderBy('stand')->orderBy('level')
            ->get();

        // فلتر بحالة الصلاحية
        if ($state = $request->string('state')->value()) {
            $locations = $locations->filter(
                fn (Location $l) => $l->qty() > 0 && $l->worstExpiryState() === $state
            );
        }
        if ($q = $request->string('q')->trim()->value()) {
            $locations = $locations->filter(function (Location $l) use ($q) {
                if (stripos($l->code, $q) !== false) {
                    return true;
                }

                return $l->batchLocations->contains(
                    fn (BatchLocation $bl) => stripos((string) $bl->product?->displayName(), $q) !== false
                        || stripos((string) $bl->batch?->batch_no, $q) !== false
                );
            });
        }

        return view('wh.locations', [
            'warehouse' => $warehouse,
            'warehouses' => $this->visibleWarehouses($request),
            'locations' => $locations,
            'stands' => $locations->groupBy('stand'),
            'filters' => $request->only(['state', 'q']),
        ]);
    }

    public function storeLocation(Request $request)
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'stand' => ['required', 'string', 'max:5'],
            'level' => ['required', 'integer', 'min:1', 'max:99'],
            'is_pick_face' => ['nullable', 'boolean'],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string'],
        ]);

        // ⚠️ رف جديد في مخزن مش بتاعه = خريطة مخزن حد تاني بتتغيّر
        $this->guardWarehouse($request, $data['warehouse_id']);

        Location::updateOrCreate(
            [
                'warehouse_id' => $data['warehouse_id'],
                'code' => Location::buildCode($data['stand'], (int) $data['level']),
            ],
            [
                'stand' => strtoupper($data['stand']),
                'level' => $data['level'],
                'is_pick_face' => $request->boolean('is_pick_face'),
                'capacity' => $data['capacity'] ?? null,
                'notes' => $data['notes'] ?? null,
                'active' => true,
            ],
        );

        return back()->with('ok', __('common.saved'));
    }

    /** تقرير المخزون بالأرفف والصلاحية — الأقرب انتهاءً فوق */
    public function expiryReport(Request $request)
    {
        $warehouse = $this->currentWarehouse($request);

        // ⚠️ **المصدر الباتشات مش الأرفف** (إصلاح 2026-08-05). التقرير
        // كان بيقرا `batch_locations` بس — يعني بضاعة مستلمة لسه
        // ماترصّفتش، أو رصيد أول مدة من غير أرفف، كانوا بيختفوا من
        // تقرير الصلاحية خالص والشاشة تطلع أصفار والمخزن مليان.
        // الباتش هو اللي شايل تاريخ الانتهاء، والأرفف تفصيلة جواه.
        $rows = Batch::query()
            ->where('qty_remaining', '>', 0)
            ->when($warehouse, fn ($q) => $q->where('warehouse_id', $warehouse->id))
            ->with(['product', 'locations.location'])
            ->get()
            ->sortBy(fn (Batch $b) => $b->expires_on?->timestamp ?? PHP_INT_MAX)
            ->values();

        $buckets = [
            'expired' => $rows->filter(fn ($b) => $b->expiryState() === 'expired'),
            'danger' => $rows->filter(fn ($b) => $b->expiryState() === 'danger'),
            'warn' => $rows->filter(fn ($b) => $b->expiryState() === 'warn'),
            'ok' => $rows->filter(fn ($b) => $b->expiryState() === 'ok'),
        ];

        return view('wh.expiry', [
            'warehouse' => $warehouse,
            'warehouses' => $this->visibleWarehouses($request),
            'rows' => $rows,
            'buckets' => $buckets,
        ]);
    }

    // ==================== التحويلات ====================

    public function transfers(Request $request)
    {
        $user = $request->user();

        return view('wh.transfers', [
            // ⚠️ **مفلترة بمخزن أمين المخزن.** كانت `latest()` على طول،
            // يعني أمين مخزن المعادي يشوف كل شحنة بين أي مخزنين
            // بأرقام باتشاتها وكمياتها وصلاحياتها — ويطبع ورق مخزن
            // مش بتاعه. الشحنة تخصّه لو هو الطرف المرسل أو المستقبل.
            'transfers' => StockTransfer::with([
                'fromWarehouse', 'toWarehouse', 'items.product', 'sender',
            ])
                ->when(
                    $user?->isWarehouseKeeper() && $user->warehouse_id,
                    fn ($q) => $q->where(fn ($w) => $w
                        ->where('from_warehouse_id', $user->warehouse_id)
                        ->orWhere('to_warehouse_id', $user->warehouse_id)),
                )
                ->latest()->paginate(20),
            'warehouses' => $this->visibleWarehouses($request),
            'products' => Product::where('active', true)->orderBy('code')->get(),
            // ⚠️ **الباتشات الحقيقية بتغذّي الفورم.** قبل كده كان
            // بيتكتب رقم باتش وتاريخ إنتاج بالإيد — يعني نفس الكرتونة
            // بتاخد رقم في العاشر ورقم تاني في المعادي، فترتيب الصلاحية
            // (FEFO) بيتكسر، والأهم: مافيش أي ضمان إن الكمية دي موجودة
            // أصلاً عشان تتبعت.
            // ⚠️ **مفلترة بالمخازن اللي المستخدم بيشوفها.** كانت بتحمّل
            // كل باتش قابل للبيع في الشركة وتسلسله JSON في الصفحة —
            // مع بضع آلاف باتش ده ميجابايتات في كل فتحة، ولمستخدم
            // مايقدرش يفتح الفورم أصلاً.
            'batches' => Batch::query()
                ->sellable()
                ->whereIn('warehouse_id', $this->visibleWarehouses($request)->pluck('id'))
                ->with('product:id,code,name,name_en')
                ->get(['id', 'product_id', 'warehouse_id', 'batch_no',
                    'produced_on', 'expires_on', 'qty_remaining']),
        ]);
    }

    public function storeTransfer(Request $request)
    {
        $data = $request->validate([
            'from_warehouse_id' => ['required', 'exists:warehouses,id'],
            'to_warehouse_id' => ['required', 'different:from_warehouse_id', 'exists:warehouses,id'],
            'sent_on' => ['required', 'date'],
            'carrier_name' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'exists:products,id'],
            // ⚠️ **باتش موجود مش نص حر.** الرقم اللي بيتكتب بالإيد
            // مايضمنش إن البضاعة موجودة، وكان بيخلّي التحويل يخلق
            // بضاعة من العدم.
            'lines.*.source_batch_id' => ['required', 'exists:batches,id'],
            'lines.*.unit' => ['nullable', 'in:piece,box,case'],
            'lines.*.qty' => ['required', 'integer', 'min:1'],
        ]);

        // ⚠️ التحويل بيطلّع بضاعة من مخزن. أمين المخزن مايبعتش من
        // مخزن غير بتاعه — ولا يستقبل نيابةً عن مخزن تاني.
        $this->guardWarehouse($request, $data['from_warehouse_id']);

        // ⚠️ **وحدة الإدخال بتتضرب هنا مش في الجافاسكريبت.**
        // «3 كراتين» بتتحول 36 قطعة قبل ما توصل لـ StockTransfer::send
        // — والتحويل كله بالقطعة زي ما هو. وحدة مش معرّفة للصنف = رفض.
        foreach ($data['lines'] as $i => $line) {
            $unit = $line['unit'] ?? 'piece';

            if ($unit === 'piece') {
                continue;
            }

            $product = Product::find($line['product_id']);
            $factor = $product?->unitFactor($unit);

            if ($factor === null) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'lines' => __('stock.unit_not_for_product', ['name' => $product?->displayName() ?? $line['product_id']]),
                ]);
            }

            $data['lines'][$i]['qty'] = (int) $line['qty'] * $factor;
        }

        $result = StockTransfer::send(
            $request->user(),
            (int) $data['from_warehouse_id'],
            (int) $data['to_warehouse_id'],
            $data['sent_on'],
            $data['lines'],
            $data['carrier_name'] ?? null,
            $data['notes'] ?? null,
        );

        if ($result['error']) {
            return back()->withErrors(['lines' => $result['error']])->withInput();
        }

        // ⚠️ **بيروح على ورقة إذن الصرف على طول.** الشحنة اللي مشيت
        // من غير ورق ممضي مالهاش إثبات — واللي بيبعت مش هيفتكر يطبع
        // بعدين وهو واقف جنب العربية.
        return redirect()
            ->route('wh.transfers.print', $result['transfer'])
            ->with('ok', __('stock.transfer_sent', ['number' => $result['transfer']->number]));
    }

    /** صفحة استلام التحويل — الكميات وتواريخ الإنتاج قابلة للتعديل */
    public function transfer(Request $request, StockTransfer $transfer)
    {
        $this->guardTransfer($request, $transfer);

        $transfer->load(['items.product', 'items.sourceBatch', 'fromWarehouse', 'toWarehouse', 'sender', 'receiver']);

        return view('wh.transfer', ['t' => $transfer]);
    }

    /** ورقة إذن الصرف — إمضاء أمين المخزن المرسل والمستلم */
    public function printTransfer(Request $request, StockTransfer $transfer)
    {
        $this->guardTransfer($request, $transfer);

        $transfer->load(['items.product', 'fromWarehouse', 'toWarehouse', 'sender']);

        return view('wh.transfer_print', ['t' => $transfer, 'mode' => 'issue']);
    }

    /** محضر الاستلام — المبعوت والمستلم والفرق، بإمضاء الطرفين */
    public function printTransferReceipt(Request $request, StockTransfer $transfer)
    {
        $this->guardTransfer($request, $transfer);

        if ($transfer->status !== 'received') {
            return redirect()->route('wh.transfers.print', $transfer);
        }

        $transfer->load(['items.product', 'fromWarehouse', 'toWarehouse', 'sender', 'receiver']);

        return view('wh.transfer_print', ['t' => $transfer, 'mode' => 'receipt']);
    }

    /** استلام التحويل — بيولّد إذن استلام وباتشات */
    public function receiveTransfer(Request $request, StockTransfer $transfer)
    {
        // ⚠️ **الاستلام بينزّل بضاعة في المخزن المستقبِل.** من غير
        // الحارس ده، أمين مخزن المعادي كان يقدر يستلم تحويل موجّه
        // للمصنع ويحقن فيه رصيد.
        $this->guardWarehouse($request, $transfer->to_warehouse_id);

        $data = $request->validate([
            'received' => ['nullable', 'array'],
            'received.*' => ['nullable', 'integer', 'min:0'],
            // ⚠️ المستلم بيصحّح تاريخ الإنتاج من الورقة اللي على
            // الكرتونة — التاريخ الغلط معناه صلاحية غلط وترتيب FEFO
            // غلط لكل مرة الباتش ده يخرج بعد كده.
            'produced' => ['nullable', 'array'],
            // ⚠️ `before_or_equal:today` مش زيادة: تاريخ إنتاج في
            // المستقبل بيطلّع باتش صلاحيته بعيدة كذبًا، وتاريخ قديم
            // جداً بيخلق باتش منتهي لحظة إنشاؤه — و`sellable` بتستبعده
            // بينما `resync` بتعدّه، فالرقمين يختلفوا بلا سبب ظاهر.
            'produced.*' => ['nullable', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string'],
        ]);

        $result = $transfer->receive(
            $request->user(),
            $data['received'] ?? null,
            $data['notes'] ?? null,
            $data['produced'] ?? null,
        );

        if ($result['error']) {
            return back()->withErrors(['status' => $result['error']]);
        }

        // ⚠️ **محضر الاستلام بيتطبع في كل مرة، مش لما يبقى فيه فرق بس.**
        // الاستلام المطابق من غير ورق ممضي معناه إن الخلاف اللي هيحصل
        // بعد شهر مالوش إثبات — والمطابق هو اللي محدش بيشك فيه ساعتها.
        return redirect()
            ->route('wh.transfers.receipt_print', $transfer)
            ->with('ok', __('stock.transfer_received', ['number' => $transfer->number]));
    }

    // ==================== مساعد ====================

    /**
     * المخازن اللي بتتعرض في مبدّل الشاشة.
     *
     * ⚠️ **لازم تتفلتر زي `currentWarehouse()` بالظبط.** لو المبدّل
     * بيعرض المخازن كلها وأمين المخزن بيدوس على واحد تاني، الصفحة
     * بتتحمّل تاني بنفس المخزن بتاعه — شكلها بايظ، وهو بيفتكر إن
     * السيستم مش بيستجيب.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Warehouse>
     */
    private function visibleWarehouses(Request $request)
    {
        $user = $request->user();

        return Warehouse::where('active', true)
            ->when(
                $user?->isWarehouseKeeper() && $user->warehouse_id,
                fn ($q) => $q->where('id', $user->warehouse_id),
            )
            ->orderBy('type')
            ->get();
    }

    private function currentWarehouse(Request $request): ?Warehouse
    {
        $user = $request->user();

        // ═══ أمين المخزن: مخزنه هو وبس ═══
        // ⚠️ **`?warehouse=<id>` كان بيفتح أي مخزن لأي حد.** أمين مخزن
        // المعادي كان يقدر يستلم بضاعة في مخزن المصنع، يرصّفها على
        // أرففه، ويجرده — والفرق بيطلع بعد أسبوع في تسوية محدش عارف
        // مصدرها. العمود `users.warehouse_id` كان بيتكتب ومحدش بيقراه.
        if ($user?->isWarehouseKeeper() && $user->warehouse_id) {
            return Warehouse::where('id', $user->warehouse_id)
                ->where('active', true)
                ->first();
        }

        if ($id = $request->integer('warehouse')) {
            return Warehouse::find($id);
        }

        // المخزن اللي المستخدم مسئول عنه، وإلا أول فرع
        $mine = $user
            ? Warehouse::where('manager_id', $user->id)->where('active', true)->first()
            : null;

        return $mine
            ?? Warehouse::defaultBranch()
            ?? Warehouse::where('active', true)->first();
    }

    /**
     * المخزن اللي المستخدم مسموح له يكتب فيه.
     *
     * ⚠️ **`exists:warehouses,id` مش كفاية.** الفاليديشن بتقول «المخزن
     * ده موجود» مش «المخزن ده بتاعك» — فأمين مخزن المعادي كان يبعت
     * `warehouse_id` بتاع المصنع في بودي الطلب وخلاص.
     */
    /**
     * الشحنة دي تخص المستخدم ده؟
     *
     * ⚠️ **الحارس ده على القراءة والطباعة كمان مش الكتابة بس.**
     * `guardWarehouse` بتحمي الاستلام، بس صفحة الشحنة وورقة الإذن
     * كانوا مفتوحين — فأمين مخزن يقدر يفتح شحنة بين مخزنين تانيين
     * ويطبع ورقها بأرقام الباتشات والكميات.
     */
    private function guardTransfer(Request $request, StockTransfer $transfer): void
    {
        $user = $request->user();

        if (! $user?->isWarehouseKeeper() || ! $user->warehouse_id) {
            return;
        }

        $mine = (int) $user->warehouse_id === (int) $transfer->from_warehouse_id
            || (int) $user->warehouse_id === (int) $transfer->to_warehouse_id;

        if (! $mine) {
            abort(403, __('stock.not_your_warehouse'));
        }
    }

    private function guardWarehouse(Request $request, int|string|null $warehouseId): void
    {
        $user = $request->user();

        if (! $user?->isWarehouseKeeper() || ! $user->warehouse_id) {
            return;
        }

        if ($warehouseId !== null && (int) $warehouseId !== (int) $user->warehouse_id) {
            abort(403, __('stock.not_your_warehouse'));
        }
    }
}
