<?php

namespace App\Services;

use App\Exceptions\Rejected;
use App\Models\Client;
use App\Models\ClientReturn;
use App\Models\Custody;
use App\Models\CustodyItem;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\ReturnItem;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * المرتجعات — المكان الوحيد اللي بيعمل مرتجع في السيستم
 * ═══════════════════════════════════════════════════════════════
 *
 * زي `Pricing` بالظبط: الأبلكيشن والـERP الاتنين بينادوا هنا، عشان
 * الفلو ما يتفرّعش لنسختين بيختلفوا مع الوقت (نفس الدرس اللي
 * اتعلمناه من `ReplenishmentRequest::assignTo`).
 *
 * ═══════════════════════════════════════════════════════════════
 * العقيدة
 * ═══════════════════════════════════════════════════════════════
 *
 * 1. ⚠️ **المرتجع بيتسعّر من الفاتورة الأصلية أولاً، مش بسعر النهارده.**
 *    ده كان باج حقيقي: لو قايمة السعر أو الخصم اتغيّروا بين البيع
 *    والمرتجع، الدفتر بيسيب باقي مالوش تفسير. التوزيع بيمشي على
 *    فواتير العميل من الأحدث للأقدم وبياخد من كل سطر المتاح منه —
 *    بسعره هو وضريبته هو.
 *
 * 2. ⚠️ **المرتجع مفتوح على الكتالوج كله (قرار المالك ١٢ أغسطس ٢٠٢٦).**
 *    العملاء عندهم بضاعة قديمة من قبل السيستم (الأرصدة الافتتاحية
 *    للبضاعة ماتسجلتش)، فشرط «اشتراه الأول» كان بيرفض مرتجعات
 *    حقيقية واقفة عند العميل. الكمية اللي مالهاش سطر فاتورة يغطيها
 *    بتتسعّر بـ`Pricing` لنفس العميل — بالظبط زي ما فاتورة بيع
 *    كانت هتسعّرها النهارده — وبتتسجل بند بلا `invoice_item_id`.
 *    صنف مش متسعّر في قايمة العميل = رفض، مش سعر صفر.
 *    وحزام أمان: سقف 9999 قطعة للصنف الواحد في المستند.
 *
 * 3. ⚠️ **حالة كل قطعة: سليم ولا تالف** (قرار المالك ٨/٨/٢٠٢٦).
 *    السليم بينزل `custody_items.returned_in` والتالف بينزل
 *    `damaged_in` — والاتنين بيبانوا وقت تصفية العهدة. خانة واحدة
 *    للاتنين كانت هتخلّي المخزن يستلم بضاعة نصها مش صالحة.
 *
 * 4. ⚠️ **السياسة بتتفحص على العميل.** المندوب بيبعت اللي اختاره،
 *    والسيرفر بيرفض لو مش من المسموح للعميل ده. الاعتماد على
 *    الشاشة إنها بتوري المسموح بس معناه إن أي توكن يبعت اللي عايزه.
 *
 * 5. ⚠️ **كل حاجة جوّه ترانزاكشن واحدة** ومعاها `recalculate()`.
 */
class Returns
{
    /**
     * سقف الكمية للصنف الواحد في المستند — حزام أمان بعد ما المرتجع
     * اتفتح على الكتالوج كله (١٢/٨): مفيش سقف «المشترى» يمسك الغلطة.
     */
    public const MAX_QTY_PER_PRODUCT = 9999;

