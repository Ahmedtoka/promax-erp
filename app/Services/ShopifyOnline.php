<?php

namespace App\Services;

use App\Models\OnlineOrder;
use App\Models\OnlineOrderItem;
use App\Models\Product;
use App\Models\Setting;
use App\Models\ShopifyProductLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * ═══ شوبيفاي — موديول الأونلاين (٣/٩/٢٠٢٦) ═══
 *
 * كل كلام السيستم مع شوبيفاي في الملف ده وبس:
 *   syncOrders()     سينك الأوردرات الجديدة (since_id — الموجود مايتجابش)
 *   fetchProducts()  جلب المنتجات بفاريانتاتها لشاشة الربط
 *   pushSku()        كتابة كود المنتج كـSKU على الفاريانت في شوبيفاي
 *
 * ⚠️ الاعتماد Admin API token من Custom App (سكوبات read_orders +
 *   read_products + write_products) — محفوظ في Settings مش في الكود.
 * ⚠️ كل ميثود بترجع رسالة خطأ كسترنج بدل ما ترمي — الشاشة بتعرضها
 *   في alert أحمر والدنيا مابتقعش.
 * ⚠️ **ممنوع التوكن يتطبع في أي لوج أو رسالة خطأ.**
 */
class ShopifyOnline
{
    /** الإعداد جاهز؟ (الشاشة بتوري كارت الإعدادات لو لأ) */
    public static function ready(): bool
    {
        $s = Setting::all_();

        return trim($s['shopify_domain'] ?? '') !== ''
            && trim($s['shopify_admin_token'] ?? '') !== '';
    }

    /**
     * نداء REST خام. بيرجع [json|null, error|null].
     *
     * @param  array<string, mixed>  $payload  كويري للـGET وبودي لغيره
     */
    private static function api(string $method, string $path, array $payload = []): array
    {
        $s = Setting::all_();
        $domain = trim($s['shopify_domain'] ?? '');
        $token = trim($s['shopify_admin_token'] ?? '');
        $version = trim($s['shopify_api_version'] ?? '') ?: '2025-01';

        if ($domain === '' || $token === '') {
            return [null, __('online.err_not_configured')];
        }

        $url = 'https://'.$domain.'/admin/api/'.$version.'/'.ltrim($path, '/');

        try {
            $req = Http::withHeaders(['X-Shopify-Access-Token' => $token])
                ->acceptJson()->timeout(40);

            // ⚠️ بايلود فاضي لازم يتبعت `{}` مش `[]` — json_encode([])
            // بيطلع مصفوفة وشوبيفاي بترفضها 400 (ده اللي منع
            // orders/{id}/cancel.json تشتغل في أول تيست ٣/٩)
            $res = $method === 'get'
                ? $req->get($url, $payload)
                : $req->send(strtoupper($method), $url, [
                    'json' => $payload === [] ? (object) [] : $payload,
                ]);
        } catch (\Throwable $e) {
            // ⚠️ رسالة الاستثناء ممكن تحتوي الـURL — كفاية نوعه
            return [null, __('online.err_network')];
        }

        if ($res->status() === 401 || $res->status() === 403) {
            return [null, __('online.err_auth')];
        }

        if ($res->failed()) {
            // نص خطأ شوبيفاي (مقصوص) بيوفّر جولة تخمين كاملة —
            // «Unavailable Shipping Rate» أوضح من «خطأ 422»
            $detail = $res->json('errors') ?? $res->json('error');

            if (is_array($detail)) {
                $detail = json_encode($detail, JSON_UNESCAPED_UNICODE);
            }

            $detail = is_string($detail) ? mb_substr($detail, 0, 140) : '';

            return [null, __('online.err_http', ['code' => $res->status()])
                .($detail !== '' ? ' — '.$detail : '')];
        }

        return [$res->json(), null];
    }

    // ==================== سينك الأوردرات ====================

