<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\Client;
use App\Models\PriceList;
use App\Models\Product;

/**
 * ═══════════════════════════════════════════════════════════════
 * دوكترين التسعير — المكان الوحيد اللي بيحسب سعر بيع في السيستم
 * Pricing doctrine — the ONLY place a selling price is computed.
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ ممنوع أي شاشة أو كنترولر يحسب سعر بنفسه. لو محتاج سعر
 * نادِ على Pricing::quote() أو Pricing::listPrice().
 *
 * الترتيب:
 *   1. قائمة السعر  → العقد يثبّتها، وإلا العميل، وإلا الافتراضية
 *   2. سعر القائمة  → `price_list_items` (وإلا الأعمدة القديمة)
 *   3. الخصم        → العقد → خصم خاص → السلسلة → القناة
 *   4. التكلفة      → تكلفة الباتش لو معروف، وإلا تكلفة المنتج
 */
class Pricing
{
    public const LIST_OLD = 'old';
    public const LIST_NEW = 'new';

    public const LISTS = [self::LIST_OLD, self::LIST_NEW];

    /**
     * قائمة السعر المعتمدة للعميل.
     * العقد بيغلب لأنه اتفاق مكتوب.
     */
    /**
     * صف قائمة السعر المعتمد للعميل.
     *
     * ⚠️ **القوايم بقت مسمّاة ومفتوحة العدد.** كانت عمودين ثابتين
     * (`price_old` / `price_new`)، ودلوقتي صفوف في `price_lists`.
     * الدالة دي هي اللي بتقرر أنهي قايمة، وكل حساب سعر بيمرّ منها.
     *
     * ⚠️ **العقد بيغلب لأنه اتفاق مكتوب**، وبعده اللي على العميل،
     * وبعده الافتراضية. العميل اللي مالوش ولا واحدة فيهم بياخد
     * الافتراضية بدل ما يتباع بصفر.
     */
    public static function listRowFor(Client $client): ?PriceList
    {
        $contract = $client->liveContract();

        // ⚠️ **لازم تكون مفعّلة.** قايمة موقوفة معناها إن أسعارها
        // مش معتمدة — والبيع بيها بيطلّع فاتورة بسعر محدش أقرّه.
        $fromContract = $contract?->priceListRow;

        if ($fromContract && $fromContract->active) {
            return $fromContract;
        }

        // ⚠️ **العمود النصي القديم لازم يتحترم قبل الافتراضية**
        // (إصلاح ١٣ أغسطس ٢٠٢٦). العميل اللي `price_list_id` بتاعه
        // فاضي و`price_list` بتاعه `old` كان بيقع على `default()`
        // (اللي هي `new`) — يعني **عميل على القايمة القديمة يتحاسب
        // بالجديدة في صمت**، وده كسر لاتفاق مكتوب. المسار ده حقيقي:
        // تحويل ليد، موافقة طلب عميل، والاستيراد بيكتبوا العمود
        // النصي من غير الـid. الاحتياطي اللي في `listFor` كان ميت
        // لأن `default()` عمرها ماترجّع `null` بعد مايجريشن 000060.
        if ($contract && ($row = PriceList::byCode($contract->price_list)) !== null) {
            return $row;
        }

        $own = $client->priceListRow;

        if ($own && $own->active) {
            return $own;
        }

        if (($row = PriceList::byCode($client->price_list)) !== null) {
            return $row;
        }

        return PriceList::default();
    }

    /**
     * كود قايمة السعر المعتمدة — للعرض والتوافق القديم.
     *
     * ⚠️ **مشتقة من `listRowFor` أولاً** (إصلاح 2026-08-07). كانت
     * بتقرا عمود `price_list` النصي لوحده، فكانت بتخالف اللي
     * الفاتورة بتتحاسب بيه لما العميل يبقى على قايمة مسمّاة.
     * دلوقتي مصدر واحد، والقراءة النصية بقت احتياطي للداتابيز
     * اللي لسه ماهاجرتش.
     *
     * ⚠️ **بترجّع كود القايمة زي ما هو حتى لو مش `old`/`new`.**
     * اللي بيمرّرها لـ`listPrice` كنص لازم يبقى فاهم إن القايمة
     * المسمّاة أسعارها في `price_list_items` — عشان كده كل مسارات
     * البيع بتمرّر صف القايمة نفسه مش الكود.
     */
    public static function listFor(Client $client): string
    {
        if (($row = self::listRowFor($client)) !== null) {
            return $row->code;
        }

        // ⚠️ liveContract() هي المصدر الوحيد للعقد: بتاع العميل لو موجود
        // وسارٍ، وإلا بتاع سلسلته. وبتتأكد إنه active ومش منتهي — لولا كده
        // كان ممكن العميل ياخد قائمة سعر من عقد ميت.
        $contract = $client->liveContract();

        $fromContract = $contract?->price_list;
        if (in_array($fromContract, self::LISTS, true)) {
            return $fromContract;
        }

        return in_array($client->price_list, self::LISTS, true)
            ? $client->price_list
            : self::LIST_NEW;
    }