    /**
     * السطور المتاحة للرد لعميل — من فواتيره، بسعرها الأصلي.
     *
     * الترتيب من الأحدث للأقدم عن قصد: المرتجع في الواقع بيبقى من
     * آخر توريد، والعميل بيتحاسب بسعر آخر فاتورة.
     *
     * @return array<int, array<string, mixed>>  سطر لكل `invoice_item` فيه رصيد
     */
    public static function returnable(Client $client, ?int $productId = null): array
    {
        $lines = InvoiceItem::query()
            ->select('invoice_items.*')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('invoices.client_id', $client->id)
            ->when($productId, fn ($q) => $q->where('invoice_items.product_id', $productId))
            ->orderByDesc('invoices.created_at')
            ->orderByDesc('invoice_items.id')
            ->with(['product', 'invoice'])
            ->get();

        if ($lines->isEmpty()) {
            return [];
        }

        // ⚠️ **اللي اترجّع قبل كده في كويري واحدة.** لوب بيسأل لكل
        // سطر = كويري لكل صف، والشاشة دي بتتفتح مع كل مرتجع.
        $taken = ReturnItem::whereIn('invoice_item_id', $lines->pluck('id'))
            ->selectRaw('invoice_item_id, COALESCE(SUM(qty), 0) as q')
            ->groupBy('invoice_item_id')
            ->pluck('q', 'invoice_item_id');

        $out = [];

        foreach ($lines as $l) {
            $left = (int) $l->qty - (int) ($taken[$l->id] ?? 0);

            if ($left <= 0) {
                continue;
            }

            $out[] = [
                'invoice_item_id' => $l->id,
                'invoice_id' => $l->invoice_id,
                'invoice_number' => $l->invoice?->number,
                'invoice_date' => $l->invoice?->created_at?->toDateString(),
                'product_id' => $l->product_id,
                'product' => $l->product,
                'qty' => $left,
                'list_price' => (float) $l->list_price,
                'price' => (float) $l->price,
                'tax_rate' => (float) $l->tax_rate,
            ];
        }

        return $out;
    }

    /** إجمالي المتاح للرد لكل صنف — للشاشات اللي بتعرض بالصنف مش بالسطر */
    public static function returnableByProduct(Client $client): array
    {
        $out = [];

        foreach (self::returnable($client) as $r) {
            $out[$r['product_id']] = ($out[$r['product_id']] ?? 0) + $r['qty'];
        }

        return $out;
    }

