<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\Models\BatchLocation;
use App\Models\GoodsReceipt;
use App\Models\Location;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\TrackEvent;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Scope;
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
            // للترصيف المباشر من الشاشة — باتشات الاستيراد القديمة مالهاش إذن
            // ⚠️ `reorder()` — العلاقة عليها `stand` ثم `level`، وإضافة
            // `code` عليهم كانت بتتجاهل وترتّب بالحامل. القايمة دي
            // دروب داون بيتدوّر فيها بالكود، فلازم يبقى هو الترتيب.
            'locationCodes' => $warehouse->locations()->reorder()->orderBy('code')->pluck('code'),
            'availableUnits' => $warehouse->availableUnits(),
            // ⚠️ الرصيد الكلي من `stocks` — «المتاح» بيعدّ المرصوف بس،
            // وبعد استيراد رصيد أول مدة كله بيبقى لسه على الأرض فالمتاح
            // صفر والمخزن مليان. الرقمين جنب بعض بيوضّحوا الصورة.
            'stockUnits' => (int) \App\Models\Stock::where('warehouse_id', $warehouse->id)->sum('qty'),
            // بلوكات FEFO (2026-08-06): البلوك المقترح لكل باتش مستني —
            // بيتملى في خانة الكود تلقائياً والحارس بيرفض أي بلوك غلط
            'suggestions' => $pending->mapWithKeys(function (Batch $b) {
                $loc = \App\Support\LifeBands::suggest($b->warehouse_id, $b);

                return [$b->id => $loc?->code];
            }),
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
    /**
     * إذن استلام من شيت (2026-08-05): المخزن والتاريخ من الفورم،
     * والأصناف من الشيت — نفس أعمدة ملف التصدير، فالباك أب بيرجع
     * يتسحب من هنا مباشرة كإذن كامل.
     *
     * ⚠️ **التحقق على كل الصفوف قبل ما يتكتب أي حاجة** — نفس دوكترين
     * شاشة الاستيراد: استيراد نصّي أسوأ من فشل كامل.
     */
    public function importReceipt(Request $request)
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'received_on' => ['required', 'date'],
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:10240'],
        ]);

        $this->guardWarehouse($request, (int) $data['warehouse_id']);

        $importer = new \App\Services\Importers\StockImporter;
        $read = $importer->read($request->file('file')->getRealPath());

        if ($read['errors'] !== [] || $read['missing'] !== []) {
            $msgs = $read['errors'];

            if ($read['missing'] !== []) {
                $msgs[] = __('import.missing_columns', ['columns' => implode('، ', $read['missing'])]);
            }

            return back()->withErrors($msgs);
        }

        $errors = [];
        foreach ($read['rows'] as $i => $row) {
            foreach ($importer->validateRow($row, $i + 2) as $e) {
                $errors[] = __('import.line_error', ['line' => $i + 2, 'error' => $e]);
            }
        }

        if ($errors !== []) {
            return back()->withErrors(array_slice($errors, 0, 12));
        }

        $receipt = GoodsReceipt::create([
            'number' => GoodsReceipt::nextNumber(),
            'warehouse_id' => (int) $data['warehouse_id'],
            'received_on' => $data['received_on'],
            'status' => 'posted',
            'reference' => $request->file('file')->getClientOriginalName(),
            'created_by' => $request->user()->id,
        ]);

        $result = $importer->applyToReceipt($read['rows'], $receipt);

        // شيت اتقري بس مطلعش منه ولا باتش (أصناف مش معروفة مثلاً)
        if ($result['batches'] === 0) {
            $receipt->delete();

            return back()->withErrors(['file' => __('stock.import_no_rows')]);
        }

        return redirect()->route('wh.receipt', $receipt)
            ->with('ok', __('stock.receipt_imported', [
                'number' => $receipt->number,
                'count' => $result['batches'],
                'qty' => number_format($result['qty']),
            ]));
    }

    /**
     * تعديل بيانات باتش من صفحة الإذن (2026-08-05): رقم الباتش
     * وتاريخي الإنتاج والانتهاء والتكلفة — **الكميات لأ.** الكمية
     * بتتحرك من الجرد والحركات بس، وتعديلها بالإيد هنا كان هيكسر
     * مطابقة الباتشات مع stocks والأرفف.
     */
    public function updateBatch(Request $request, Batch $batch)
    {
        $this->guardWarehouse($request, $batch->warehouse_id);

        $data = $request->validate([
            'batch_no' => ['required', 'string', 'max:40'],
            'produced_on' => ['nullable', 'date'],
            // ⚠️ expires_on عمود NOT NULL — أساس الـFEFO
            'expires_on' => ['required', 'date', 'after_or_equal:produced_on'],
            'cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        // نفس رقم الباتش لنفس الصنف في نفس المخزن = صف تاني بيتخلط بيه
        $dup = Batch::where('product_id', $batch->product_id)
            ->where('warehouse_id', $batch->warehouse_id)
            ->where('batch_no', $data['batch_no'])
            ->where('id', '!=', $batch->id)
            ->exists();

        if ($dup) {
            return back()->withErrors(['batch_no' => __('stock.batch_exists')]);
        }

        $batch->update([
            'batch_no' => $data['batch_no'],
            'produced_on' => $data['produced_on'] ?? null,
            'expires_on' => $data['expires_on'],
            'cost' => $data['cost'] ?? $batch->cost,
        ]);

        return back()->with('ok', __('stock.batch_updated', ['batch' => $batch->batch_no]));
    }

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

    /**
     * ترصيف جماعي من شاشة عمليات المخزن (2026-08-05).
     *
     * علّم على الباتشات ← كود رف لكل سطر أو كود موحّد فوق ← Apply.
     * كل سطر بيتنفذ لوحده والأخطاء بتتجمع — باتش واقع مايوقّعش الباقي.
     */
    public function putAwayBulk(Request $request)
    {
        $data = $request->validate([
            'rows' => ['required', 'array'],
            'rows.*.qty' => ['nullable', 'integer', 'min:0'],
            'rows.*.code' => ['nullable', 'string', 'max:20'],
            'location_code' => ['nullable', 'string', 'max:20'],
            'checked' => ['nullable', 'array'],
            'checked.*' => ['integer'],
            'only' => ['nullable', 'integer'],
        ]);

        // «ترصيف» بتاع السطر الواحد بيبعت only — والزرار الكبير بيبعت المعلّم عليهم
        $ids = $request->filled('only')
            ? [(int) $data['only']]
            : array_map('intval', $data['checked'] ?? []);

        if ($ids === []) {
            return back()->withErrors(['checked' => __('stock.no_rows_selected')]);
        }

        $done = 0;
        $doneQty = 0;
        $errors = [];

        foreach ($ids as $id) {
            $batch = Batch::find($id);

            if ($batch === null) {
                continue;
            }

            $this->guardWarehouse($request, $batch->warehouse_id);

            $row = $data['rows'][$id] ?? [];
            // كود السطر بيغلب الكود الموحّد — والفاضي بياخد الموحّد
            $code = strtoupper(trim(($row['code'] ?? '') ?: ($data['location_code'] ?? '')));
            $qty = (int) ($row['qty'] ?? 0) ?: $batch->unshelvedQty();

            if ($code === '') {
                $errors[] = __('stock.row_needs_shelf', ['batch' => $batch->batch_no]);

                continue;
            }

            $location = Location::where('warehouse_id', $batch->warehouse_id)
                ->where('code', $code)->first();

            if ($location === null) {
                $errors[] = __('stock.location_not_found', ['code' => $code]);

                continue;
            }

            if ($error = BatchLocation::putAway($batch, $location, $qty)) {
                $errors[] = $error;

                continue;
            }

            $done++;
            $doneQty += $qty;
        }

        $resp = back();

        if ($done > 0) {
            $resp->with('ok', __('stock.putaway_done_count', ['count' => $done, 'qty' => $doneQty]));
        }

        return $errors === [] ? $resp : $resp->withErrors($errors);
    }

    /**
     * ترصيف إذن استلام كامل بضغطة (2026-08-06) — كل باتش لسه
     * مترصّفش بيروح **للبلوك المطابق لعمره** أوتوماتيك (LifeBands).
     * اللي من غير تاريخ انتهاء مالوش بلوك — بيتبلغ عنه ويترصّف يدوي.
     */
    public function putAwayReceipt(Request $request, GoodsReceipt $receipt)
    {
        $this->guardWarehouse($request, $receipt->warehouse_id);

        $done = 0;
        $doneQty = 0;
        $errors = [];

        foreach ($receipt->batches as $batch) {
            $left = $batch->unshelvedQty();

            if ($left <= 0) {
                continue;
            }

            $target = \App\Support\LifeBands::suggest($batch->warehouse_id, $batch);

            if ($target === null) {
                $errors[] = __('stock.no_block_for_batch', ['batch' => $batch->batch_no]);

                continue;
            }

            // الحارس جوه putAway بيتأكد إن البلوك مطابق للنطاق برضه
            if ($error = BatchLocation::putAway($batch, $target, $left)) {
                $errors[] = $error;

                continue;
            }

            $done++;
            $doneQty += $left;
        }

        $resp = back();

        if ($done > 0) {
            $resp->with('ok', __('stock.receipt_putaway_done', ['count' => $done, 'qty' => $doneQty]));
        }

        return $errors === [] ? $resp : $resp->withErrors($errors);
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
            // بلوك FEFO — فاضي يعني رف حر بيقبل أي حاجة
            'life_band' => ['nullable', 'in:'.implode(',', array_keys(\App\Support\LifeBands::BANDS))],
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
                'life_band' => ($data['life_band'] ?? null) ?: null,
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
        // «كل المخازن» (2026-08-06) — لغير أمين المخزن (مقفول على مخزنه)
        $all = $request->query('warehouse') === 'all'
            && ! ($request->user()?->isWarehouseKeeper() && $request->user()->warehouse_id);
        $warehouse = $all ? null : $this->currentWarehouse($request);
        $visibleIds = $this->visibleWarehouses($request)->pluck('id');

        // ⚠️ **المصدر الباتشات مش الأرفف** (إصلاح 2026-08-05). التقرير
        // كان بيقرا `batch_locations` بس — يعني بضاعة مستلمة لسه
        // ماترصّفتش، أو رصيد أول مدة من غير أرفف، كانوا بيختفوا من
        // تقرير الصلاحية خالص والشاشة تطلع أصفار والمخزن مليان.
        // الباتش هو اللي شايل تاريخ الانتهاء، والأرفف تفصيلة جواه.
        $rows = Batch::query()
            ->where('qty_remaining', '>', 0)
            ->when(! $all && $warehouse, fn ($q) => $q->where('warehouse_id', $warehouse->id))
            ->when($all, fn ($q) => $q->whereIn('warehouse_id', $visibleIds))
            ->with(['product', 'locations.location', 'warehouse:id,name,name_en'])
            ->get()
            ->sortBy(fn (Batch $b) => $b->expires_on?->timestamp ?? PHP_INT_MAX)
            ->values();

        $buckets = [
            'expired' => $rows->filter(fn ($b) => $b->expiryState() === 'expired'),
            'danger' => $rows->filter(fn ($b) => $b->expiryState() === 'danger'),
            'warn' => $rows->filter(fn ($b) => $b->expiryState() === 'warn'),
            'ok' => $rows->filter(fn ($b) => $b->expiryState() === 'ok'),
        ];

        // ═══ بلوكات FEFO: اللي قعد وعمره قل عن نطاق بلوكه (2026-08-06) ═══
        // بضاعة على بلوك «سنة» بقى فاضل لها 3 شهور — تتنقل بضغطة عشان
        // التقسيمة تفضل صادقة. ده «تقرير النقل الأسبوعي» بس لايف دايماً.
        $relocations = [];

        foreach ($rows as $b) {
            $need = \App\Support\LifeBands::bandForBatch($b);

            if ($need === null) {
                continue;
            }

            foreach ($b->locations->where('qty', '>', 0) as $bl) {
                $locBand = $bl->location?->life_band;

                if ($locBand !== null && $locBand !== $need) {
                    $relocations[] = [
                        'bl' => $bl,
                        'batch' => $b,
                        'target' => \App\Support\LifeBands::suggest($b->warehouse_id, $b),
                    ];
                }
            }
        }

        return view('wh.expiry', [
            'warehouse' => $warehouse,
            'all' => $all,
            'warehouses' => $this->visibleWarehouses($request),
            'rows' => $rows,
            'buckets' => $buckets,
            'relocations' => $relocations,
            'bucketFilter' => $request->string('bucket')->value(),
        ]);
    }

    // ==================== التحويلات ====================

    public function transfers(Request $request)
    {
        $user = $request->user();

        // ⚠️ **مفلترة بمخزن أمين المخزن.** كانت `latest()` على طول،
        // يعني أمين مخزن المعادي يشوف كل شحنة بين أي مخزنين
        // بأرقام باتشاتها وكمياتها وصلاحياتها — ويطبع ورق مخزن
        // مش بتاعه. الشحنة تخصّه لو هو الطرف المرسل أو المستقبل.
        $base = fn () => StockTransfer::query()
            ->when(
                $user?->isWarehouseKeeper() && $user->warehouse_id,
                fn ($q) => $q->where(fn ($w) => $w
                    ->where('from_warehouse_id', $user->warehouse_id)
                    ->orWhere('to_warehouse_id', $user->warehouse_id)),
            )
            ->when($request->string('q')->trim()->value(),
                fn ($q, $s) => $q->where('number', 'like', "%$s%"))
            ->when($request->integer('wh'), fn ($q, $w) => $q->where(fn ($x) => $x
                ->where('from_warehouse_id', $w)->orWhere('to_warehouse_id', $w)))
            // فلتر الاتجاه (١٤/٨): مخزن↔مخزن / مندوب←مخزن / مندوب←مندوب
            ->when(
                array_key_exists($request->string('kind')->value(), StockTransfer::KINDS),
                fn ($q) => $q->where('kind', $request->string('kind')->value()),
            );

        $q = $base()->with([
            'fromWarehouse', 'toWarehouse', 'items.product', 'sender',
            // الأطراف الميدانية والسبب واللي عمل المستند — بيتعرضوا في الجدول
            'fromUser', 'toUser', 'creator',
        ]);

        if ($status = $request->string('status')->value()) {
            $q->where('status', $status);
        }

        return view('wh.transfers', [
            'transfers' => $q->latest()->paginate(20)->withQueryString(),
            // KPIs من نفس الأساس المفلتر — رقم فوق وجدول تحت من نطاقين = شاشة بتكدب
            'kpi' => [
                'total' => $base()->count(),
                'sent' => $base()->where('status', 'sent')->count(),
                'received' => $base()->where('status', 'received')->count(),
                // ⚠️ «في الطريق» = مخزن لمخزن بس — التحويلات الميدانية
                // بتتنفّذ في خطوة واحدة فمالهاش لحظة على الطريق
                'transit_units' => (int) StockTransferItem::whereHas('transfer',
                    fn ($t) => $t->where('status', 'sent')->where('kind', 'wh_wh'))->sum('qty_sent'),
                'van' => $base()->whereIn('kind', ['rep_wh', 'rep_rep'])->count(),
            ],
            'warehouses' => $this->visibleWarehouses($request),
            'filters' => $request->only(['q', 'status', 'wh', 'kind']),
        ]);
    }

    /**
     * صفحة تحويل جديد (2026-08-06) — بدل الدايالوج: بحث بالصور،
     * جدول بهيدر ثابت، وملخصات لايف. بتبعت لنفس `storeTransfer`.
     */
    public function newTransfer(Request $request)
    {
        return view('wh.transfer_new', [
            'warehouses' => $this->visibleWarehouses($request),
            'products' => Product::where('active', true)->orderBy('code')->get(),
            // ⚠️ **الباتشات الحقيقية بتغذّي الفورم** — الباتش لازم يكون
            // موجود فعلاً في المخزن المرسل، مش رقم بيتكتب بالإيد.
            // ومفلترة بالمخازن اللي المستخدم بيشوفها.
            'batches' => Batch::query()
                ->sellable()
                ->whereIn('warehouse_id', $this->visibleWarehouses($request)->pluck('id'))
                ->orderBy('expires_on')
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
            // ⚠️ **السبب إجباري لكل التحويلات** (قرار ١٤/٨، امتداد لطلب
            // المالك على التحويل الميداني). تحويل مخزن لمخزن من غير سبب
            // مكتوب كان نفس المشكلة بالظبط: بضاعة بتتنقل ومحدش يعرف ليه
            // بعد شهر. `notes` حقل حر مش بديل — مش إجباري ومحدش بيقراه.
            'reason' => ['required', 'string', 'min:3', 'max:300'],
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
            $data['reason'],
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

    // ============ التحويل من عربية مندوب (١٤ أغسطس ٢٠٢٦) ============

    /**
     * شاشة «تحويل من عربية» — مندوب ← مخزن، أو مندوب ← مندوب.
     *
     * ⚠️ **الأصناف من العهدة الحية مش من الكتالوج.** المالك طلبها
     * بالنص: «أحوّل بضاعة موجودة فعلاً … اختار حسب المتوفر مع المندوب».
     * فالمنتقي بيتغذّى من `custody_items` بالمتاح والباتش وشارة المصدر،
     * والكتالوج العام مش موجود في الشاشة دي أصلاً.
     *
     * ⚠️ العهدة من `currentCustody()` — عقيدة ١٠/٨.
     */
    public function newVanTransfer(Request $request)
    {
        $actor = $request->user();

        // نفس سكوب بورد العربيات: المدير فريقه، والأدمن الكل
        $reps = User::fieldVisibleTo(User::whereIn('role', User::FIELD_WORK_ROLES))
            ->where('active', true)
            ->orderBy('name')
            ->get();

        $lines = [];

        foreach ($reps as $rep) {
            $custody = $rep->currentCustody();

            if ($custody === null || $custody->status === 'closed') {
                continue;
            }

            $custody->load(['items.product', 'items.batch']);

            // ⚠️ شارة المصدر من `CustodySource` مش من البند نفسه —
            // البنود الأقدم من عمود `source` بتقول «غير محدد» مع إن
            // إذن التسليم اللي جابها مربوط بالعهدة أصلاً (بلاغ المالك
            // ١٥ أغسطس). كويريز ثابتة للعهدة، مش للبند.
            $srcMap = \App\Support\CustodySource::forCustody($custody, $custody->items);

            foreach ($custody->items as $item) {
                if ($item->remaining() <= 0) {
                    continue;
                }

                $lines[] = [
                    'id' => (int) $item->id,
                    // «rep» عشان المنتقي يفلتر بالمندوب من غير راوند تريب
                    'rep' => (int) $rep->id,
                    'code' => (string) ($item->product?->code ?? ''),
                    'name' => $item->product?->displayName() ?? '—',
                    'name_ar' => (string) ($item->product?->name ?? ''),
                    'name_en' => (string) ($item->product?->name_en ?? ''),
                    'image' => $item->product?->imageSrc(),
                    'avail' => $item->remaining(),
                    'batch' => $item->batchLabel(),
                    'exp' => $item->batch?->expires_on?->toDateString(),
                    // ⚠️ مخزن الباتش — البضاعة بترجع لمخزنها هي، والفلتر
                    // ده هو اللي بيمنع اختيار وجهة غلط من الأساس
                    'wh' => (int) ($item->batch?->warehouse_id ?? 0),
                    'src' => $srcMap->keyFor($item),
                    'src_label' => $srcMap->labelFor($item),
                    'src_ref' => $srcMap->refFor($item),
                ];
            }
        }

        return view('wh.transfer_van', [
            'reps' => $reps,
            'warehouses' => $this->visibleWarehouses($request),
            'lines' => $lines,
            'actor' => $actor,
        ]);
    }

    /**
     * تنفيذ التحويل الميداني — كل الحركة في `StockTransfer::sendFromCustody`.
     *
     * هنا: فاليديشن + **سبب إجباري** + حارس `Scope` على الطرفين +
     * الحدث على تايم لاين المندوب. مفيش أي كتابة مخزون في الكنترولر.
     */
    public function storeVanTransfer(Request $request)
    {
        $data = $request->validate([
            'kind' => ['required', 'in:rep_wh,rep_rep'],
            'from_user_id' => ['required', 'exists:users,id'],
            'to_warehouse_id' => ['required_if:kind,rep_wh', 'nullable', 'exists:warehouses,id'],
            // ⚠️ **من غير `different:from_user_id`.** السيلكت المخفي بيتبعت
            // برضه (`display:none` مش بيمنع الإرسال)، فتحويل «مندوب ←
            // مخزن» كان بيترفض برسالة عن حقل مش ظاهر أصلاً. الشاشة
            // بتعطّل السيلكت المخفي، والحالة الحقيقية («نفس المندوب»)
            // بترفض في `sendFromCustody` برسالة مفهومة.
            'to_user_id' => ['required_if:kind,rep_rep', 'nullable', 'exists:users,id'],
            // ⚠️ **السبب إجباري** — قرار المالك بالنص: «لازم أكتب
            // السبب». من غيره بضاعة بتختفي من عربية ومحدش يعرف ليه
            // بعد أسبوع، والمندوب بيتحاسب على رقم مالوش تفسير.
            'reason' => ['required', 'string', 'min:3', 'max:300'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.custody_item_id' => ['required', 'integer'],
            'lines.*.qty' => ['required', 'integer', 'min:1'],
        ]);

        $actor = $request->user();
        $fromRep = User::find($data['from_user_id']);
        $toRep = ($data['to_user_id'] ?? null) ? User::find($data['to_user_id']) : null;

        // ⚠️⚠️ **الحارس على الطرفين.** `exists:users,id` بتقول إن
        // اليوزر موجود وبس — مش إنه ميداني ولا أكتيف ولا تحت نفس
        // المدير. من غير ده مدير قناة كان يقدر يسحب بضاعة من عربية
        // مندوب فريق تاني ويحطها في عربية مندوبه.
        Scope::assertRep($actor, $fromRep);

        if ($data['kind'] === 'rep_rep') {
            Scope::assertRep($actor, $toRep);
        }

        $toWarehouse = ($data['to_warehouse_id'] ?? null)
            ? Warehouse::find($data['to_warehouse_id'])
            : null;

        // أمين المخزن (لو الأدمن منحه الأكشن) بيستقبل في مخزنه هو بس
        if ($data['kind'] === 'rep_wh') {
            $this->guardWarehouse($request, $data['to_warehouse_id']);
        }

        $result = StockTransfer::sendFromCustody(
            $actor,
            $fromRep,
            $data['kind'],
            $toWarehouse,
            $toRep,
            $data['lines'],
            $data['reason'],
            $data['notes'] ?? null,
        );

        if ($result['error'] !== null) {
            return back()->withErrors(['lines' => $result['error']])->withInput();
        }

        /** @var StockTransfer $transfer */
        $transfer = $result['transfer'];

        // الحدث على تايم لاين المندوب — النوع في `TrackEvent::TYPES`
        // و`enums.track` في ملفي اللغة (الفخ الموثّق)
        TrackEvent::log(
            $fromRep,
            'custody_transfer',
            __('stock.event_van_transfer', ['number' => $transfer->number]),
            __('stock.event_van_transfer_sub', [
                'qty' => $transfer->qtySent(),
                'to' => $transfer->toLabel(),
                'reason' => $transfer->reason,
            ]),
        );

        if ($toRep !== null) {
            TrackEvent::log(
                $toRep,
                'custody_transfer',
                __('stock.event_van_transfer_in', ['number' => $transfer->number]),
                __('stock.event_van_transfer_in_sub', [
                    'qty' => $transfer->qtySent(),
                    'from' => $transfer->fromLabel(),
                ]),
            );
        }

        // ⚠️ على ورقة التحويل على طول — البضاعة اللي مشيت من غير ورق
        // ممضي مالهاش إثبات، واللي بيحوّل مش هيفتكر يطبع بعدين
        return redirect()
            ->route('wh.transfers.print', $transfer)
            ->with('ok', __('stock.transfer_sent', ['number' => $transfer->number]));
    }

    /** صفحة استلام التحويل — الكميات وتواريخ الإنتاج قابلة للتعديل */
    public function transfer(Request $request, StockTransfer $transfer)
    {
        $this->guardTransfer($request, $transfer);

        // ⚠️ **التحويل الميداني مالوش شاشة استلام.** بيتنفّذ في خطوة
        // واحدة (البضاعة بتتسلّم إيد بإيد)، فالشاشة دي كانت هتوري
        // فورم استلام لمستند مقفول — والزرار بياخد 422 من `receive`.
        if ($transfer->isVan()) {
            return redirect()->route('wh.transfers.print', $transfer);
        }

        $transfer->load(['items.product', 'items.sourceBatch', 'fromWarehouse', 'toWarehouse', 'sender', 'receiver']);

        return view('wh.transfer', ['t' => $transfer]);
    }

    /** ورقة إذن الصرف — إمضاء أمين المخزن المرسل والمستلم */
    public function printTransfer(Request $request, StockTransfer $transfer)
    {
        $this->guardTransfer($request, $transfer);

        $transfer->load([
            'items.product', 'fromWarehouse', 'toWarehouse', 'sender',
            'fromUser', 'toUser', 'creator',
        ]);

        return view('wh.transfer_print', ['t' => $transfer, 'mode' => 'issue']);
    }

    /** محضر الاستلام — المبعوت والمستلم والفرق، بإمضاء الطرفين */
    public function printTransferReceipt(Request $request, StockTransfer $transfer)
    {
        $this->guardTransfer($request, $transfer);

        // التحويل الميداني ورقته واحدة (إذن صرف بإمضاء الطرفين)
        if ($transfer->status !== 'received' || $transfer->isVan()) {
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
