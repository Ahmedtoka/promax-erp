<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * حركة الأصناف بالكمية — مين سحب إيه، كام قطعة، بكام، وإمتى
 * ═══════════════════════════════════════════════════════════════
 *
 * كشف الحساب بيقول فلوس (مدين/دائن). ده بيقول **بضاعة**: لكل صنف كام
 * قطعة اتسحبت وبأي سعر وإمتى، وكام رجع، وكام اتهدى — مجمّعة بالعائلة.
 *
 * المصادر (المستندات نفسها — مش القيود):
 *   • `invoice_items` — فواتير الكاش فان والمستندات اليدوية.
 *   • `purchase_order_items` للأوامر **المسلَّمة** — الكي أكاونت والأونلاين
 *     بيسحبوا بأوامر توريد مش فواتير. الكمية = `delivered_qty`، ولو صفر
 *     (صفوف قديمة قبل عمود التسليم) بنرجع لـ`qty`.
 *   • `return_items` — المرتجع بحالته (سليم/تالف).
 *   • `gift_handouts` — الهدايا (بلا سعر).
 *
 * ⚠️ **ده مش مصدر أرقام فلوس.** `sold_value` مجموع سطور المستندات
 * (صافي بعد الخصم قبل الضريبة = `total` في عقيدة الأرقام التلاتة) —
 * للاسترشاد بمتوسط السعر بس. الرصيد والمشتريات من `transactions`.
 */
class ProductMovements
{
    public const KIND_SALE = 'sale';

    public const KIND_PO = 'po';

    public const KIND_RETURN = 'return';

    public const KIND_GIFT = 'gift';