    /**
     * إنشاء مرتجع.
     *
     * @param  array<int, array{product_id:int, qty:int, condition?:string}>  $items
     *
     * @throws Rejected
     */
    public static function create(
        Client $client,
        array $items,
        string $policy,
        ?User $rep = null,
        ?Visit $visit = null,
        ?string $note = null,
        ?string $idemKey = null,
        ?User $actor = null,
        string $source = 'app',
    ): ClientReturn {
        // ═══ 0. منع التكرار قبل أي شغل ═══
        //
        // ⚠️ الأبلكيشن بيولّد المفتاح مرة واحدة للشاشة، فإعادة
        // الإرسال بعد انقطاع شبكة بترجّع نفس المستند بدل ما تكتب
        // قيد دائن تاني. `N` نداءات كانت بتعمل `N` قيود.
        if ($idemKey !== null) {
            $prev = ClientReturn::where('idem_key', $idemKey)->first();

            if ($prev !== null) {
                return $prev;
            }
        }

        // ═══ سويتش المرتجعات (قرار المالك ١٠ أغسطس ٢٠٢٦) ═══
        //
        // المالك بيفتح ويقفل استقبال المرتجعات يدوياً من إعدادات
        // السيستم. الحارس هنا في الخدمة نفسها — فالأبلكيشن والـERP
        // بيترفضوا من نفس النقطة برسالة واضحة، ومفيش مسار يتفرّع.
        // ⚠️ بييجي **بعد** فحص الـidem_key فوق عن قصد: إعادة إرسال
        // لمستند اتعمل قبل القفل بترجّع المستند نفسه مش رسالة رفض.
        if (\App\Models\Setting::read('returns_open', '1') === '0') {
            throw new Rejected(__('api.returns_closed'));
        }

        if (! in_array($policy, ClientReturn::POLICIES, true)) {
            throw new Rejected(__('api.return_policy_unknown'));
        }

        // ⚠️ **السياسة بتتفحص على العميل مش على اللي جاي من الشاشة**
        if (! in_array($policy, $client->returnPolicies(), true)) {
            throw new Rejected(__('api.return_policy_not_allowed'));
        }

        // ⚠️ عميل الأمانة بضاعته أصلاً ملك بروماكس ومفيش مديونية بيع
        // تتخصم — مرتجعه بيتسوى من تقرير مبيعات الفرع مش من هنا
        if ($client->isConsignment()) {
            throw new Rejected(__('api.return_consignment'));
        }

        // ═══ 1. تجميع الكميات بالصنف والحالة ═══
        $wanted = [];

        foreach ($items as $i) {
            $pid = (int) $i['product_id'];
            $qty = (int) $i['qty'];
            $cond = $i['condition'] ?? ClientReturn::CONDITION_GOOD;

            if ($qty <= 0) {
                continue;
            }

            if (! in_array($cond, ClientReturn::CONDITIONS, true)) {
                throw new Rejected(__('api.return_condition_unknown'));
            }

            $wanted[$pid][$cond] = ($wanted[$pid][$cond] ?? 0) + $qty;
        }

        if ($wanted === []) {
            throw new Rejected(__('api.return_no_items'));
        }

        $custody = $rep?->currentCustody();

        // ⚠️ من غير عهدة مفيش مكان البضاعة تتحط فيه — مرتجع المندوب
        // بيتسجل على عربية. مرتجع الـERP بيدخل بلا عهدة (بضاعة
        // بترجع المخزن مباشرة) وده مقبول.
        if ($rep !== null && $custody === null) {
            throw new Rejected(__('field.no_custody_today'));
        }

        return DB::transaction(function () use (
            $client, $wanted, $policy, $rep, $visit, $note, $idemKey, $actor, $source, $custody
        ) {
            $doc = ClientReturn::create([
                'number' => ClientReturn::nextNumber(),
                'client_id' => $client->id,
                'user_id' => $rep?->id,
                'visit_id' => $visit?->id,
                'custody_id' => $custody?->id,
                'source' => $source,
                'policy' => $policy,
                'idem_key' => $idemKey,
                'note' => $note,
                'created_by' => $actor?->id ?? $rep?->id,
            ]);

            $subtotal = 0.0;
            $net = 0.0;
            $taxTotal = 0.0;
            $good = 0;
            $damaged = 0;

            foreach ($wanted as $pid => $byCondition) {
                $product = Product::findOrFail($pid);

                // ⚠️ **السطور المتاحة بتتقفل للتحديث.** من غير القفل
                // نداءين متوازيين بيقروا نفس «المتاح» والاتنين
                // يعدّوا — ونفس البضاعة تتسعّر من نفس السطر مرتين.
                $pool = self::lockedPool($client, $pid);
                $asked = array_sum($byCondition);

                // ⚠️ **شرط «اشتراه الأول» اتشال (قرار المالك ١٢/٨)** —
                // العميل ممكن يكون ماسك بضاعة من قبل السيستم. اللي
                // بقي هو حزام الأمان: سقف ثابت للصنف في المستند.
                if ($asked > self::MAX_QTY_PER_PRODUCT) {
                    throw new Rejected(__('api.return_qty_cap', [
                        'product' => $product->displayName(),
                        'max' => self::MAX_QTY_PER_PRODUCT,
                    ]));
                }

                foreach ($byCondition as $cond => $qty) {
                    $left = $qty;

                    // ⚠️ **بند مرتجع لكل سطر فاتورة** — الكمية ممكن
                    // تتاخد من فاتورتين بسعرين مختلفين، وده المقصود
                    // عشان الدفتر يعرف رجع بكام من كل واحدة.
                    foreach ($pool as &$row) {
                        if ($left <= 0) {
                            break;
                        }

                        $take = min($left, $row['qty']);

                        if ($take <= 0) {
                            continue;
                        }

                        $row['qty'] -= $take;
                        $left -= $take;

                        // ⚠️ **السعر من السطر نفسه** — `price` مخصوم
                        // أصلاً و`list_price` قبل الخصم، بالظبط زي
                        // ما اتسجلوا يوم البيع.
                        $lineTotal = round($take * (float) $row['price'], 2);
                        $lineTax = round($lineTotal * (float) $row['tax_rate'], 2);

                        ReturnItem::create([
                            'return_id' => $doc->id,
                            'product_id' => $pid,
                            'invoice_id' => $row['invoice_id'],
                            'invoice_item_id' => $row['invoice_item_id'],
                            'qty' => $take,
                            'condition' => $cond,
                            'list_price' => $row['list_price'],
                            'price' => $row['price'],
                            'total' => $lineTotal,
                            'tax_rate' => $row['tax_rate'],
                            'tax' => $lineTax,
                        ]);

                        $subtotal += round((float) $row['list_price'] * $take, 2);
                        $net += $lineTotal;
                        $taxTotal += $lineTax;
                    }
                    // ⚠️ **`unset` إجباري بعد `foreach` بمرجع** — من
                    // غيره `$row` بتفضل مرجع لآخر عنصر وأي لوب بعدها
                    // بيكتب فوقه. فخ معروف في المشروع.
                    unset($row);

                    // ═══ بضاعة قديمة من قبل السيستم (١٢ أغسطس ٢٠٢٦) ═══
                    //
                    // الكمية اللي مالهاش سطر فاتورة يغطيها — العميل
                    // ماسكها من قبل ما السيستم يشتغل (رصيد افتتاحي
                    // مااتسجلش). بتتسعّر بـ`Pricing` لنفس العميل زي
                    // ما فاتورة بيع كانت هتسعّرها النهارده، والضريبة
                    // من `Tax::rate` بنفس قاعدة البيع. البند بيتسجل
                    // بلا `invoice_item_id` — وده اللي بيفرّقه في
                    // المراجعة (عمود الفاتورة بيطبع «—»).
                    if ($left > 0) {
                        $listPrice = Pricing::listPriceFor($client, $product);
                        $unitPrice = Pricing::unitPrice($client, $product);

                        // ⚠️ صنف مش متسعّر في قايمة العميل = رفض —
                        // الصفر كان هيعدّي كأنه سعر حقيقي ويطلع
                        // قيد دائن ببلاش (نفس قاعدة البيع بالظبط).
                        if ($unitPrice <= 0 || $listPrice <= 0) {
                            throw new Rejected(__('api.product_not_priced', [
                                'product' => $product->displayName(),
                            ]));
                        }

                        $taxRate = Tax::rate($client, $product);
                        $lineTotal = round($left * $unitPrice, 2);
                        $lineTax = round($lineTotal * $taxRate, 2);

                        ReturnItem::create([
                            'return_id' => $doc->id,
                            'product_id' => $pid,
                            'invoice_id' => null,
                            'invoice_item_id' => null,
                            'qty' => $left,
                            'condition' => $cond,
                            'list_price' => $listPrice,
                            'price' => $unitPrice,
                            'total' => $lineTotal,
                            'tax_rate' => $taxRate,
                            'tax' => $lineTax,
                        ]);

                        $subtotal += round($listPrice * $left, 2);
                        $net += $lineTotal;
                        $taxTotal += $lineTax;
                        $left = 0;
                    }

                    if ($cond === ClientReturn::CONDITION_DAMAGED) {
                        $damaged += $qty;
                    } else {
                        $good += $qty;
                    }
                }

                // ═══ البضاعة تدخل العربية — سليم في خانة وتالف في خانة ═══
                if ($custody !== null) {
                    self::intoCustody($custody, $pid, $byCondition);
                }
            }

            $net = round($net, 2);
            $taxTotal = round($taxTotal, 2);
            $grand = round($net + $taxTotal, 2);

            $doc->update([
                'subtotal' => round($subtotal, 2),
                'discount' => round($subtotal - $net, 2),
                'total' => $net,
                'tax_total' => $taxTotal,
                'grand_total' => $grand,
                'good_units' => $good,
                'damaged_units' => $damaged,
            ]);

            self::postEntries($doc, $client, $rep, $visit, $grand, $taxTotal);

            $client->recalculate();

            return $doc->fresh(['items.product']);
        });
    }