    /**
     * جلب كل الأوردرات اللي متعملهاش سينك.
     *
     * `since_id` = أكبر shopify_id عندنا — شوبيفاي بترجّع اللي بعده بس،
     * فالسينك idempotent بطبيعته والـunique على العمود خط دفاع تاني.
     *
     * @return array{created: int, error: ?string}
     */
    public static function syncOrders(): array
    {
        $created = 0;
        $failed = [];
        $sinceId = (int) OnlineOrder::max('shopify_id');

        // بحد أقصى 10 صفحات × 250 في النداء الواحد — سينك أول مرة
        // على متجر قديم يتعمل على دفعات بدل ما يعلّق الريكوست
        for ($page = 0; $page < 10; $page++) {
            // ⚠️ من بعد أول سينك كامل: **الجديد الغير مشحون بس** (قرار
            // المالك ٥/٩) — أوردر اتشحن أو اتلغى في شوبيفاي مالوش
            // لازمة في طابور الاتصال بتاعنا
            [$data, $err] = self::api('get', 'orders.json', [
                'status' => 'open',
                'fulfillment_status' => 'unshipped',
                'limit' => 250,
                'since_id' => $sinceId,
                'order' => 'id asc',
            ]);

            if ($err !== null) {
                return ['created' => $created, 'failed' => $failed, 'error' => $err];
            }

            $orders = $data['orders'] ?? [];

            if (empty($orders)) {
                break;
            }

            foreach ($orders as $o) {
                $sinceId = max($sinceId, (int) $o['id']);

                if (OnlineOrder::where('shopify_id', $o['id'])->exists()) {
                    continue;
                }

                // ⚠️ أوردر واحد ببايلود غريب مايوقعش السينك كله بـ500 —
                // بيتعدّى ويتبلّغ عنه، والباقي بيكمل (مراجعة ٣/٩)
                try {
                    self::storeOrder($o);
                    $created++;
                } catch (\Throwable $e) {
                    $failed[] = (string) ($o['order_number'] ?? $o['id']);
                }
            }

            if (count($orders) < 250) {
                break;
            }
        }

        return ['created' => $created, 'failed' => $failed, 'error' => null];
    }

    /** تخزين أوردر شوبيفاي واحد ببنوده — ذرّي */
    private static function storeOrder(array $o): void
    {
        $ship = $o['shipping_address'] ?? [];
        $customer = $o['customer'] ?? [];

        $name = trim(($ship['first_name'] ?? '').' '.($ship['last_name'] ?? ''));

        if ($name === '') {
            $name = trim(($customer['first_name'] ?? '').' '.($customer['last_name'] ?? ''));
        }

        $phone = $ship['phone'] ?? ($customer['phone'] ?? ($o['phone'] ?? null));
        $address = trim(($ship['address1'] ?? '').' '.($ship['address2'] ?? ''));
        $area = trim(implode(' - ', array_filter([
            $ship['city'] ?? null,
            $ship['province'] ?? null,
        ])));

        $shipping = (float) ($o['total_shipping_price_set']['shop_money']['amount'] ?? 0);

        $lines = $o['line_items'] ?? [];

        // عدد القطع الحقيقي = الكمية × قطع الباك (فاريانت الـ12 = 12 قطعة)
        $itemsCount = 0;

        foreach ($lines as $l) {
            $m = self::matchLine($l);
            $itemsCount += (int) ($l['quantity'] ?? 0) * $m['units'];
        }

        // أوردر ملغي في شوبيفاي بيدخل ملغي عندنا على طول —
        // مايظهرش في السينك ويبان في «كل الأوردرات» بحالته
        $status = ! empty($o['cancelled_at']) ? 'cancelled' : 'new';

        DB::transaction(function () use ($o, $name, $phone, $address, $area, $shipping, $lines, $itemsCount, $status) {
            $order = OnlineOrder::create([
                'shopify_id' => $o['id'],
                'number' => (string) ($o['order_number'] ?? $o['number'] ?? $o['id']),
                'customer_name' => $name !== '' ? mb_substr($name, 0, 190) : null,
                'phone' => $phone !== null ? mb_substr((string) $phone, 0, 40) : null,
                'address' => $address !== '' ? $address : null,
                'area' => $area !== '' ? mb_substr($area, 0, 150) : null,
                'items_count' => $itemsCount,
                'subtotal' => (float) ($o['subtotal_price'] ?? 0),
                'shipping' => $shipping,
                'total' => (float) ($o['total_price'] ?? 0),
                'status' => $status,
                'cancel_reason' => $status === 'cancelled' ? __('online.cancelled_in_shopify') : null,
                'ordered_at' => ! empty($o['created_at'])
                    ? \Illuminate\Support\Carbon::parse($o['created_at'])->setTimezone(config('app.timezone'))
                    : null,
            ]);

            foreach ($lines as $l) {
                $m = self::matchLine($l);

                OnlineOrderItem::create([
                    'online_order_id' => $order->id,
                    'shopify_line_id' => $l['id'] ?? null,
                    'shopify_variant_id' => $l['variant_id'] ?? null,
                    'sku' => isset($l['sku']) && $l['sku'] !== '' ? mb_substr($l['sku'], 0, 100) : null,
                    'title' => mb_substr((string) ($l['title'] ?? '—'), 0, 250),
                    'product_id' => $m['product_id'],
                    'units_per' => $m['units'],
                    'qty' => (int) ($l['quantity'] ?? 1),
                    'price' => (float) ($l['price'] ?? 0),
                    // ⚠️ خصم البند (total_discount) لازم يتخصم — من غيره
                    // مجموع البنود مايساويش الإجمالي على أي أوردر عليه
                    // خصم في شوبيفاي، والفاتورة المطبوعة بتطلع مش مظبوطة
                    'total' => round(
                        (float) ($l['price'] ?? 0) * (int) ($l['quantity'] ?? 1)
                        - (float) ($l['total_discount'] ?? 0), 2),
                ]);
            }
        });
    }

