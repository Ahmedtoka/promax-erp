<?php

namespace App\Services;

use App\Http\Controllers\OpsController;
use App\Models\PurchaseOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * الطلب مقابل المخزون — نواقص أوامر التوريد المفتوحة (٩/١١/٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * طلب المالك: «مديرين القنوات بينزّلوا أوامر — عاوز أشوف كل الأوامر
 * المفتوحة بكام وحدة وبكام فلوس، والمخزن فيه كام، والفرق».
 *
 * ═══ التعريفات (المصدر الوحيد — الشاشة وكروت `ops.pos` والتست) ═══
 *
 *   الأمر المفتوح   الحالة `pending` (مستني) أو `arrived` (جاري التسليم)،
 *                   ومش مرفوض من الحسابات. الملغي والمتسلّم بره.
 *   الباقي في البند  qty − delivered_qty (التسليم الجزئي بيقلّل الطلب).
 *   القيمة         (total + tax) × الباقي ÷ qty — نفس أساس `grand_total`
 *                   اللي كارت قيمة الأوامر في `ops.pos` بيجمعه.
 *
 *   الأمر ليه أمر تجهيز حي (مش ملغي) ⇒ **متغطّي**، مش بيطلب من المتاح:
 *     requested/picking  البضاعة لسه على الرف بس **محجوزة** للأمر ده
 *                        (`PickOrder::raise` بيرفض لو مش كفاية)
 *     ready/handed       البضاعة خرجت من الرف (جاهزة أو في عهدة المندوب)
 *   من غير أمر تجهيز (مستني الحسابات، أو الفلو القديم) ⇒ **محتاج مخزون**.
 *
 *   المتاح الحر     رصيد الأرفف القابل للبيع بالظبط زي `PickOrder::sellableRows`
 *                   (batch_locations.qty > 0، الباتش مش موقوف/تالف، qty_remaining > 0،
 *                   ومش منتهي) − المحجوز لكل أوامر التجهيز المفتوحة
 *                   (requested/picking — أي غرض: عهدة، أونلاين، ريفيل، أمر توريد).
 *   مش مترصّف       باتشات صالحة مستلمة ولسه على الأرض — **معلومة بس**، مش
 *                   في المتاح (أمر التجهيز مابيسحبش غير من رف).
 *   عهدة المناديب   **مش** في المتاح — دي بضاعة بتتباع من العربية.
 *
 *   العجز = max(المحتاج − المتاح الحر، 0) · قيمته بمتوسط سعر المحتاج
 *   التغطية % = (المتغطّي + min(المتاح، المحتاج)) ÷ المطلوب
 */
class PoShortage
{
    public const OPEN_STATUSES = ['pending', 'arrived'];

    public const LIVE_PICK = ['requested', 'picking', 'ready', 'handed'];

    public const RESERVING_PICK = ['requested', 'picking'];

    /** @var array<string, mixed> */
    private array $f;

    public function __construct(private Request $request)
    {
        $this->f = [
            'status' => in_array($request->string('status')->value(), self::OPEN_STATUSES, true)
                ? $request->string('status')->value() : null,
            'approval' => in_array($request->string('approval')->value(), ['pending', 'approved', 'none'], true)
                ? $request->string('approval')->value() : null,
            'warehouse' => $request->integer('warehouse') ?: null,
        ];
    }

    /** أوامر مفتوحة بنفس فلاتر `ops.pos` + المخزن */
    public function openOrders()
    {
        return OpsController::poFilterBase($this->request)
            ->whereIn('purchase_orders.status', $this->f['status'] ? [$this->f['status']] : self::OPEN_STATUSES)
            ->where(fn ($w) => $w->whereNull('purchase_orders.approval_status')
                ->orWhere('purchase_orders.approval_status', '<>', 'rejected'))
            ->when($this->f['approval'] === 'none', fn ($q) => $q->whereNull('purchase_orders.approval_status'))
            ->when(in_array($this->f['approval'], ['pending', 'approved'], true),
                fn ($q) => $q->where('purchase_orders.approval_status', $this->f['approval']))
            ->when($this->f['warehouse'], fn ($q, $w) => $q->where('purchase_orders.warehouse_id', $w))
            // كروت `ops.pos` بتحترم فلتر الحالة/القرار كما هو: «اتسلم» أو
            // «مرفوض» معناها مفيش ولا أمر مفتوح في النطاق — مش «تجاهل الفلتر»
            ->when($this->closedFilter(), fn ($q) => $q->whereRaw('1 = 0'))
            ->when($this->request->boolean('late'), fn ($q) => $q->whereNotNull('purchase_orders.due_at')
                ->where('purchase_orders.due_at', '<', now()));
    }

    private function closedFilter(): bool
    {
        $s = $this->request->string('status')->value();
        $a = $this->request->string('approval')->value();

        return ($s !== '' && ! in_array($s, self::OPEN_STATUSES, true)) || $a === 'rejected';
    }

    /** بنود الأوامر المفتوحة اللي لسه فيها باقي — بحالة أمر تجهيزها */
    private function lines()
    {
        $ids = $this->openOrders()->reorder()->select('purchase_orders.id');

        return DB::table('purchase_order_items as i')
            ->join('purchase_orders as po', 'po.id', '=', 'i.purchase_order_id')
            ->leftJoin('pick_orders as pk', 'pk.id', '=', 'po.pick_order_id')
            ->whereIn('i.purchase_order_id', $ids)
            ->whereRaw('i.qty - i.delivered_qty > 0');
    }

    private const REM = '(i.qty - i.delivered_qty)';

    private const VAL = '((i.total + i.tax) * (i.qty - i.delivered_qty) / NULLIF(i.qty, 0))';

    /** @return array<int, object> طلب كل صنف */
    private function demand(): array
    {
        $live = "pk.status IN ('".implode("','", self::LIVE_PICK)."')";
        $reserving = "pk.status IN ('".implode("','", self::RESERVING_PICK)."')";

        return $this->lines()
            ->groupBy('i.product_id')
            ->selectRaw('i.product_id,
                COUNT(DISTINCT i.purchase_order_id) as po_count,
                SUM('.self::REM.') as ordered_units,
                SUM('.self::VAL.') as ordered_value,
                SUM(CASE WHEN '.$reserving.' THEN '.self::REM.' ELSE 0 END) as reserved_units,
                SUM(CASE WHEN '.$live.' AND NOT '.$reserving.' THEN '.self::REM.' ELSE 0 END) as prepared_units,
                SUM(CASE WHEN '.$live.' THEN 0 ELSE '.self::REM.' END) as need_units,
                SUM(CASE WHEN '.$live.' THEN 0 ELSE '.self::VAL.' END) as need_value,
                COUNT(DISTINCT CASE WHEN '.$live.' THEN NULL ELSE i.purchase_order_id END) as need_po_count,
                SUM(CASE WHEN '.$live.' THEN 0 WHEN po.approval_status = \'pending\' THEN '.self::REM.' ELSE 0 END) as awaiting_units')
            ->get()->keyBy('product_id')->all();
    }


    /**
     * رصيد الأرفف القابل للبيع لكل صنف **في كل مخزن** — نفس شروط
     * `BatchLocation::sellable()`.
     *
     * @param  list<int>  $productIds
     * @return array<int, array<int, int>> [product_id][warehouse_id] => qty
     */
    private function shelfByWarehouse(array $productIds): array
    {
        $out = [];
        if (! $productIds) {
            return $out;
        }

        DB::table('batch_locations as bl')
            ->join('batches as b', 'b.id', '=', 'bl.batch_id')
            ->join('locations as l', 'l.id', '=', 'bl.location_id')
            ->whereIn('bl.product_id', $productIds)
            ->where('bl.qty', '>', 0)
            ->where('b.blocked', false)
            ->where('b.qty_remaining', '>', 0)
            ->whereDate('b.expires_on', '>=', now()->toDateString())
            ->when($this->f['warehouse'], fn ($q, $w) => $q->where('l.warehouse_id', $w))
            ->groupBy('bl.product_id', 'l.warehouse_id')
            ->selectRaw('bl.product_id, l.warehouse_id, SUM(bl.qty) as q')
            ->get()
            ->each(function ($r) use (&$out) {
                $out[(int) $r->product_id][(int) $r->warehouse_id] = (int) $r->q;
            });

        return $out;
    }

    /**
     * المحجوز لأوامر التجهيز المفتوحة (لسه ماتسحبش من الرف) لكل صنف في كل مخزن.
     *
     * @param  list<int>  $productIds
     * @return array<int, array<int, int>>
     */
    private function heldByWarehouse(array $productIds): array
    {
        $out = [];
        if (! $productIds) {
            return $out;
        }

        DB::table('pick_order_items as pi')
            ->join('pick_orders as pk', 'pk.id', '=', 'pi.pick_order_id')
            ->whereIn('pk.status', self::RESERVING_PICK)
            ->whereIn('pi.product_id', $productIds)
            ->when($this->f['warehouse'], fn ($q, $w) => $q->where('pk.warehouse_id', $w))
            ->groupBy('pi.product_id', 'pk.warehouse_id')
            ->selectRaw('pi.product_id, pk.warehouse_id, SUM(pi.qty_requested) as q')
            ->get()
            ->each(function ($r) use (&$out) {
                $out[(int) $r->product_id][(int) $r->warehouse_id] = (int) $r->q;
            });

        return $out;
    }

    /**
     * المتاح الحر لكل صنف في كل مخزن = رف المخزن − المحجوز في نفس المخزن.
     * ⚠️ بالمخزن مش بالإجمالي: حجز في مخزن مايقدرش ياكل رف مخزن تاني.
     *
     * @param  list<int>  $productIds
     * @return array<int, array<int, int>>
     */
    public function freeByWarehouse(array $productIds): array
    {
        $shelf = $this->shelfByWarehouse($productIds);
        $held = $this->heldByWarehouse($productIds);
        $out = [];

        foreach ($productIds as $pid) {
            foreach (array_unique(array_merge(array_keys($shelf[$pid] ?? []), array_keys($held[$pid] ?? []))) as $wid) {
                $free = max(($shelf[$pid][$wid] ?? 0) - ($held[$pid][$wid] ?? 0), 0);
                if ($free > 0) {
                    $out[$pid][$wid] = $free;
                }
            }
            if (isset($out[$pid])) {
                arsort($out[$pid]);
            }
        }

        return $out;
    }

    /** @return array<int, int> رصيد الرف لكل صنف (كل المخازن في النطاق) */
    public function shelf(array $productIds): array
    {
        return array_map('array_sum', $this->shelfByWarehouse($productIds));
    }

    /** @return array<int, int> المحجوز لأوامر تجهيز مفتوحة لكل صنف */
    public function reservedByPicks(array $productIds): array
    {
        return array_map('array_sum', $this->heldByWarehouse($productIds));
    }

    /**
     * الصالح المستلم ولسه مترصّفش (معلومة بس).
     *
     * @param  list<int>  $productIds
     * @return array<int, int>
     */
    private function unshelved(array $productIds): array
    {
        if (! $productIds) {
            return [];
        }

        $shelved = DB::table('batch_locations')->selectRaw('batch_id, SUM(qty) as s')->groupBy('batch_id');

        return DB::table('batches as b')
            ->leftJoinSub($shelved, 'sh', 'sh.batch_id', '=', 'b.id')
            ->whereIn('b.product_id', $productIds)
            ->where('b.blocked', false)
            ->where('b.qty_remaining', '>', 0)
            ->whereDate('b.expires_on', '>=', now()->toDateString())
            ->when($this->f['warehouse'], fn ($q, $w) => $q->where('b.warehouse_id', $w))
            ->groupBy('b.product_id')
            ->selectRaw('b.product_id, SUM(GREATEST(b.qty_remaining - COALESCE(sh.s, 0), 0)) as q')
            ->pluck('q', 'product_id')->map(fn ($v) => (int) $v)->all();
    }

    /**
     * الباتشات الصالحة على الرف لكل صنف — المخزن والباتش والصلاحية (لفتح الصنف).
     *
     * @param  list<int>  $productIds
     * @return array<int, list<object>> [product_id] => [{warehouse_id, batch_no, expires_on, qty}]
     */
    public function batches(array $productIds): array
    {
        if (! $productIds) {
            return [];
        }

        return DB::table('batch_locations as bl')
            ->join('batches as b', 'b.id', '=', 'bl.batch_id')
            ->join('locations as l', 'l.id', '=', 'bl.location_id')
            ->whereIn('bl.product_id', $productIds)
            ->where('bl.qty', '>', 0)
            ->where('b.blocked', false)
            ->where('b.qty_remaining', '>', 0)
            ->whereDate('b.expires_on', '>=', now()->toDateString())
            ->when($this->f['warehouse'], fn ($q, $w) => $q->where('l.warehouse_id', $w))
            ->groupBy('bl.product_id', 'l.warehouse_id', 'b.id', 'b.batch_no', 'b.expires_on')
            ->orderBy('b.expires_on')
            ->selectRaw('bl.product_id, l.warehouse_id, b.batch_no, b.expires_on, SUM(bl.qty) as qty')
            ->get()
            ->groupBy('product_id')
            ->map(fn ($g) => $g->values()->all())
            ->all();
    }

    /**
     * صف لكل صنف مطلوب + الإجماليات.
     *
     * @return array{rows: Collection, totals: array<string, mixed>}
     */
    public function report(): array
    {
        $demand = $this->demand();
        $ids = array_map('intval', array_keys($demand));

        $shelf = $this->shelf($ids);
        $held = $this->reservedByPicks($ids);
        $freeWh = $this->freeByWarehouse($ids);
        $floor = $this->unshelved($ids);
        $products = \App\Models\Product::whereIn('id', $ids)->get(['id', 'code', 'name', 'name_en'])->keyBy('id');

        $rows = collect($demand)->map(function ($d, $pid) use ($shelf, $held, $freeWh, $floor, $products) {
            $need = (int) $d->need_units;
            $needValue = (float) $d->need_value;
            $ordered = (int) $d->ordered_units;
            $covered = (int) $d->prepared_units + (int) $d->reserved_units;
            $free = array_sum($freeWh[$pid] ?? []);
            $short = max($need - $free, 0);
            $avg = $need > 0 ? $needValue / $need : 0;
            // سعر الوحدة في الأوامر (شامل الضريبة) — بيه بنقيّم المتاح عشان
            // الفلوس في الناحيتين تبقى بنفس المسطرة (مش التكلفة)
            $unit = $ordered > 0 ? (float) $d->ordered_value / $ordered : 0;

            return (object) [
                'product_id' => (int) $pid,
                'product' => $products[$pid] ?? null,
                'po_count' => (int) $d->po_count,
                'need_po_count' => (int) $d->need_po_count,
                'ordered_units' => $ordered,
                'ordered_value' => round((float) $d->ordered_value, 2),
                'prepared_units' => (int) $d->prepared_units,
                'reserved_units' => (int) $d->reserved_units,
                'need_units' => $need,
                'need_value' => round($needValue, 2),
                // منهم مستني قرار الحسابات (الباقي: فلو قديم أو أمر تجهيز اتلغى)
                'awaiting_units' => (int) $d->awaiting_units,
                'shelf' => (int) ($shelf[$pid] ?? 0),
                'held' => (int) ($held[$pid] ?? 0),
                'free' => $free,
                'free_by_wh' => $freeWh[$pid] ?? [],
                'unit_price' => round($unit, 4),
                'free_value' => round($free * $unit, 2),
                'unshelved' => (int) ($floor[$pid] ?? 0),
                'short_units' => $short,
                'short_value' => round($short * $avg, 2),
                'coverage' => $ordered > 0 ? round(min(($covered + min($free, $need)) / $ordered, 1) * 100, 1) : 100.0,
            ];
        })->sortBy([['short_value', 'desc'], ['short_units', 'desc'], ['need_units', 'desc']])->values();

        // المتاح حسب المخزن (للأصناف المطلوبة بس) — قطع وقيمة بسعر الأوامر
        $byWh = [];
        foreach ($rows as $r) {
            foreach ($r->free_by_wh as $wid => $q) {
                $byWh[$wid]['units'] = ($byWh[$wid]['units'] ?? 0) + $q;
                $byWh[$wid]['value'] = ($byWh[$wid]['value'] ?? 0) + $q * $r->unit_price;
                $byWh[$wid]['products'] = ($byWh[$wid]['products'] ?? 0) + 1;
            }
        }
        uasort($byWh, fn ($a, $b) => $b['units'] <=> $a['units']);

        $ordered = (int) $rows->sum('ordered_units');
        $covered = (int) $rows->sum(fn ($r) => $r->prepared_units + $r->reserved_units + min($r->free, $r->need_units));

        return [
            'rows' => $rows,
            'totals' => [
                'open_orders' => $this->openOrders()->count(),
                'products' => $rows->count(),
                'short_products' => $rows->where('short_units', '>', 0)->count(),
                'ordered_units' => $ordered,
                'ordered_value' => round((float) $rows->sum('ordered_value'), 2),
                'prepared_units' => (int) $rows->sum('prepared_units'),
                'reserved_units' => (int) $rows->sum('reserved_units'),
                'need_units' => (int) $rows->sum('need_units'),
                'need_value' => round((float) $rows->sum('need_value'), 2),
                'awaiting_units' => (int) $rows->sum('awaiting_units'),
                // عدد الأوامر (مميّز) اللي لسه مالهاش أمر تجهيز حي
                'need_orders' => (int) $this->lines()
                    ->where(fn ($w) => $w->whereNull('pk.status')->orWhereNotIn('pk.status', self::LIVE_PICK))
                    ->distinct()->count('i.purchase_order_id'),
                // المتاح «اللي يخدم الطلب» — فايض صنف مايغطيش عجز صنف تاني
                'free_for_need' => (int) $rows->sum(fn ($r) => min($r->free, $r->need_units)),
                'free' => (int) $rows->sum('free'),
                'free_value' => round((float) $rows->sum('free_value'), 2),
                'by_warehouse' => $byWh,
                'short_units' => (int) $rows->sum('short_units'),
                'short_value' => round((float) $rows->sum('short_value'), 2),
                'coverage' => $ordered > 0 ? round($covered / $ordered * 100, 1) : 100.0,
            ],
        ];
    }

    /**
     * الأوامر المفتوحة اللي محتاجة مخزون (أو بتطلب صنف معيّن) + «ينفع
     * يتجهّز كامل دلوقتي؟ ومنين؟». المتاح الحر **بالمخزن** بيتوزّع على
     * الأوامر بالترتيب (ميعاد التوريد، وبعدين الأقدم):
     *
     *   can    مخزن الأمر نفسه فيه كل بنوده
     *   other  مخزن الأمر مش كفاية بس مخزن تاني واحد فيه الكل (محتاج تحويل/تغيير المخزن)
     *   split  الكمية موجودة بس متوزّعة على أكتر من مخزن
     *   short  مفيش كفاية في كل المخازن — `missing` بالصنف
     *
     * الأمر بياخد بس لو كل بنوده تتغطّى، عشان مانحجزش نص أمر ونعطّل اللي بعده.
     *
     * @return Collection<int, object>
     */
    public function orders(?int $productId = null): Collection
    {
        $q = $this->openOrders()->reorder()
            ->with(['client.group', 'courier', 'pickOrder:id,number,status', 'items' => fn ($i) => $i->whereRaw('qty - delivered_qty > 0')])
            ->when($productId, fn ($q2, $p) => $q2->whereHas('items', fn ($i) => $i->where('product_id', $p)->whereRaw('qty - delivered_qty > 0')))
            ->orderByRaw('COALESCE(purchase_orders.due_at, purchase_orders.due_date, purchase_orders.created_at)')
            ->orderBy('purchase_orders.id');

        $pos = $q->get();

        // ⚠️ التوزيع على **كل** الأوامر بالترتيب حتى لو فيه فلتر صنف —
        // وإلا أمر متأخر في الطابور يبان «ينفع» وهو مش هيلحق
        $all = $productId ? $this->orders(null)->keyBy('id') : null;

        $ids = $pos->flatMap(fn ($po) => $po->items->pluck('product_id'))->unique()->map(fn ($v) => (int) $v)->values()->all();
        $pool = $all ? [] : $this->freeByWarehouse($ids);

        return $pos->map(function (PurchaseOrder $po) use (&$pool, $productId, $all) {
            $live = $po->pickOrder && in_array($po->pickOrder->status, self::LIVE_PICK, true);
            $units = (int) $po->items->sum(fn ($i) => $i->qty - $i->delivered_qty);
            $value = round((float) $po->items->sum(fn ($i) => $i->qty > 0 ? ((float) $i->total + (float) $i->tax) * ($i->qty - $i->delivered_qty) / $i->qty : 0), 2);

            if ($all) {
                $row = clone $all[$po->id];
            } else {
                $want = $po->items->groupBy('product_id')
                    ->map(fn ($its) => (int) $its->sum(fn ($i) => $i->qty - $i->delivered_qty))->all();
                [$state, $from, $missing] = $live ? ['covered', null, []] : $this->allocate($want, $pool, $po->warehouse_id);

                $row = (object) [
                    'id' => $po->id,
                    'po' => $po,
                    'units' => $units,
                    'value' => $value,
                    'covered' => $live,
                    'state' => $state,
                    'can_serve' => in_array($state, ['can', 'other', 'split'], true),
                    'from' => $from,
                    'missing' => $missing,
                    'missing_units' => array_sum($missing),
                ];
            }

            if ($productId) {
                $row->product_units = (int) $po->items->where('product_id', $productId)->sum(fn ($i) => $i->qty - $i->delivered_qty);
            }

            return $row;
        });
    }

    /**
     * يخصّص أمر واحد من البول (بيتعدّل بالمرجع لو اتخصّص).
     *
     * @param  array<int, int>  $want  [product_id => qty]
     * @param  array<int, array<int, int>>  $pool  [product_id][warehouse_id] => free
     * @return array{0: string, 1: list<int>|null, 2: array<int, int>} [state, warehouses, missing]
     */
    private function allocate(array $want, array &$pool, ?int $home): array
    {
        $fits = function (int $wid) use ($want, $pool): bool {
            foreach ($want as $pid => $q) {
                if (($pool[$pid][$wid] ?? 0) < $q) {
                    return false;
                }
            }

            return true;
        };
        $take = function (int $wid) use ($want, &$pool): void {
            foreach ($want as $pid => $q) {
                $pool[$pid][$wid] -= $q;
            }
        };

        // ١. مخزن الأمر نفسه
        if ($home && $fits($home)) {
            $take($home);

            return ['can', [$home], []];
        }

        // ٢. مخزن واحد تاني فيه الكل (أو أي مخزن لو الأمر مالوش مخزن)
        $candidates = [];
        foreach ($want as $pid => $q) {
            $candidates = array_merge($candidates, array_keys($pool[$pid] ?? []));
        }
        foreach (array_unique($candidates) as $wid) {
            if ($wid !== $home && $fits($wid)) {
                $take($wid);

                return [$home ? 'other' : 'can', [$wid], []];
            }
        }

        // ٣. الإجمالي كفاية بس متوزّع — أو ناقص بجد
        $missing = [];
        foreach ($want as $pid => $q) {
            $have = array_sum($pool[$pid] ?? []);
            if ($have < $q) {
                $missing[$pid] = $q - $have;
            }
        }
        if ($missing) {
            return ['short', null, $missing];
        }

        $used = [];
        foreach ($want as $pid => $q) {
            // من مخزن الأمر الأول، وبعدين الأكبر
            $order = array_keys($pool[$pid]);
            usort($order, fn ($a, $b) => ($b === $home) <=> ($a === $home) ?: $pool[$pid][$b] <=> $pool[$pid][$a]);
            foreach ($order as $wid) {
                if ($q <= 0) {
                    break;
                }
                $t = min($q, $pool[$pid][$wid]);
                if ($t > 0) {
                    $pool[$pid][$wid] -= $t;
                    $q -= $t;
                    $used[$wid] = true;
                }
            }
        }

        return ['split', array_keys($used), []];
    }
}