    /**
     * سطور الفاتورة المتاحة للرد لصنف واحد — **مقفولة للتحديث**.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function lockedPool(Client $client, int $productId): array
    {
        $lines = InvoiceItem::query()
            ->select('invoice_items.*')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('invoices.client_id', $client->id)
            ->where('invoice_items.product_id', $productId)
            ->orderByDesc('invoices.created_at')
            ->orderByDesc('invoice_items.id')
            ->lockForUpdate()
            ->get();

        $taken = ReturnItem::whereIn('invoice_item_id', $lines->pluck('id'))
            ->selectRaw('invoice_item_id, COALESCE(SUM(qty), 0) as q')
            ->groupBy('invoice_item_id')
            ->pluck('q', 'invoice_item_id');

        $pool = [];

        foreach ($lines as $l) {
            $left = (int) $l->qty - (int) ($taken[$l->id] ?? 0);

            if ($left <= 0) {
                continue;
            }

            $pool[] = [
                'invoice_item_id' => $l->id,
                'invoice_id' => $l->invoice_id,
                'qty' => $left,
                'list_price' => (float) $l->list_price,
                'price' => (float) $l->price,
                'tax_rate' => (float) $l->tax_rate,
            ];
        }

        return $pool;
    }

    /**
     * البضاعة تدخل العربية — السليم للبيع والتالف لأ.
     *
     * @param  array<string, int>  $byCondition
     */
    private static function intoCustody(Custody $custody, int $productId, array $byCondition): void
    {
        // ⚠️ `batch_id = null` عن قصد — اللي راجع من العميل مش معروف
        // من أنهي تشغيلة، والافتراض بيخرّب تتبّع الصلاحية.
        $item = CustodyItem::firstOrCreate(
            ['custody_id' => $custody->id, 'product_id' => $productId, 'batch_id' => null],
            ['assigned' => 0, 'sold' => 0, 'returned' => 0, 'returned_in' => 0, 'damaged_in' => 0],
        );

        if ($g = (int) ($byCondition[ClientReturn::CONDITION_GOOD] ?? 0)) {
            $item->increment('returned_in', $g);
        }

        if ($d = (int) ($byCondition[ClientReturn::CONDITION_DAMAGED] ?? 0)) {
            $item->increment('damaged_in', $d);
        }
    }