    /**
     * سعر القائمة قبل أي خصم.
     *
     * ⚠️ **`price_list_items` هي المصدر، والأعمدة القديمة احتياطي.**
     * الشغل التاريخي كله (فواتير وتقارير قديمة) اتحسب من الأعمدة،
     * فمسحها بيحوّل كل رقم قديم لصفر. المايجريشن نقلتهم لقايمتين،
     * والاحتياطي هنا بيغطي أي داتابيز لسه ماهاجرتش.
     */
    public static function listPrice(Product $product, string|PriceList|null $list = null): float
    {
        if ($list instanceof PriceList) {
            $price = $list->priceFor($product);

            if ($price > 0) {
                return $price;
            }

            // ⚠️ **القايمة المسمّاة الناقصة بترجّع صفر — مش سعر قايمة
            // تانية.** الرجوع لـ`price_new` كان معناه إن عميل على
            // «قائمة 3» والصنف مش متسعّر فيها يتحاسب بسعر محدش
            // اعتمده للقايمة دي، في صمت. الصفر بيتلقف عند البيع
            // وبيترفض — قرار المالك إن كل قايمة لازم تكون كاملة.
            if (! in_array($list->code, self::LISTS, true)) {
                return 0.0;
            }

            // القوايم المنقولة أكوادها `old`/`new` — نرجع لعمودها
            $list = $list->code;
        }

        $list = $list ?: self::LIST_NEW;

        return (float) ($list === self::LIST_OLD ? $product->price_old : $product->price_new);
    }

    /**
     * سعر صنف لعميل — قبل الخصم.
     *
     * ⚠️ **بترجّع `null` لو الصنف مش متسعّر في قايمة العميل.** صفر
     * كان هيعدّي كأنه سعر حقيقي وتطلع فاتورة مجانية؛ الـ`null`
     * بتخلّي اللي بينادي يقرر: يرفض البيع، أو يقول إن الصنف ده
     * مالوش سعر للعميل ده. (قرار المالك: كل قايمة لازم تكون كاملة،
     * فالحالة دي مفروض ماتحصلش أصلاً — ودي شبكة الأمان.)
     */
    public static function priceForClient(Client $client, Product $product): ?float
    {
        $list = self::listRowFor($client);

        if ($list === null) {
            return null;
        }

        $price = self::listPrice($product, $list);

        return $price > 0 ? $price : null;
    }

    /**
     * مزامنة عمودي السعر مع القايمتين المهاجرتين (`old`/`new`).
     *
     * ⚠️ **الاتجاهين لازم يفضلوا متطابقين.** الفواتير بتقرا
     * `price_list_items` والـKPIs والأبلكيشن بيقروا العمودين —
     * لو اتفرقوا، المندوب بيشوف سعر والفاتورة بتطلع بسعر تاني.
     * فورم الصنف والمستورد بينادوا الدالة دي (عمود ← قايمة)،
     * وشاشة التسعير بتكتب العمود مع القايمة (قايمة ← عمود).
     *
     * ⚠️ **الصفر مابيدوسش على سعر حقيقي.** المستورد والسيدر بيعملوا
     * أصناف أسعارها 0 — لو الصفر ده اتكتب فوق سعر معتمد في القايمة
     * الافتراضية، الصنف بيبقى «ناقص» فيها وأي فاتورة بيه بصفر.
     */
    public static function syncColumnsToLists(Product $product): void
    {
        foreach (['old' => 'price_old', 'new' => 'price_new'] as $code => $column) {
            $list = PriceList::where('code', $code)->first();

            if ($list === null) {
                continue;   // داتابيز لسه ماهاجرتش — العمود هو المصدر
            }

            $value = (float) $product->{$column};

            $item = \App\Models\PriceListItem::firstOrNew([
                'price_list_id' => $list->id,
                'product_id' => $product->id,
            ]);

            if ($value <= 0 && $item->exists && (float) $item->price > 0) {
                continue;
            }

            $item->price = $value;
            $item->save();
        }
    }

    /**
     * التكلفة المعتمدة. تكلفة الباتش أدق لأنها فعلية،
     * وتكلفة المنتج قياسية بتستخدم لو الباتش مش معروف.
     */
    public static function costFor(Product $product, ?Batch $batch = null): float
    {
        $batchCost = $batch?->cost === null ? 0.0 : (float) $batch->cost;

        return $batchCost > 0 ? $batchCost : (float) $product->cost;
    }