    /**
     * مطابقة بند شوبيفاي بمنتج السيستم **وعدد قطع الباك**:
     * ١) جدول الربط بالفاريانت (شاشة ربط المنتجات) — بيرجع المنتج
     *    و`units` (فاريانت الـ12 قطعة = 12 من منتج السيستم)
     * ٢) الـSKU على كود المنتج — باك مجهول = قطعة واحدة
     * لو مفيش منتج → null، والأوردر مايتأكدش لحد ما الربط يتعمل.
     *
     * @return array{product_id: ?int, units: int}
     */
    private static function matchLine(array $line): array
    {
        $variantId = $line['variant_id'] ?? null;

        if ($variantId !== null) {
            $link = ShopifyProductLink::where('shopify_variant_id', $variantId)
                ->first(['product_id', 'units']);

            if ($link?->product_id !== null) {
                return [
                    'product_id' => (int) $link->product_id,
                    'units' => max((int) $link->units, 1),
                ];
            }
        }

        $sku = trim((string) ($line['sku'] ?? ''));

        if ($sku !== '') {
            $byCode = Product::where('code', $sku)->value('id');

            if ($byCode !== null) {
                return ['product_id' => (int) $byCode, 'units' => 1];
            }
        }

        return ['product_id' => null, 'units' => 1];
    }

    /** إعادة محاولة المطابقة للبنود الفاضية — بعد أي حفظ في شاشة الربط */
    public static function rematchUnlinked(): int
    {
        $fixed = 0;
        $touchedOrders = [];

        OnlineOrderItem::whereNull('product_id')
            ->whereHas('order', fn ($q) => $q->whereIn('status', ['new', 'postponed']))
            ->with('order')
            ->chunkById(200, function ($items) use (&$fixed, &$touchedOrders) {
                foreach ($items as $item) {
                    $m = self::matchLine([
                        'variant_id' => $item->shopify_variant_id,
                        'sku' => $item->sku,
                    ]);

                    if ($m['product_id'] !== null) {
                        $item->update(['product_id' => $m['product_id'], 'units_per' => $m['units']]);
                        $touchedOrders[$item->online_order_id] = true;
                        $fixed++;
                    }
                }
            });

        // عدد القطع على الأوردر بيتحسب تاني — الباك اتعرف بعد السينك
        foreach (array_keys($touchedOrders) as $orderId) {
            $order = OnlineOrder::with('items')->find($orderId);
            $order?->update([
                'items_count' => $order->items->sum(fn ($i) => $i->pieces()),
            ]);
        }

        return $fixed;
    }

    // ==================== GraphQL — الحالات الحقيقية ====================

    /**
     * نداء GraphQL Admin — نفس التوكن ونفس الدومين.
     * بيرجع [data|null, error|null]، والأخطاء بنوعيها (errors العلوية
     * وuserErrors بتاعة الميوتيشن) بتتلم في رسالة واحدة مقروءة.
     */
    private static function gql(string $query, array $vars = []): array
    {
        [$res, $err] = self::api('post', 'graphql.json', [
            'query' => $query,
            'variables' => $vars,
        ]);

        if ($err !== null) {
            return [null, $err];
        }

        if (! empty($res['errors'])) {
            $msg = $res['errors'][0]['message'] ?? 'GraphQL error';

            return [null, mb_substr((string) $msg, 0, 140)];
        }

        return [$res['data'] ?? [], null];
    }