    /**
     * الحركات التفصيلية مرتبة بالتاريخ.
     *
     * @param  list<int>  $clientIds
     * @return Collection<int, object{kind:string, at:string, doc:?string, client_id:int, product_id:int, qty:int, price:float, total:float, condition:?string}>
     */
    public static function rows(array $clientIds, ?Carbon $from = null, ?Carbon $to = null): Collection
    {
        $clientIds = array_values(array_unique(array_map('intval', $clientIds)));

        if ($clientIds === []) {
            return collect();
        }

        // العمود ممكن يبقى تعبير (COALESCE لأوامر التوريد القديمة بلا delivered_at)
        $range = function ($q, mixed $column) use ($from, $to) {
            if ($from !== null) {
                $q->where($column, '>=', $from->copy()->startOfDay());
            }
            if ($to !== null) {
                $q->where($column, '<=', $to->copy()->endOfDay());
            }

            return $q;
        };

        $sales = $range(
            DB::table('invoice_items as it')
                ->join('invoices as i', 'i.id', '=', 'it.invoice_id')
                ->whereIn('i.client_id', $clientIds),
            'i.created_at',
        )->selectRaw("'sale' as kind, i.created_at as at, i.number as doc, i.client_id, it.product_id,
                      it.qty as qty, it.price as price, it.total as total, NULL as `condition`")
            ->get();

        $pos = $range(
            DB::table('purchase_order_items as pi')
                ->join('purchase_orders as po', 'po.id', '=', 'pi.purchase_order_id')
                ->whereIn('po.client_id', $clientIds)
                ->where('po.status', 'delivered'),
            DB::raw('COALESCE(po.delivered_at, po.updated_at)'),
        )->selectRaw("'po' as kind, COALESCE(po.delivered_at, po.updated_at) as at, po.number as doc, po.client_id, pi.product_id,
                      CASE WHEN pi.delivered_qty > 0 THEN pi.delivered_qty ELSE pi.qty END as qty,
                      pi.price as price,
                      CASE WHEN pi.delivered_qty > 0 THEN pi.delivered_qty * pi.price ELSE pi.total END as total,
                      NULL as `condition`")
            ->get();

        $returns = $range(
            DB::table('return_items as ri')
                ->join('returns as r', 'r.id', '=', 'ri.return_id')
                ->whereIn('r.client_id', $clientIds),
            'r.created_at',
        )->selectRaw("'return' as kind, r.created_at as at, r.number as doc, r.client_id, ri.product_id,
                      ri.qty as qty, ri.price as price, ri.total as total, ri.`condition` as `condition`")
            ->get();

        $gifts = $range(
            DB::table('gift_handouts as g')->whereIn('g.client_id', $clientIds),
            'g.created_at',
        )->selectRaw("'gift' as kind, g.created_at as at, NULL as doc, g.client_id, g.product_id,
                      g.qty as qty, 0 as price, 0 as total, NULL as `condition`")
            ->get();

        return $sales->concat($pos)->concat($returns)->concat($gifts)
            ->map(function ($r) {
                $r->qty = (int) $r->qty;
                $r->price = (float) $r->price;
                $r->total = (float) $r->total;

                return $r;
            })
            ->sortBy(fn ($r) => (string) $r->at)
            ->values();
    }

    /**
     * ملخص لكل صنف مجمّع بالعائلة.
     *
     * @param  list<int>  $clientIds
     * @return array{
     *   families: list<array{key:string,label:string,sold_qty:int,returned_qty:int,gift_qty:int,net_qty:int,sold_value:float,products:list<array<string,mixed>>}>,
     *   totals: array{sold_qty:int,returned_qty:int,gift_qty:int,net_qty:int,sold_value:float,docs:int},
     *   rows: int
     * }
     */
    public static function summary(array $clientIds, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $rows = self::rows($clientIds, $from, $to);
        $products = Product::whereIn('id', $rows->pluck('product_id')->unique()->all())->get()->keyBy('id');

        $byProduct = [];

        foreach ($rows as $r) {
            $product = $products->get($r->product_id);

            if ($product === null) {
                continue;
            }

            $e = $byProduct[$r->product_id] ?? [
                'product' => $product,
                'sold_qty' => 0, 'sold_value' => 0.0, 'docs' => 0,
                'returned_qty' => 0, 'returned_good' => 0, 'returned_damaged' => 0,
                'gift_qty' => 0,
                'first_at' => null, 'last_at' => null,
            ];

            if ($r->kind === self::KIND_SALE || $r->kind === self::KIND_PO) {
                $e['sold_qty'] += $r->qty;
                $e['sold_value'] += $r->total;
                $e['docs']++;
                $at = Carbon::parse($r->at);
                $e['first_at'] = $e['first_at'] === null || $at->lt($e['first_at']) ? $at : $e['first_at'];
                $e['last_at'] = $e['last_at'] === null || $at->gt($e['last_at']) ? $at : $e['last_at'];
            } elseif ($r->kind === self::KIND_RETURN) {
                $e['returned_qty'] += $r->qty;

                if ($r->condition === 'damaged') {
                    $e['returned_damaged'] += $r->qty;
                } else {
                    $e['returned_good'] += $r->qty;
                }
            } elseif ($r->kind === self::KIND_GIFT) {
                $e['gift_qty'] += $r->qty;
            }

            $byProduct[$r->product_id] = $e;
        }

        $families = [];

        foreach ($byProduct as $e) {
            $e['net_qty'] = $e['sold_qty'] - $e['returned_qty'];
            $e['avg_price'] = $e['sold_qty'] > 0 ? round($e['sold_value'] / $e['sold_qty'], 2) : 0.0;

            $key = (string) ($e['product']->family ?? '');

            if (! isset($families[$key])) {
                $families[$key] = [
                    'key' => $key,
                    'label' => $e['product']->familyLabel(),
                    'sold_qty' => 0, 'returned_qty' => 0, 'gift_qty' => 0, 'net_qty' => 0, 'sold_value' => 0.0,
                    'products' => [],
                ];
            }

            $families[$key]['products'][] = $e;
            $families[$key]['sold_qty'] += $e['sold_qty'];
            $families[$key]['returned_qty'] += $e['returned_qty'];
            $families[$key]['gift_qty'] += $e['gift_qty'];
            $families[$key]['net_qty'] += $e['net_qty'];
            $families[$key]['sold_value'] += $e['sold_value'];
        }

        // الأكتر سحباً الأول — عائلات وأصناف
        $families = collect($families)
            ->map(function (array $f) {
                $f['products'] = collect($f['products'])->sortByDesc('sold_qty')->values()->all();

                return $f;
            })
            ->sortByDesc('sold_qty')->values()->all();

        $totals = [
            'sold_qty' => array_sum(array_column($byProduct, 'sold_qty')),
            'returned_qty' => array_sum(array_column($byProduct, 'returned_qty')),
            'gift_qty' => array_sum(array_column($byProduct, 'gift_qty')),
            'sold_value' => (float) array_sum(array_column($byProduct, 'sold_value')),
            'docs' => array_sum(array_column($byProduct, 'docs')),
        ];
        $totals['net_qty'] = $totals['sold_qty'] - $totals['returned_qty'];

        return ['families' => $families, 'totals' => $totals, 'rows' => $rows->count()];
    }

    /** ليبل نوع الحركة — من lang/{ar,en}/client.php */
    public static function kindLabel(string $kind): string
    {
        return __('client.mv_'.$kind);
    }
}
