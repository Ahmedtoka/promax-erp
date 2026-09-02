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

            $res = $method === 'get'
                ? $req->get($url, $payload)
                : $req->send(strtoupper($method), $url, ['json' => $payload]);
        } catch (\Throwable $e) {
            // ⚠️ رسالة الاستثناء ممكن تحتوي الـURL — كفاية نوعه
            return [null, __('online.err_network')];
        }

        if ($res->status() === 401 || $res->status() === 403) {
            return [null, __('online.err_auth')];
        }

        if ($res->failed()) {
            return [null, __('online.err_http', ['code' => $res->status()])];
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
            [$data, $err] = self::api('get', 'orders.json', [
                'status' => 'any',
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
        $itemsCount = array_sum(array_map(fn ($l) => (int) ($l['quantity'] ?? 0), $lines));

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
                OnlineOrderItem::create([
                    'online_order_id' => $order->id,
                    'shopify_line_id' => $l['id'] ?? null,
                    'shopify_variant_id' => $l['variant_id'] ?? null,
                    'sku' => isset($l['sku']) && $l['sku'] !== '' ? mb_substr($l['sku'], 0, 100) : null,
                    'title' => mb_substr((string) ($l['title'] ?? '—'), 0, 250),
                    'product_id' => self::matchProduct($l),
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
     * مطابقة بند شوبيفاي بمنتج السيستم:
     * ١) جدول الربط بالفاريانت (شاشة ربط المنتجات)
     * ٢) الـSKU على كود المنتج
     * لو مفيش → null، والأوردر مايتأكدش لحد ما الربط يتعمل.
     */
    private static function matchProduct(array $line): ?int
    {
        $variantId = $line['variant_id'] ?? null;

        if ($variantId !== null) {
            $linked = ShopifyProductLink::where('shopify_variant_id', $variantId)
                ->value('product_id');

            if ($linked !== null) {
                return (int) $linked;
            }
        }

        $sku = trim((string) ($line['sku'] ?? ''));

        if ($sku !== '') {
            $byCode = Product::where('code', $sku)->value('id');

            if ($byCode !== null) {
                return (int) $byCode;
            }
        }

        return null;
    }

    /** إعادة محاولة المطابقة للبنود الفاضية — بعد أي حفظ في شاشة الربط */
    public static function rematchUnlinked(): int
    {
        $fixed = 0;

        OnlineOrderItem::whereNull('product_id')
            ->whereHas('order', fn ($q) => $q->whereIn('status', ['new', 'postponed']))
            ->with('order')
            ->chunkById(200, function ($items) use (&$fixed) {
                foreach ($items as $item) {
                    $pid = self::matchProduct([
                        'variant_id' => $item->shopify_variant_id,
                        'sku' => $item->sku,
                    ]);

                    if ($pid !== null) {
                        $item->update(['product_id' => $pid]);
                        $fixed++;
                    }
                }
            });

        return $fixed;
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
                    ShopifyProductLink::updateOrCreate(
                        ['shopify_variant_id' => $v['id']],
                        [
                            'shopify_product_id' => $p['id'],
                            'title' => mb_substr((string) $p['title'], 0, 250),
                            'variant_title' => ($v['title'] ?? '') !== 'Default Title'
                                ? mb_substr((string) $v['title'], 0, 190) : null,
                            'sku' => ($v['sku'] ?? '') !== '' ? mb_substr($v['sku'], 0, 100) : null,
                            'image' => $image !== null ? mb_substr($image, 0, 500) : null,
                        ],
                    );
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