    /**
     * القيود — بتختلف بالسياسة.
     *
     * ⚠️ **`source_type` هو المستند نفسه مش الزيارة.** قبل كده القيد
     * كان بيتربط بالزيارة، فمن القيد ماكانش ينفع توصل للبنود ولا
     * تعرف رجع إيه. المستند دلوقتي موجود، فهو المصدر.
     */
    private static function postEntries(
        ClientReturn $doc,
        Client $client,
        ?User $rep,
        ?Visit $visit,
        float $grand,
        float $taxTotal,
    ): void {
        if ($grand <= 0) {
            return;
        }

        $memo = __('flash.memo_return_doc', [
            'number' => $doc->number,
            'count' => $doc->good_units + $doc->damaged_units,
            'user' => $rep?->displayName() ?? __('common.office'),
        ]);

        // القيد الدائن — موجود في كل السياسات، وهو اللي بيقلل المديونية
        $credit = Transaction::create([
            'client_id' => $client->id,
            'date' => today(),
            'memo' => $memo,
            'debit' => 0,
            'credit' => $grand,
            'tax' => $taxTotal,
            'kind' => 'return',
            'source_type' => ClientReturn::class,
            'source_id' => $doc->id,
        ]);

        $refund = null;

        // ⚠️ **الكاش بس هو اللي بياخد قيد مدين مقابل.** المندوب ردّ
        // الفلوس في إيده، فالرصيد لازم يرجع زي ما كان — والقيد ده
        // هو اللي بيقل فلوس تصفيته (`RepSettlement` بيقرا `refund`).
        if ($doc->policy === ClientReturn::POLICY_CASH) {
            $refund = Transaction::create([
                'client_id' => $client->id,
                'date' => today(),
                'memo' => __('flash.memo_return_refund'),
                'debit' => $grand,
                'credit' => 0,
                'tax' => $taxTotal,
                'kind' => 'refund',
                // ⚠️ الزيارة مش المستند — تصفية المندوب بتدوّر على
                // قيود `refund` مصدرها زيارات المندوب في النافذة.
                'source_type' => $visit !== null ? Visit::class : ClientReturn::class,
                'source_id' => $visit?->id ?? $doc->id,
            ]);
        }

        $doc->update([
            'transaction_id' => $credit->id,
            'refund_transaction_id' => $refund?->id,
        ]);
    }
}