    /**
     * التسعير الكامل لصنف على عميل.
     *
     * @return array{
     *   list: string, list_price: float, discount_pct: float, discount_source: string,
     *   unit_price: float, unit_cost: float, margin: float, margin_pct: float
     * }
     */
    public static function quote(
        Client $client,
        Product $product,
        ?Batch $batch = null,
        int $qty = 1,
    ): array {
        // ⚠️ الصف الأول عشان ياخد السعر المسمّى، والكود بعده عشان
        // اللي بيقرا `list` من الناتج (شاشات وتقارير) مايتكسرش.
        $listRow = self::listRowFor($client);
        $list = $listRow?->code ?? self::listFor($client);
        $listPrice = self::listPrice($product, $listRow ?? $list);
        $discountPct = $client->effectiveDiscount();

        $unitPrice = round($listPrice * (1 - $discountPct), 2);
        $unitCost = self::costFor($product, $batch);

        $margin = round($unitPrice - $unitCost, 2);

        return [
            'list' => $list,
            'list_price' => $listPrice,
            'discount_pct' => $discountPct,
            'discount_source' => $client->discountSourceKey(),
            'unit_price' => $unitPrice,
            'unit_cost' => $unitCost,
            'margin' => $margin,
            'margin_pct' => $unitPrice > 0 ? round($margin / $unitPrice, 4) : 0.0,
            'qty' => $qty,
            'line_total' => round($unitPrice * $qty, 2),
            'line_cost' => round($unitCost * $qty, 2),
        ];
    }

    /**
     * سعر الوحدة للعميل بعد الخصم — الاستخدام السريع.
     *
     * ⚠️ **بتمرّ من `listRowFor` زي `quote` بالظبط** (إصلاح 2026-08-07).
     * كانت بتنادي `listFor` النصية اللي بتقرا عمود `price_list`
     * (`old`/`new`) بس، بينما الفاتورة بتتعمل من `quote` اللي بتقرا
     * `price_list_id`. النتيجة: عميل على قايمة مسمّاة (أو قايمته
     * القديمة على العمود بس) كان المندوب يشوف سعر في الشاشة
     * والفاتورة تطلع بسعر تاني — فرق مابيبانش غير في مراجعة آخر
     * الشهر. **الدالتين دلوقتي بيقروا من نفس المُحدِّد.**
     */
    public static function unitPrice(Client $client, Product $product): float
    {
        return round(self::listPriceFor($client, $product) * (1 - $client->effectiveDiscount()), 2);
    }

    /**
     * سعر القايمة للعميل **قبل الخصم** — الأساس اللي `unitPrice`
     * بتضرب فيه.
     *
     * ⚠️ **موجودة عشان الاتنين مايفترقوش.** أوامر التوريد بتخزّن
     * السعر قبل الخصم على البند عشان الورقة تطبع «سعر / خصم / سعر بعد
     * الخصم». أول نسخة كتبت الاشتقاق بإيدها (`listPrice($product,
     * listRowFor($client))`) وسابت الـfallback لـ`listFor()` اللي
     * في `unitPrice` — يعني عميل من غير صف قايمة على قايمة `old`
     * كان هياخد `price_new` كسعر «قبل الخصم» وسعر `old` بعد الخصم،
     * وتطلع نسبة خصم مالهاش وجود على مستند بيتمضى عليه.
     *
     * ⚠️ **مش هي `priceForClient`.** دي بترجّع `null` للصنف المش
     * متسعّر عشان البيع يترفض؛ ودي بترجّع الرقم الخام للعرض.
     */
    public static function listPriceFor(Client $client, Product $product): float
    {
        $row = self::listRowFor($client);

        return self::listPrice($product, $row ?? self::listFor($client));
    }

    /**
     * سعر بقائمة معيّنة من غير عميل — للمخزون وأوامر التوريد.
     * ⚠️ ده سعر قائمة، مش سعر عميل. مفيش خصم فيه.
     */
    public static function byList(Product $product, string $list): float
    {
        return self::listPrice($product, in_array($list, self::LISTS, true) ? $list : self::LIST_NEW);
    }

    /** هامش الربح على سعر القائمة — لشاشة المخزون */
    public static function marginPct(Product $product, string $list = self::LIST_NEW): float
    {
        $price = self::listPrice($product, $list);

        return $price > 0 ? round(($price - (float) $product->cost) / $price, 4) : 0.0;
    }

    /**
     * اسم القايمة للعرض.
     *
     * ⚠️ **القوايم المسمّاة بتظهر باسمها الحقيقي** (2026-08-07) —
     * قبل كده أي كود مش `old`/`new` كان بيتعرض «الجديد»، فالعميل
     * اللي على «قائمة الجملة» كانت الشاشة تقول عنه «الجديد».
     */
    public static function listLabel(string $list): string
    {
        if (in_array($list, self::LISTS, true)) {
            return __('stock.price_list_'.$list);
        }

        return PriceList::where('code', $list)->first()?->displayName()
            ?? __('stock.price_list_'.self::LIST_NEW);
    }
}
