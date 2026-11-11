<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\PurchaseOrder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * قوايم الأسعار — مين عليها وشكلها إيه (طلب المالك ١١/١١/٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * «عايز أعرف مين العملاء والسلاسل اللي واخدين القايمة دي، كام عميل
 * وسلسلة، وعدد المنتجات في كل قايمة».
 *
 * ⚠️ **كل الأرقام بكام query ثابتة مهما كان عدد القوايم.** الشاشة
 * القديمة كانت بتعمل `missingCount()` لكل قايمة؛ هنا كل حاجة بتتجمّع
 * مرة واحدة وتتوزّع على القوايم في PHP.
 *
 * ⚠️ **«العميل على القايمة» = `clients.price_list_id`.** بعد تصليح
 * الأسعار (١٠/١١) كل عميل متسجّل على قايمة بالـid، وده اللي شاشة
 * الإعداد بتكتبه. عقد الفرع الشخصي السارٍ بيغلبه في `Pricing` — فالعقود
 * اللي بتشاور على قايمة بتتعدّ لوحدها عشان تبان.
 */
class PriceListOverview
{
    /** نسبة الضريبة — للعرض بس (الشامل = غير الشامل × ١.١٤) */
    public const VAT = 1.14;

    /**
     * @param  Collection<int, PriceList>  $lists
     * @return array<int, array<string, mixed>> list_id ⇒ الإحصائيات
     */
    public static function forLists(Collection $lists): array
    {
        $ids = $lists->pluck('id')->all();
        if ($ids === []) {
            return [];
        }

        $sellable = Product::sellable()->pluck('id')->all();
        $sellableSet = array_flip($sellable);

        // ── البنود: عدد المسعَّر + نسبة كل قايمة للمستهلك الجديد/القديم ──
        $items = DB::table('price_list_items')->whereIn('price_list_id', $ids)
            ->where('price', '>', 0)->get(['price_list_id', 'product_id', 'price']);
        $byList = [];
        foreach ($items as $it) {
            if (isset($sellableSet[$it->product_id])) {
                $byList[$it->price_list_id][$it->product_id] = (float) $it->price;
            }
        }
        $newId = PriceList::where('code', 'new')->value('id');
        $oldId = PriceList::where('code', 'old')->value('id');
        $consumer = fn ($id) => $id ? DB::table('price_list_items')->where('price_list_id', $id)
            ->where('price', '>', 0)->pluck('price', 'product_id')->map(fn ($p) => (float) $p)->all() : [];
        $newP = $consumer($newId);
        $oldP = $consumer($oldId);

        // ── العملاء على كل قايمة ──
        $clients = Client::query()->whereIn('price_list_id', $ids)
            ->with('group:id,name,name_en')
            ->get(['id', 'code', 'name', 'name_en', 'status', 'discount', 'taxable', 'group_id', 'price_list_id'])
            ->groupBy('price_list_id');

        // ── السلاسل ──
        $chains = ClientGroup::query()->whereIn('price_list_id', $ids)
            ->withCount(['clients as branches' => fn ($q) => $q->where('status', 'active')])
            ->get(['id', 'code', 'name', 'name_en', 'price_list_id', 'default_discount', 'taxable', 'active'])
            ->groupBy('price_list_id');

        // ── العقود السارية اللي بتشاور على القايمة ──
        $contracts = Contract::query()->whereIn('price_list_id', $ids)->where('active', true)->get()
            ->reject(fn ($c) => $c->isExpired())->groupBy('price_list_id');

        // ── آخر فاتورة (بكود القايمة وقت البيع) وآخر أمر توريد (بقايمة العميل) ──
        $codes = $lists->pluck('code', 'id');
        $lastInvIds = Invoice::query()->whereIn('price_list', $codes->values())
            ->groupBy('price_list')->select('price_list', DB::raw('max(id) as id'))->pluck('id', 'price_list');
        $invs = Invoice::whereIn('id', $lastInvIds->values())->get(['id', 'number', 'created_at', 'grand_total'])->keyBy('id');
        $lastPoIds = PurchaseOrder::query()->join('clients', 'clients.id', '=', 'purchase_orders.client_id')
            ->whereIn('clients.price_list_id', $ids)->where('purchase_orders.status', '!=', 'cancelled')
            ->groupBy('clients.price_list_id')
            ->select('clients.price_list_id', DB::raw('max(purchase_orders.id) as id'))->pluck('id', 'price_list_id');
        $pos = PurchaseOrder::whereIn('id', $lastPoIds->values())->get(['id', 'number', 'created_at', 'grand_total', 'status'])->keyBy('id');

        $total = count($sellable);
        $out = [];
        foreach ($lists as $l) {
            $prices = $byList[$l->id] ?? [];
            $cl = $clients->get($l->id, collect());
            $active = $cl->where('status', 'active');

            // توزيع الخصم على العملاء الشغّالين: ‎10% × 12‎ …
            $disc = $active->groupBy(fn ($c) => rtrim(rtrim(number_format((float) $c->discount * 100, 2, '.', ''), '0'), '.'))
                ->map->count()->sortDesc();

            $out[$l->id] = [
                'priced' => count($prices),
                'missing' => $total - count($prices),
                'clients' => $cl->sortBy([['status', 'asc'], ['name', 'asc']])->values(),
                'clients_active' => $active->count(),
                'clients_other' => $cl->count() - $active->count(),
                'chains' => $chains->get($l->id, collect())->sortBy('name')->values(),
                'contracts' => $contracts->get($l->id, collect())->count(),
                'discounts' => $disc,
                'basis' => self::basis($l, $prices, $newP, $oldP),
                'last_invoice' => ($i = $lastInvIds[$l->code] ?? null) ? $invs[$i] ?? null : null,
                'last_po' => ($p = $lastPoIds[$l->id] ?? null) ? $pos[$p] ?? null : null,
            ];
        }

        return $out;
    }

    /**
     * القايمة مبنية على إيه.
     *
     * ⚠️ **محسوبة من الأسعار نفسها، مش من الاسم.** بنقارن كل صنف بسعره في
     * المستهلك الجديد:
     *   - كل الأصناف زيه بالظبط ⇒ نسخة منه · كلها زي «القديم» ⇒ نسخة منه
     *   - غير كده ⇒ قايمة صافي: كام صنف مختلف، ووسيط نسبتهم للمستهلك
     *   - الوسيط ≈ ١٫١٤ ⇒ ⚠️ شكلها متسجّلة **شامل** — ضد مبدأ «المخزَّن غير شامل»
     *
     * ⚠️⚠️ **الوسيط على الأصناف المختلفة بس.** أصناف كتير سعرها واحد في كل
     * القوايم (المكمّلات مثلاً)، فوسيط الكل كان بيطلع ١٫٠٠ لقايمة سيركل ك
     * وفيها ٢١ صنف بسعر تاني — والشاشة كانت بتقول «زي المستهلك الجديد».
     *
     * @param  array<int, float>  $prices
     * @param  array<int, float>  $newP
     * @param  array<int, float>  $oldP
     * @return array{kind: string, ratio: ?float, differ: int, incl_suspect: bool}
     */
    public static function basis(PriceList $l, array $prices, array $newP, array $oldP): array
    {
        $differ = [];
        $sameOld = 0;
        foreach ($prices as $pid => $p) {
            if (($newP[$pid] ?? 0) > 0 && abs($p - $newP[$pid]) >= 0.00005) {
                $differ[] = $p / $newP[$pid];
            }
            if (($oldP[$pid] ?? 0) > 0 && abs($p - $oldP[$pid]) < 0.00005) {
                $sameOld++;
            }
        }
        $ratio = self::median($differ);
        $out = fn ($kind) => ['kind' => $kind, 'ratio' => $ratio, 'differ' => count($differ),
            'incl_suspect' => $ratio !== null && abs($ratio - self::VAT) < 0.01];

        if ($l->code === 'new') {
            return ['kind' => 'consumer_new', 'ratio' => null, 'differ' => 0, 'incl_suspect' => false];
        }
        if ($l->code === 'old') {
            return $out('consumer_old');
        }
        if ($prices === []) {
            return $out('empty');
        }
        if ($differ === []) {
            return $out('copy_new');
        }
        if ($sameOld === count($prices)) {
            return $out('copy_old');
        }

        return $out('net');
    }

    /** وسيط مجموعة نسب */
    private static function median(array $r): ?float
    {
        if ($r === []) {
            return null;
        }
        sort($r);
        $n = count($r);

        return $n % 2 ? $r[intdiv($n, 2)] : ($r[$n / 2 - 1] + $r[$n / 2]) / 2;
    }

    /** ٨٢٫٨٩٤٧ ⇒ «82.8947» · ٨٢٫٥ ⇒ «82.50» — ٤ أرقام لما تفرق، و٢ على الأقل */
    public static function money4(float $v): string
    {
        $s = number_format($v, 4, '.', ',');

        return preg_replace('/(\.\d\d)0{1,2}$/', '$1', $s);
    }
}