    /** أول userError في ريسبونس ميوتيشن — null لو مفيش */
    private static function userError(?array $node): ?string
    {
        $e = $node['userErrors'][0]['message'] ?? null;

        return $e !== null ? mb_substr((string) $e, 0, 140) : null;
    }

    /**
     * ═══ الأوردر بيتقلب Fulfilled في شوبيفاي أول ما يطلع في بيك اب
     * (قرار المالك ٥/٩) ═══
     *
     * بنجيب fulfillment orders المفتوحة وبنعمل fulfillment لكل واحد
     * (كل بنوده) من غير إشعار للعميل.
     *
     * ⚠️ محتاج سكوبات read/write_merchant_managed_fulfillment_orders
     * (+ read/write_fulfillments) على الـCustom App — وتعديل السكوبات
     * بيولّد **توكن جديد** لازم يتحدث في الإعدادات فوراً.
     * 🔴 النجاح = fulfillment.id متعمّر — مش غياب الخطأ (عقيدة شوبيفاي).
     */
    public static function fulfillOrder(OnlineOrder $order): ?string
    {
        if (! self::ready()) {
            return __('online.err_not_configured');
        }

        if (! $order->shopify_id) {
            return __('online.fulfill_failed', ['number' => $order->number]).' (no shopify id)';
        }

        $gid = 'gid://shopify/Order/'.$order->shopify_id;

        [$data, $err] = self::gql(
            'query($id: ID!) { order(id: $id) {
                fulfillmentOrders(first: 10) { nodes { id status } }
            } }',
            ['id' => $gid],
        );

        if ($err !== null) {
            return __('online.fulfill_failed', ['number' => $order->number]).' ('.$err.')';
        }

        if (! isset($data['order']) || $data['order'] === null) {
            // الأوردر نفسه مش راجع — غالباً سكوبات ناقصة أو gid غلط
            return __('online.fulfill_failed', ['number' => $order->number]).' (order not returned — check scopes)';
        }

        $nodes = $data['order']['fulfillmentOrders']['nodes'] ?? [];

        // ⚠️ Payment pending وأشباهه بيسيبوا الـ FO على ON_HOLD أو SCHEDULED —
        // بنفك الهولد / بنفتح الـ scheduled وبعدين نفلفل. أي حالة تانية
        // (غير CLOSED/CANCELLED) بنطلعها بالاسم بدل السكوت.
        $open = [];
        $blocked = [];

        foreach ($nodes as $n) {
            $st = $n['status'] ?? '';

            if (in_array($st, ['OPEN', 'IN_PROGRESS'], true)) {
                $open[] = $n;

                continue;
            }

            if ($st === 'ON_HOLD' || $st === 'SCHEDULED') {
                $mutName = $st === 'ON_HOLD' ? 'fulfillmentOrderReleaseHold' : 'fulfillmentOrderOpen';
                [$m, $e] = self::gql(
                    'mutation($id: ID!) { '.$mutName.'(id: $id) {
                        fulfillmentOrder { id status }
                        userErrors { message }
                    } }',
                    ['id' => $n['id']],
                );
                $ue = $e ?? self::userError($m[$mutName] ?? null);

                if ($ue === null && ! empty($m[$mutName]['fulfillmentOrder']['id'])) {
                    $open[] = $n;
                } else {
                    $blocked[] = $st.($ue !== null ? ' — '.$ue : '');
                }

                continue;
            }

            if (! in_array($st, ['CLOSED', 'CANCELLED'], true)) {
                $blocked[] = $st;
            }
        }

        if (empty($open)) {
            if (! empty($blocked)) {
                return __('online.fulfill_failed', ['number' => $order->number])
                    .' ('.implode(' · ', array_unique($blocked)).')';
            }

            if (empty($nodes)) {
                return __('online.fulfill_no_fo', ['number' => $order->number]);
            }

            // كلها CLOSED — متفلفل فعلاً من قبل
            return null;
        }

        foreach ($open as $fo) {
            [$mut, $err2] = self::gql(
                'mutation($f: FulfillmentInput!) {
                    fulfillmentCreate(fulfillment: $f) {
                        fulfillment { id }
                        userErrors { message }
                    }
                }',
                ['f' => [
                    'lineItemsByFulfillmentOrder' => [
                        ['fulfillmentOrderId' => $fo['id']],
                    ],
                    'notifyCustomer' => false,
                ]],
            );

            $ue = $err2 ?? self::userError($mut['fulfillmentCreate'] ?? null);

            if ($ue !== null || empty($mut['fulfillmentCreate']['fulfillment']['id'])) {
                return __('online.fulfill_failed', ['number' => $order->number])
                    .($ue !== null ? ' ('.$ue.')' : '');
            }
        }

        return null;
    }

    /**
     * ═══ الأوردر بيتقلب Paid أول ما يتحصّل بالكامل (٥/٩) ═══
     * orderMarkAsPaid — نفس زرار «Mark as paid» في أدمن شوبيفاي،
     * وده الصح لأوردرات الكاش أون ديليفري المعلقة pending.
     */
    public static function markPaid(OnlineOrder $order): ?string
    {
        if (! self::ready() || ! $order->shopify_id) {
            return null;
        }

        [$mut, $err] = self::gql(
            'mutation($input: OrderMarkAsPaidInput!) {
                orderMarkAsPaid(input: $input) {
                    order { id }
                    userErrors { message }
                }
            }',
            ['input' => ['id' => 'gid://shopify/Order/'.$order->shopify_id]],
        );

        $ue = $err ?? self::userError($mut['orderMarkAsPaid'] ?? null);

        if ($ue !== null || empty($mut['orderMarkAsPaid']['order']['id'])) {
            return __('online.paid_push_failed', ['number' => $order->number])
                .($ue !== null ? ' ('.$ue.')' : '');
        }

        return null;
    }

    /**
     * ═══ ريتيرن / بارشال ريتيرن حقيقي في شوبيفاي (٥/٩) ═══
     *
     * بالكميات اللي رجعت فعلاً: بنجيب بنود الفلفلمنت القابلة للإرجاع
     * (returnableFulfillments — معمولة مخصوص لبناء المرتجعات)، بنطابقها
     * على line items الأوردر، وبنعمل returnCreate ثم returnClose —
     * فالأوردر بياخد باج Returned أو Partially returned حسب العدد.
     *
     * ⚠️ محتاج سكوبات read/write_returns.
     * @param  array<int, int>  $qtyByLineId  [shopify_line_id => باكات راجعة]
     */
    public static function createReturn(OnlineOrder $order, array $qtyByLineId): ?string
    {
        if (! self::ready() || ! $order->shopify_id || empty($qtyByLineId)) {
            return null;
        }

        $gid = 'gid://shopify/Order/'.$order->shopify_id;

        [$data, $err] = self::gql(
            'query($oid: ID!) {
                returnableFulfillments(orderId: $oid, first: 10) {
                    edges { node {
                        returnableFulfillmentLineItems(first: 50) {
                            edges { node {
                                fulfillmentLineItem { id lineItem { id } }
                                quantity
                            } }
                        }
                    } }
                }
            }',
            ['oid' => $gid],
        );

        if ($err !== null) {
            return __('online.return_push_failed', ['number' => $order->number]).' ('.$err.')';
        }

        // خريطة line item → بنود فلفلمنت قابلة للإرجاع (ممكن أكتر من واحد)
        $lines = [];

        foreach ($data['returnableFulfillments']['edges'] ?? [] as $f) {
            foreach ($f['node']['returnableFulfillmentLineItems']['edges'] ?? [] as $li) {
                $node = $li['node'];
                $lineGid = $node['fulfillmentLineItem']['lineItem']['id'] ?? '';

                if (preg_match('~/LineItem/(\d+)$~', $lineGid, $m)) {
                    $lines[(int) $m[1]][] = [
                        'fid' => $node['fulfillmentLineItem']['id'],
                        'available' => (int) $node['quantity'],
                    ];
                }
            }
        }

        $returnLineItems = [];

        foreach ($qtyByLineId as $lineId => $qty) {
            $need = (int) $qty;

            foreach ($lines[(int) $lineId] ?? [] as $slot) {
                if ($need <= 0) {
                    break;
                }

                $take = min($need, $slot['available']);

                if ($take > 0) {
                    $returnLineItems[] = [
                        'fulfillmentLineItemId' => $slot['fid'],
                        'quantity' => $take,
                        'returnReason' => 'OTHER',
                    ];
                    $need -= $take;
                }
            }

            if ($need > 0) {
                // مفيش كمية قابلة للإرجاع كفاية في شوبيفاي — بلّغ ومتكسّرش
                return __('online.return_push_failed', ['number' => $order->number])
                    .' (returnable < requested)';
            }
        }

        if (empty($returnLineItems)) {
            return __('online.return_push_failed', ['number' => $order->number]);
        }

        [$mut, $err2] = self::gql(
            'mutation($input: ReturnInput!) {
                returnCreate(returnInput: $input) {
                    return { id }
                    userErrors { message }
                }
            }',
            ['input' => [
                'orderId' => $gid,
                'returnLineItems' => $returnLineItems,
            ]],
        );

        $ue = $err2 ?? self::userError($mut['returnCreate'] ?? null);
        $returnId = $mut['returnCreate']['return']['id'] ?? null;

        if ($ue !== null || empty($returnId)) {
            return __('online.return_push_failed', ['number' => $order->number])
                .($ue !== null ? ' ('.$ue.')' : '');
        }

        // قفل المرتجع — البضاعة رجعت مخزننا فعلاً، فالريتيرن مكتمل.
        // فشل القفل مش بيرجّع خطأ: الريتيرن نفسه اتسجل والباج ظهر.
        self::gql(
            'mutation($id: ID!) {
                returnClose(id: $id) { return { id } userErrors { message } }
            }',
            ['id' => $returnId],
        );

        return null;
    }

    // ==================== دفع الحالة لشوبيفاي ====================

    /**
     * ═══ «الابديت يسمع في شوبيفاي في كل خطوة» (قرار المالك ٣/٩) ═══
     *
     * كل تغيير حالة عندنا بيتكتب **تاج** على الأوردر في شوبيفاي:
     * pmx-confirmed · pmx-preparing · pmx-ready · pmx-shipped ·
     * pmx-returned · pmx-completed · pmx-cancelled ·
     * pmx-postponed-YYYY-MM-DD — تاجاتنا كلها ببادئة pmx- وبنشيل
     * القديمة قبل ما نحط الجديدة، وتاجات المتجر نفسها مابنلمسهاش.
     *
     * ⚠️ **مش بتوقف الفلو المحلي أبداً** — بترجع رسالة تحذير للعرض
     * بس. محتاجة سكوب write_orders على الـCustom App.
     */
    public static function pushStatus(OnlineOrder $order, ?string $tag = null): ?string
    {
        if (! self::ready() || ! $order->shopify_id) {
            return null;
        }

        [$data, $err] = self::api('get', 'orders/'.$order->shopify_id.'.json', ['fields' => 'id,tags']);

        if ($err !== null) {
            return __('online.push_failed', ['number' => $order->number]);
        }

        $tags = array_values(array_filter(
            array_map('trim', explode(',', (string) ($data['order']['tags'] ?? ''))),
            fn ($t) => $t !== '' && ! str_starts_with($t, 'pmx-'),
        ));

        if ($tag === null) {
            $tag = 'pmx-'.$order->status;

            if ($order->status === 'postponed' && $order->postponed_to !== null) {
                $tag .= '-'.$order->postponed_to->format('Y-m-d');
            }
        }

        if ($tag !== '') {
            $tags[] = $tag;
        }

        [$res, $err2] = self::api('put', 'orders/'.$order->shopify_id.'.json', [
            'order' => ['id' => (int) $order->shopify_id, 'tags' => implode(', ', $tags)],
        ]);

        if ($err2 !== null || empty($res['order']['id'])) {
            return __('online.push_failed', ['number' => $order->number]);
        }

        return null;
    }

    /**
     * إلغاء الأوردر في شوبيفاي نفسها — مع تاج pmx-cancelled وكتابة
     * **سبب الإلغاء في نوت الأوردر** عشان يتشاف من أدمن شوبيفاي
     * (endpoint الإلغاء نفسه مابياخدش نص حر).
     * فشل الإلغاء هناك مايرجّعش الإلغاء هنا، بيتبلّغ بس.
     */
    public static function cancelInShopify(OnlineOrder $order): ?string
    {
        if (! self::ready() || ! $order->shopify_id) {
            return null;
        }

        [$data, $err] = self::api('post', 'orders/'.$order->shopify_id.'/cancel.json', [
            'reason' => 'other',
        ]);

        // السبب اللي التيم كتبه → نوت الأوردر في شوبيفاي
        if ($order->cancel_reason !== null && $order->cancel_reason !== '') {
            self::api('put', 'orders/'.$order->shopify_id.'.json', [
                'order' => [
                    'id' => (int) $order->shopify_id,
                    'note' => 'PROMAX: '.__('online.status_cancelled').' — '.$order->cancel_reason,
                ],
            ]);
        }

        $tagWarn = self::pushStatus($order);

        if ($err !== null) {
            return __('online.cancel_push_failed', ['number' => $order->number]).' ('.$err.')';
        }

        return $tagWarn;
    }

    // ==================== المنتجات والربط ====================

    /**
     * جلب منتجات شوبيفاي بفاريانتاتها لجدول الربط.
     * بيحدّث العنوان والـSKU والصورة — **ومايلمسش product_id** (الربط
     * اللي المالك عمله بإيده مايتداسش بسينك).
     *
     * @return array{fetched: int, error: ?string}
     */
    public static function fetchProducts(): array
    {
        $fetched = 0;
        $sinceId = 0;

        for ($page = 0; $page < 20; $page++) {
            [$data, $err] = self::api('get', 'products.json', [
                'limit' => 250,
                'since_id' => $sinceId,
            ]);

            if ($err !== null) {
                return ['fetched' => $fetched, 'error' => $err];
            }

            $products = $data['products'] ?? [];

            if (empty($products)) {
                break;
            }

            foreach ($products as $p) {
                $sinceId = max($sinceId, (int) $p['id']);
                $image = $p['image']['src'] ?? null;

                foreach ($p['variants'] ?? [] as $v) {
                    $vTitle = ($v['title'] ?? '') !== 'Default Title'
                        ? mb_substr((string) $v['title'], 0, 190) : null;

                    $fields = [
                        'shopify_product_id' => $p['id'],
                        'title' => mb_substr((string) $p['title'], 0, 250),
                        'variant_title' => $vTitle,
                        'sku' => ($v['sku'] ?? '') !== '' ? mb_substr($v['sku'], 0, 100) : null,
                        'image' => $image !== null ? mb_substr($image, 0, 500) : null,
                    ];

                    $link = ShopifyProductLink::where('shopify_variant_id', $v['id'])->first();

                    if ($link !== null) {
                        // ⚠️ الجلب مايداسش على اللي المالك حدده بإيده —
                        // product_id وunits بيتعدلوا من شاشة الربط بس
                        $link->update($fields);
                    } else {
                        // تخمين قطع الباك من اسم الفاريانت («pcs 12» /
                        // «6 (50%)») — أول رقم فيه، والمالك بيصححه من
                        // خانة القطع لو التخمين غلط
                        $guess = 1;

                        if ($vTitle !== null && preg_match('/(\d+)/', $vTitle, $mm)) {
                            $guess = max((int) $mm[1], 1);
                        }

                        ShopifyProductLink::create($fields + [
                            'shopify_variant_id' => $v['id'],
                            'units' => $guess,
                        ]);
                    }

                    $fetched++;
                }
            }

            if (count($products) < 250) {
                break;
            }
        }

        return ['fetched' => $fetched, 'error' => null];
    }

    /**
     * كتابة الـSKU على الفاريانت في شوبيفاي (قرار المالك ٣/٩:
     * «يتعملها Save في شوبيفاي كمان»). بترجع null عند النجاح.
     */
    public static function pushSku(ShopifyProductLink $link, string $sku): ?string
    {
        [$data, $err] = self::api('put', 'variants/'.$link->shopify_variant_id.'.json', [
            'variant' => [
                'id' => (int) $link->shopify_variant_id,
                'sku' => $sku,
            ],
        ]);

        if ($err !== null) {
            return $err;
        }

        // ⚠️ **النجاح = الـid راجع متعمّر** — مش مجرد غياب الخطأ
        // (نفس درس Le Voile مع باتش الأكواد: شوبيفاي بترد 200 بجسم
        // فاضي المعنى في حالات فشل حقيقية).
        if (empty($data['variant']['id'])) {
            return __('online.err_sku_push');
        }

        $link->update(['sku' => $sku, 'sku_pushed_at' => now()]);

        return null;
    }
}
