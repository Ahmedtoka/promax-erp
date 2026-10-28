<?php

namespace App\Services\Reprice;

use App\Exceptions\Rejected;
use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\Invoice;
use App\Models\PriceChangeDoc;
use App\Models\PriceChangeRun;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Pricing;
use App\Support\PeriodGuard;
use App\Support\Scope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ═══════════════════════════════════════════════════════════════
 * محرك تعديل الأسعار (Lane 13، ٢٤/١٠/٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * أي تعديل سعر (بنود قايمة / قايمة سلسلة / قايمة فرع / قايمة عميل)
 * بيتحفظ **مسودة** (`price_change_runs` بحالة draft) ومابيغيّرش حاجة.
 *
 *   • `draft()` — بيتحقق من الطلب، بيسجّل اللي هيتغير (`item_changes`)،
 *     وبيعمل أول معاينة.
 *   • `refreshPreview()` — **نفس كود التطبيق بالحرف** جوه
 *     `DB::beginTransaction()` وبعدين `rollBack()` في `finally` — فأرقام
 *     المعاينة = أرقام التطبيق بالبناء مش بالصدفة.
 *   • `apply()` — ترانزاكشن واحدة للتعديل كله (القايمة + كل المستندات +
 *     كل `recalculate()` + صفوف السجل): يا كله يا ولا حاجة. بيقفل صف
 *     المسودة، وبيرفض لو الأرقام اختلفت عن المعاينة المتخزنة.
 *   • `applyMasterOnly()` — «احفظ للبيع الجديد بس» = سلوك النهاردة.
 *
 * ⚠️⚠️ **مفيش هنا ولا سطر بيحسب سعر.** كل فاتورة بتتسعّر بـ
 * `InvoiceRepricer` وكل أمر توريد بـ`PoRepricer` (نفس طريق الزراير
 * الفردية)، واللي بيوصلوا لـ`Pricing` / `Client::priceFor`.
 *
 * ⚠️ الفاتورة الكاش: الجماعي بيغيّر قيد البيع بس — قيد التحصيل
 * المربوط ماحدش يلمسه، فالفرق بيفضل على حساب العميل (مديونية أو
 * رصيد دائن). الزرار الفردي غير كده عن قصد (قرار أدمن على مستند واحد).
 *
 * ⚠️ الكميات والتكلفة ماتتلمسش أبداً — إعادة التسعير بتغيّر البيع بس.
 */
class PriceChange
{
    /** سقف المستندات في التعديل الواحد — أكتر من كده ضيّق بتاريخ «من» */
    public const MAX_DOCS = 5000;

    /** صفوف المستندات اللي بتتخزن في المعاينة (العدّادات كاملة) */
    public const PREVIEW_DOC_ROWS = 300;

    public const TRIGGERS = ['list', 'chain', 'branch', 'client'];

    private const EPS = 0.005;

    private const MONEY_KEYS = [
        'before_net', 'before_tax', 'before_grand',
        'after_net', 'after_tax', 'after_grand',
        'diff_net', 'diff_tax', 'diff_grand',
    ];

    // ═══════════════════════ المسودة ═══════════════════════

    /**
     * مسودة تعديل جديدة + أول معاينة.
     *
     * الطلب حسب المصدر:
     *   list   → ['price_list_id' => int, 'prices' => [product_id => float]]
     *   chain  → ['group_id' => int, 'price_list_id' => ?int]
     *   branch → ['client_id' => int, 'price_list_id' => ?int, 'scope' => 'branch'|'chain']
     *   client → ['client_id' => int, 'price_list_id' => ?int]
     * `price_list_id` = null معناها «طبّق التسعيرة الحالية على المستندات»
     * من غير أي تغيير في القايمة نفسها.
     */
    public static function draft(User $by, string $trigger, array $payload, ?string $since = null): PriceChangeRun
    {
        if (! in_array($trigger, self::TRIGGERS, true)) {
            throw new \InvalidArgumentException("Unknown price change trigger [{$trigger}]");
        }

        $cols = ['price_list_id' => null, 'group_id' => null, 'client_id' => null];

        switch ($trigger) {
            case 'list':
                $list = PriceList::findOrFail((int) ($payload['price_list_id'] ?? 0));
                [$prices, $itemChanges] = self::listRows($list, (array) ($payload['prices'] ?? []));

                if ($prices === []) {
                    throw new Rejected(__('reprice.nothing_changed'));
                }

                $payload = ['price_list_id' => $list->id, 'prices' => $prices];
                $cols['price_list_id'] = $list->id;
                $scope = 'list';
                break;

            case 'chain':
                $group = ClientGroup::findOrFail((int) ($payload['group_id'] ?? 0));
                $listId = self::listIdOrNull($payload['price_list_id'] ?? null);
                $payload = ['group_id' => $group->id, 'price_list_id' => $listId];
                $cols['group_id'] = $group->id;
                $cols['price_list_id'] = $listId;
                $scope = 'chain';
                break;

            default: // branch | client
                $client = Client::findOrFail((int) ($payload['client_id'] ?? 0));
                Scope::assertClient($by, $client);
                $listId = self::listIdOrNull($payload['price_list_id'] ?? null);

                $scope = $trigger === 'client' ? 'client' : self::branchScope($client, $payload['scope'] ?? 'branch');
                $payload = ['client_id' => $client->id, 'price_list_id' => $listId];
                if ($trigger === 'branch') {
                    $payload['scope'] = $scope;
                }

                $cols['client_id'] = $client->id;
                $cols['group_id'] = $client->group_id;
                $cols['price_list_id'] = $listId;
                break;
        }

        $run = PriceChangeRun::create($cols + [
            'user_id' => $by->id,
            'trigger' => $trigger,
            'status' => 'draft',
            'scope' => $scope,
            'since' => $since ? Carbon::parse($since)->toDateString() : null,
            'payload' => $payload,
            'item_changes' => $trigger === 'list' ? $itemChanges : [],
        ]);

        if ($trigger !== 'list') {
            $run->update(['item_changes' => self::assignmentChanges($run, $by)]);
        }

        // ⚠️ أول معاينة وقعت (كتير / الشجرة رفضت / ...) → المسودة تتقفل
        // بسببها بدل ما تفضل يتيمة في السجل من غير أرقام
        try {
            self::refreshPreview($run);
        } catch (\Throwable $e) {
            PriceChangeRun::whereKey($run->id)->where('status', 'draft')->update([
                'status' => 'cancelled',
                'error' => Str::limit($e->getMessage(), 245),
            ] + self::zeroColumns());

            throw $e;
        }

        return $run->fresh();
    }

    /**
     * تغيير نطاق المسودة (فرع بس ↔ كل السلسلة — لمصدر الفرع بس)
     * و/أو تاريخ «من»، وبعدين معاينة جديدة.
     */
    public static function retarget(PriceChangeRun $run, ?string $scope, ?string $since): array
    {
        // القفل والفحص بعده — تأكيد في تاب تانية مايتقابلش مع تعديل النطاق
        DB::transaction(function () use ($run, $scope, $since) {
            $locked = self::lockDraft($run);

            $attrs = ['since' => $since ? Carbon::parse($since)->toDateString() : null];

            if ($locked->trigger === 'branch' && $scope !== null) {
                $client = Client::findOrFail((int) $locked->payload['client_id']);
                $scope = self::branchScope($client, $scope);
                $attrs['scope'] = $scope;
                $attrs['payload'] = array_merge($locked->payload, ['scope' => $scope]);
            }

            $locked->update($attrs);

            if ($locked->trigger === 'branch') {
                $locked->update(['item_changes' => self::assignmentChanges($locked, $locked->user)]);
            }
        });

        return self::refreshPreview($run->refresh());
    }

    // ═══════════════════════ المعاينة ═══════════════════════

    /**
     * المعاينة: التطبيق الحقيقي جوه ترانزاكشن بترجع دايماً.
     *
     * ⚠️ `rollBack()` في `finally` — أي استثناء (حتى `too_many`) بيرجّع
     * كل اللي اتكتب. وبعده `PriceList::flushCache()` لأن الميمو الستاتيك
     * ممكن يكون شايل صف من جوه الترانزاكشن اللي رجعت.
     */
    public static function refreshPreview(PriceChangeRun $run): array
    {
        if (! $run->isDraft()) {
            throw new Rejected(__('reprice.not_draft'));
        }

        self::longRun();
        self::assertCreator($run);

        DB::beginTransaction();
        try {
            $result = self::simulate($run, $run->user);
        } finally {
            DB::rollBack();
            self::flushCaches();
        }

        // ⚠️ التخزين بعد القفل وبشرط إنها لسه draft — تأكيد خلص في
        // النص مايتدهسش بأرقام معاينة قديمة
        DB::transaction(function () use ($run, $result) {
            self::lockDraft($run);

            // ⚠️ مابنفحصش عدد الصفوف المتأثرة: MySQL بيرجّع صفر لو القيم
            // هي هي (معاينة متكررة في نفس الثانية) — القفل فوق هو الضمان
            PriceChangeRun::whereKey($run->id)->where('status', 'draft')->update([
                'preview' => json_encode(self::stored($result), JSON_UNESCAPED_UNICODE),
                'error' => null,
                'updated_at' => now(),
            ] + self::columns($result));
        });

        $run->refresh();

        return $result;
    }

    // ═══════════════════════ التطبيق ═══════════════════════

    /**
     * التأكيد — ترانزاكشن واحدة: يا كله يا ولا حاجة.
     *
     * ⚠️ صف المسودة بيتقفل (`lockForUpdate`) والحالة بتتفحص **بعد**
     * القفل — الدبل كليك التاني بيستنى الأول يخلّص وبعدين يلاقيها
     * applied فيترفض (`not_draft`).
     * ⚠️ لو الأرقام اختلفت عن المعاينة المتخزنة (داتا اتغيرت في النص)
     * → `preview_stale` ومفيش ولا كتابة.
     */
    public static function apply(PriceChangeRun $run, User $by): array
    {
        self::longRun();

        try {
            return DB::transaction(function () use ($run, $by) {
                $locked = self::lockDraft($run);

                self::assertCreator($locked);
                $result = self::simulate($locked, $locked->user);

                if (! self::matchesPreview($locked->preview, $result)) {
                    throw new Rejected(__('reprice.preview_stale'));
                }

                self::writeDocs($locked, $result['docs']);
                self::markDocuments($locked, $by, $result['docs']);

                $locked->update([
                    'status' => 'applied',
                    'applied_at' => now(),
                    'summary' => self::stored($result, 0),
                    'error' => null,
                ] + self::columns($result));

                return $result;
            });
        } catch (\Throwable $e) {
            // الترانزاكشن رجعت — بنسجّل السبب على المسودة بس (بره الترانزاكشن)،
            // وبشرط إنها لسه draft (الرفض «مش مسودة» مالوش دعوة بالصف)
            PriceChangeRun::whereKey($run->id)->where('status', 'draft')
                ->update(['error' => Str::limit($e->getMessage(), 245)]);

            throw $e;
        } finally {
            self::flushCaches();
        }
    }

    /** «احفظ الأسعار للبيع الجديد بس» — القايمة تتغير والمستندات زي ما هي */
    public static function applyMasterOnly(PriceChangeRun $run, User $by): void
    {
        try {
            DB::transaction(function () use ($run, $by) {
                $locked = self::lockDraft($run);

                self::assertCreator($locked);
                self::applyMaster($locked, $locked->user);

                // ⚠️ مفيش ولا مستند اتلمس — أرقام المعاينة بتتصفّر من الأعمدة
                // (السجل بيجمع الأعمدة)، والـjson فاضل للتاريخ
                $locked->update(['status' => 'master_only', 'applied_at' => now()] + self::zeroColumns());
            });
        } finally {
            self::flushCaches();
        }
    }

    public static function cancel(PriceChangeRun $run): void
    {
        DB::transaction(function () use ($run) {
            self::lockDraft($run)->update(['status' => 'cancelled'] + self::zeroColumns());
        });
    }

    /**
     * تسجيل زرار «إعادة تسعير» الفردي في السجل — تعديل مطبّق بمستند واحد.
     * المنادي يناديها **بعد** `InvoiceRepricer::apply` بنفس الخطة.
     *
     * أثر الحساب: لو للفاتورة قيد تحصيل مربوط (كاش) الزرار الفردي
     * بيحرّكه معاها فالأثر صفر، وإلا = فرق الشامل.
     */
    public static function recordSingle(Invoice $inv, User $by, array $plan): PriceChangeRun
    {
        return DB::transaction(function () use ($inv, $by, $plan) {
            $before = $plan['before'];
            $after = $plan['after'];
            $diff = round($after['grand'] - $before['grand'], 2);

            $hasCollection = Transaction::where('source_type', Invoice::class)
                ->where('source_id', $inv->id)
                ->where('kind', 'collection')->exists();

            $totals = self::emptyTotals();
            self::addTo($totals['invoice'], $before, $after);
            self::addTo($totals['all'], $before, $after);

            $doc = self::docRow('invoice', $inv, 'changed', null, null, $before, $after, $plan['lines']);

            $result = [
                'clients' => 1, 'invoices_changed' => 1, 'pos_changed' => 0,
                'unchanged' => 0, 'skipped' => 0,
                'totals' => self::rounded($totals),
                'ledger_diff' => $hasCollection ? 0.0 : $diff,
                'docs' => [$doc],
                'item_changes' => array_map(fn ($l) => [
                    'product_id' => $l['product_id'], 'name' => $l['name'],
                    'old' => (float) $l['old_price'], 'new' => (float) $l['price'],
                ], $plan['lines']),
            ];

            $run = PriceChangeRun::create([
                'user_id' => $by->id,
                'trigger' => 'invoice',
                'status' => 'applied',
                'scope' => 'invoice',
                'invoice_id' => $inv->id,
                'client_id' => $inv->client_id,
                'payload' => ['invoice_id' => $inv->id],
                'item_changes' => $result['item_changes'],
                'summary' => self::stored($result, 0),
                'applied_at' => now(),
            ] + self::columns($result));

            self::writeDocs($run, [$doc]);
            self::markDocuments($run, $by, [$doc]);

            return $run;
        });
    }

    // ═══════════════════════ مساعدات للشاشات ═══════════════════════

    /**
     * قايمة أسعار السلسلة: قايمة عقد السلسلة السارٍ لو مفعّلة، وإلا
     * القايمة الأكتر تكراراً بين فروعها، وإلا مفيش.
     *
     * @return array{list: ?PriceList, source: string, count: int, total: int}
     */
    public static function chainListOf(ClientGroup $g): array
    {
        $total = $g->clients()->count();

        $ct = $g->contract;
        if ($ct !== null && $ct->active && ! $ct->isExpired()) {
            $row = $ct->priceListRow;
            if ($row !== null && $row->active) {
                return [
                    'list' => $row,
                    'source' => 'contract',
                    'count' => $g->clients()->where('price_list_id', $row->id)->count(),
                    'total' => $total,
                ];
            }
        }

        $top = $g->clients()->whereNotNull('price_list_id')
            ->select('price_list_id', DB::raw('COUNT(*) as n'))
            ->groupBy('price_list_id')
            ->orderByDesc('n')->orderBy('price_list_id')
            ->first();

        $list = $top ? PriceList::find($top->price_list_id) : null;

        if ($list === null) {
            return ['list' => null, 'source' => 'none', 'count' => 0, 'total' => $total];
        }

        return ['list' => $list, 'source' => 'branches', 'count' => (int) $top->n, 'total' => $total];
    }

    /**
     * كتابة أسعار بنود قايمة — جسم حلقة `PriceListController::savePrices`
     * منقول بالحرف (+ الكتابة العكسية لعمودي الصنف للقايمتين old/new).
     * بترجّع عدد البنود اللي اتغيرت فعلاً.
     *
     * @param  array<int|string, mixed>  $prices  product_id => سعر
     */
    public static function writeListPrices(PriceList $list, array $prices): int
    {
        $touched = 0;

        DB::transaction(function () use ($prices, $list, &$touched) {
            foreach ($prices as $productId => $price) {
                if ($price === null || $price === '') {
                    continue;
                }

                $price = round((float) $price, 2);

                $item = PriceListItem::firstOrNew([
                    'price_list_id' => $list->id,
                    'product_id' => (int) $productId,
                ]);

                if ($item->exists && abs((float) $item->price - $price) < 0.005) {
                    continue;
                }

                $item->price = $price;
                $item->save();
                $touched++;

                // ⚠️ **القايمتين المهاجرتين ليهم عمودين على الصنف**
                // لسه بيقروا منهم الـKPIs والأبلكيشن وأوامر التوريد.
                // من غير الكتابة العكسية دي، المندوب بيشوف سعر
                // والفاتورة بتطلع بسعر تاني.
                if (in_array($list->code, Pricing::LISTS, true)) {
                    Product::whereKey((int) $productId)
                        ->update(['price_'.$list->code => $price]);
                }
            }
        });

        return $touched;
    }

    // ═══════════════════════ القلب ═══════════════════════

    /**
     * التعديل نفسه — كتابات حقيقية. المنادي هو اللي بيقرر: يرجّعها
     * (معاينة) ولا يثبّتها (تطبيق). **نفس الكود في الحالتين.**
     */
    private static function simulate(PriceChangeRun $run, ?User $scopeUser): array
    {
        self::longRun();
        PeriodGuard::flush();

        // ١. تغيير القايمة نفسها
        self::applyMaster($run, $scopeUser);

        // ٢. العملاء في النطاق (بعد التغيير — استعلامات جديدة)
        $clientIds = self::scopeClientIds($run, $scopeUser);

        $result = [
            'clients' => count($clientIds),
            'invoices_changed' => 0,
            'pos_changed' => 0,
            'unchanged' => 0,
            'skipped' => 0,
            'totals' => self::emptyTotals(),
            'ledger_diff' => 0.0,
            'docs' => [],
            'item_changes' => $run->item_changes ?? [],
            // القايمة بيانات أساسية على مستوى الشركة — نطاقها كل العملاء
            'company_wide' => $run->trigger === 'list',
        ];

        if ($clientIds === []) {
            $result['totals'] = self::rounded($result['totals']);

            return $result;
        }

        // ٣. المستندات — العدّ الأول، وفوق السقف = رفض
        $since = $run->since ? Carbon::parse($run->since)->startOfDay() : null;

        // ⚠️ مصدر القايمة (قرار المنسّق ٢٤/١٠): التعديل بيلمس بس اللي
        // التعديل لمسه — المستند لازم يكون فيه صنف من الأصناف اللي سعرها
        // اتغير. السلسلة/الفرع/العميل وإعادة المزامنة = إعادة تسعير كاملة.
        $only = self::changedProducts($run);
        $withItems = fn ($q) => $only === null ? $q
            : $q->whereHas('items', fn ($i) => $i->whereIn('product_id', $only));

        $invoiceQ = fn () => $withItems(Invoice::whereIn('client_id', $clientIds)
            ->when($since, fn ($q) => $q->where('created_at', '>=', $since)));
        $poQ = fn () => $withItems(PurchaseOrder::whereIn('client_id', $clientIds)
            ->when($since, fn ($q) => $q->where('created_at', '>=', $since)));

        $n = $invoiceQ()->count() + $poQ()->count();
        if ($n > self::MAX_DOCS) {
            throw new Rejected(__('reprice.too_many', ['n' => $n]));
        }

        $touched = [];

        // ٤. الفواتير
        $invoiceQ()->chunkById(200, function ($invoices) use (&$result, &$touched, $only) {
            foreach ($invoices as $inv) {
                self::invoiceStep($inv, $result, $touched, $only);
            }
        });

        // ٥. أوامر التوريد
        $poQ()->with('client')->chunkById(200, function ($pos) use (&$result, $only) {
            foreach ($pos as $po) {
                self::poStep($po, $result, $only);
            }
        });

        // ٦. رصيد كل عميل اتلمست فاتورته — مرة واحدة جوه نفس الترانزاكشن
        foreach (Client::whereIn('id', array_keys($touched))->get() as $client) {
            $client->recalculate();
        }

        $result['totals'] = self::rounded($result['totals']);
        $result['ledger_diff'] = $result['totals']['invoice']['diff_grand'];

        return $result;
    }

    /** @param  list<int>|null  $only  أصناف تعديل القايمة (null = كل حاجة) */
    private static function invoiceStep(Invoice $inv, array &$result, array &$touched, ?array $only = null): void
    {
        $current = ['net' => (float) $inv->total, 'tax' => (float) $inv->tax_total, 'grand' => (float) $inv->grand_total];

        if ($block = InvoiceRepricer::blocker($inv)) {
            self::skip($result, 'invoice', $inv, $block['key'], $block['detail'], $current);

            return;
        }

        if (PeriodGuard::isClosed($inv->created_at)) {
            self::skip($result, 'invoice', $inv, 'closed_period', PeriodGuard::label($inv->created_at), $current);

            return;
        }

        $plan = InvoiceRepricer::plan($inv);

        if ($plan['not_priced'] !== null) {
            self::skip($result, 'invoice', $inv, 'not_priced', $plan['not_priced'], $current);

            return;
        }

        if (! $plan['changed']) {
            $result['unchanged']++;

            return;
        }

        if ($only !== null && self::otherTermsChanged($plan, $inv->items, $only, (float) $inv->discount_pct)) {
            self::skip($result, 'invoice', $inv, 'other_terms_changed', null, $current);

            return;
        }

        // ⚠️ التحصيل ماحدش يلمسه، و`recalculate` مرة واحدة في الآخر
        // والشجرة صارمة: فشل إعادة الترحيل = التعديل كله يرجع
        InvoiceRepricer::apply($inv, $plan, false, false, true);

        $fresh = $inv->fresh();
        $after = ['net' => (float) $fresh->total, 'tax' => (float) $fresh->tax_total, 'grand' => (float) $fresh->grand_total];

        $result['invoices_changed']++;
        $result['docs'][] = self::docRow('invoice', $inv, 'changed', null, null, $plan['before'], $after, $plan['lines']);
        self::addTo($result['totals']['invoice'], $plan['before'], $after);
        self::addTo($result['totals']['all'], $plan['before'], $after);
        $touched[(int) $inv->client_id] = true;
    }

    /** @param  list<int>|null  $only  أصناف تعديل القايمة (null = كل حاجة) */
    private static function poStep(PurchaseOrder $po, array &$result, ?array $only = null): void
    {
        $current = ['net' => (float) $po->total, 'tax' => (float) $po->tax_total, 'grand' => (float) $po->grand_total];

        if ($block = PoRepricer::blocker($po)) {
            self::skip($result, 'po', $po, $block['key'], $block['detail'], $current);

            return;
        }

        $plan = PoRepricer::plan($po);

        if ($plan['not_priced'] !== null) {
            self::skip($result, 'po', $po, 'not_priced', $plan['not_priced'], $current);

            return;
        }

        if (! $plan['changed']) {
            $result['unchanged']++;

            return;
        }

        if ($only !== null && self::otherTermsChanged($plan, $po->items, $only, null)) {
            self::skip($result, 'po', $po, 'other_terms_changed', null, $current);

            return;
        }

        // ترانزاكشن متداخلة للأمر ده بس — الرفض (صنف درافت مثلاً)
        // بيرجّع كتاباته هو من غير ما يوقع التعديل كله
        $error = null;
        try {
            DB::transaction(function () use ($po, &$error) {
                $error = PoRepricer::apply($po);
                if ($error !== null) {
                    throw new Rejected($error);
                }
            });
        } catch (Rejected $e) {
            $error = $e->getMessage();
        }

        if ($error !== null) {
            self::skip($result, 'po', $po, 'not_priced', $error, $current);

            return;
        }

        $fresh = $po->fresh();
        $after = ['net' => (float) $fresh->total, 'tax' => (float) $fresh->tax_total, 'grand' => (float) $fresh->grand_total];

        $result['pos_changed']++;
        $result['docs'][] = self::docRow('po', $po, 'changed', null, null, $plan['before'], $after, $plan['lines']);
        self::addTo($result['totals']['po'], $plan['before'], $after);
        self::addTo($result['totals']['all'], $plan['before'], $after);
    }

    /** أصناف تعديل القايمة — أو null لباقي المصادر (إعادة تسعير كاملة) */
    private static function changedProducts(PriceChangeRun $run): ?array
    {
        if ($run->trigger !== 'list') {
            return null;
        }

        return array_map('intval', array_keys((array) ($run->payload['prices'] ?? [])));
    }

    /**
     * الخطة بتغيّر حاجة **غير** أسعار الأصناف اللي القايمة غيّرتها؟
     * (خصم الفاتورة/السطر، نسبة ضريبة أي سطر، أو سعر صنف تاني)
     *
     * ⚠️ لو آه، مصدر القايمة مايلمسش المستند: إعادة تسعيره كانت هتغيّر
     * شروط تانية في صمت باسم «تعديل سعر صنف». ده شغل «طبّق التسعيرة
     * الحالية» من صفحة العميل/السلسلة، بمعاينته هو.
     *
     * @param  iterable<\Illuminate\Database\Eloquent\Model>  $items  بنود المستند (فيها tax_rate الحالي)
     * @param  list<int>  $only
     */
    private static function otherTermsChanged(array $plan, iterable $items, array $only, ?float $docDiscountPct): bool
    {
        $byId = [];
        foreach ($items as $item) {
            $byId[(int) $item->id] = $item;
        }

        // خصم الفاتورة (العمود decimal:4 — نفس دقة المقارنة في InvoiceRepricer)
        if ($docDiscountPct !== null
            && abs(round($docDiscountPct, 4) - round((float) ($plan['after']['discount_pct'] ?? 0), 4)) >= 0.00005) {
            return true;
        }

        foreach ($plan['lines'] as $l) {
            $item = $byId[(int) $l['item_id']] ?? null;

            if ($item !== null && abs((float) $item->tax_rate - (float) $l['tax_rate']) >= 0.00005) {
                return true;
            }

            // خصم سطر أمر التوريد
            if (isset($l['old_discount_pct'], $l['discount_pct'])
                && abs(round((float) $l['old_discount_pct'], 4) - round((float) $l['discount_pct'], 4)) >= 0.00005) {
                return true;
            }

            if (! in_array((int) $l['product_id'], $only, true)
                && (abs($l['old_list'] - $l['list_price']) >= self::EPS
                    || abs($l['old_price'] - $l['price']) >= self::EPS
                    || abs($l['old_total'] - $l['total']) >= self::EPS
                    || abs($l['old_tax'] - $l['tax']) >= self::EPS)) {
                return true;
            }
        }

        return false;
    }

    /** تغيير القايمة (أسعار بنود، أو قايمة عميل/فروع) — من غير أي مستند */
    private static function applyMaster(PriceChangeRun $run, ?User $scopeUser): void
    {
        $p = $run->payload;

        if ($run->trigger === 'list') {
            self::writeListPrices(PriceList::findOrFail((int) $p['price_list_id']), (array) $p['prices']);
            PriceList::flushCache();

            return;
        }

        if (empty($p['price_list_id'])) {
            return;   // إعادة مزامنة المستندات بس
        }

        $list = PriceList::findOrFail((int) $p['price_list_id']);

        self::assignmentQuery($run, $scopeUser)->update([
            'price_list_id' => $list->id,
            'price_list' => self::codeColumn($list),
        ]);

        PriceList::flushCache();
    }

    /** العملاء اللي قايمتهم بتتغير — سلسلة/فرع/عميل، عبر `visibleTo` */
    private static function assignmentQuery(PriceChangeRun $run, ?User $scopeUser)
    {
        $p = $run->payload;

        if ($run->trigger === 'chain') {
            return Client::visibleTo(Client::where('group_id', (int) $p['group_id']), $scopeUser);
        }

        if ($run->trigger === 'branch' && $run->scope === 'chain') {
            $groupId = Client::whereKey((int) $p['client_id'])->value('group_id');

            return Client::visibleTo(Client::where('group_id', (int) $groupId), $scopeUser);
        }

        return Client::visibleTo(Client::whereKey((int) $p['client_id']), $scopeUser);
    }

    /** @return list<int> */
    private static function scopeClientIds(PriceChangeRun $run, ?User $scopeUser): array
    {
        if ($run->trigger !== 'list') {
            return self::assignmentQuery($run, $scopeUser)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        // كل عميل قايمته الفعلية (`listRowFor`) هي القايمة دي.
        // ⚠️ **من غير `visibleTo` عن قصد** (قرار المنسّق ٢٤/١٠): القايمة
        // بيانات أساسية للشركة كلها — سعرها اتغير لكل عملائها مش لفريق
        // واحد، والتطبيق بأثر رجعي صلاحية أدمن بس. السلسلة/الفرع/العميل
        // فاضلين على سكوب منشئ المسودة.
        $listId = (int) $run->payload['price_list_id'];
        $ids = [];

        Client::query()
            ->with(['contract.priceListRow', 'group.contract.priceListRow', 'priceListRow'])
            ->chunkById(200, function ($clients) use ($listId, &$ids) {
                foreach ($clients as $c) {
                    if (Pricing::listRowFor($c)?->id === $listId) {
                        $ids[] = (int) $c->id;
                    }
                }
            });

        return $ids;
    }

    /**
     * سطور «القايمة اتغيرت من كذا لكذا» لكل عميل في النطاق قايمته
     * المختارة مختلفة عن الجديدة.
     */
    private static function assignmentChanges(PriceChangeRun $run, ?User $scopeUser): array
    {
        $newId = $run->payload['price_list_id'] ?? null;
        if (empty($newId)) {
            return [];
        }

        $new = PriceList::find((int) $newId);
        $names = PriceList::all()->mapWithKeys(fn ($l) => [$l->id => $l->displayName()]);

        return self::assignmentQuery($run, $scopeUser)
            ->where(fn ($q) => $q->whereNull('price_list_id')->orWhere('price_list_id', '!=', (int) $newId))
            ->orderBy('id')->get()
            ->map(fn (Client $c) => [
                'client_id' => (int) $c->id,
                'name' => $c->displayName(),
                'old_list_id' => $c->price_list_id !== null ? (int) $c->price_list_id : null,
                'old_list_name' => $c->price_list_id !== null ? ($names[$c->price_list_id] ?? null) : null,
                'new_list_id' => (int) $newId,
                'new_list_name' => $new?->displayName(),
            ])->all();
    }

    /**
     * صفوف القايمة اللي فعلاً مختلفة — الصنف لازم يكون منتج تام حقيقي
     * (نفس فلتر `savePrices`)، والفاضي بيتشال.
     *
     * @return array{0: array<int, float>, 1: list<array<string, mixed>>}
     */
    private static function listRows(PriceList $list, array $input): array
    {
        $products = Product::whereIn('id', array_map('intval', array_keys($input)))
            ->where('kind', Product::KIND_FINISHED)
            ->get()->keyBy('id');

        $current = PriceListItem::where('price_list_id', $list->id)
            ->whereIn('product_id', $products->keys())
            ->pluck('price', 'product_id');

        $prices = [];
        $changes = [];

        foreach ($input as $productId => $price) {
            $productId = (int) $productId;
            if ($price === null || $price === '' || ! isset($products[$productId])) {
                continue;
            }

            $price = round((float) $price, 2);
            $old = isset($current[$productId]) ? (float) $current[$productId] : null;

            if ($old !== null && abs($old - $price) < self::EPS) {
                continue;
            }

            $prices[$productId] = $price;
            $changes[] = [
                'product_id' => $productId,
                'name' => $products[$productId]->displayName(),
                'old' => $old ?? 0.0,
                'new' => $price,
            ];
        }

        return [$prices, $changes];
    }

    /**
     * العمود النصي `clients.price_list` enum — نفس قاعدة
     * `ErpController::updateClient`: old/new بكودها، والمسمّاة `new`.
     */
    private static function codeColumn(PriceList $list): string
    {
        return in_array($list->code, Pricing::LISTS, true) ? $list->code : Pricing::LIST_NEW;
    }

    private static function listIdOrNull(mixed $id): ?int
    {
        if ($id === null || $id === '') {
            return null;
        }

        return PriceList::findOrFail((int) $id)->id;
    }

    /** نطاق الفرع: «كل السلسلة» بس لو الفرع فعلاً في سلسلة */
    private static function branchScope(Client $client, ?string $scope): string
    {
        return $scope === 'chain' && $client->group_id !== null ? 'chain' : 'branch';
    }

    // ═══════════════════════ السجل ═══════════════════════

    private static function skip(array &$result, string $type, Invoice|PurchaseOrder $doc, string $reason, ?string $detail, array $current): void
    {
        $result['skipped']++;
        $result['docs'][] = self::docRow($type, $doc, 'skipped', $reason, $detail, $current, null, null);
    }

    private static function docRow(string $type, Invoice|PurchaseOrder $doc, string $outcome, ?string $reason, ?string $detail, array $before, ?array $after, ?array $lines): array
    {
        return [
            'doc_type' => $type,
            'doc_id' => (int) $doc->id,
            'number' => (string) $doc->number,
            'client_id' => $doc->client_id !== null ? (int) $doc->client_id : null,
            'doc_date' => $doc->created_at?->toDateString(),
            'payment' => $type === 'invoice' ? $doc->payment : null,
            'outcome' => $outcome,
            'reason' => $reason,
            'reason_detail' => $detail !== null ? Str::limit($detail, 185) : null,
            'before_net' => round($before['net'], 2),
            'before_tax' => round($before['tax'], 2),
            'before_grand' => round($before['grand'], 2),
            'after_net' => $after ? round($after['net'], 2) : null,
            'after_tax' => $after ? round($after['tax'], 2) : null,
            'after_grand' => $after ? round($after['grand'], 2) : null,
            'lines' => $lines,
        ];
    }

    private static function writeDocs(PriceChangeRun $run, array $docs): void
    {
        $now = now();

        foreach (array_chunk($docs, 200) as $chunk) {
            PriceChangeDoc::insert(array_map(fn ($d) => array_merge($d, [
                'run_id' => $run->id,
                'lines' => $d['lines'] !== null ? json_encode($d['lines'], JSON_UNESCAPED_UNICODE) : null,
                'created_at' => $now,
            ]), $chunk));
        }
    }

    /** علامة «اتسعّرت في التعديل ده» على الفواتير والأوامر اللي اتغيرت بس */
    private static function markDocuments(PriceChangeRun $run, User $by, array $docs): void
    {
        $changed = array_filter($docs, fn ($d) => $d['outcome'] === 'changed');

        $invoiceIds = array_column(array_filter($changed, fn ($d) => $d['doc_type'] === 'invoice'), 'doc_id');
        $poIds = array_column(array_filter($changed, fn ($d) => $d['doc_type'] === 'po'), 'doc_id');

        foreach (array_chunk($invoiceIds, 1000) as $ids) {
            Invoice::whereIn('id', $ids)->update([
                'repriced_at' => now(),
                'repriced_by' => $by->id,
                'price_change_run_id' => $run->id,
            ]);
        }

        foreach (array_chunk($poIds, 1000) as $ids) {
            PurchaseOrder::whereIn('id', $ids)->update([
                'price_change_run_id' => $run->id,
                'edited_by' => $by->id,
            ]);
        }
    }

    // ═══════════════════════ الأرقام ═══════════════════════

    private static function emptyTotals(): array
    {
        $t = array_fill_keys(self::MONEY_KEYS, 0.0);

        return ['invoice' => $t, 'po' => $t, 'all' => $t];
    }

    private static function addTo(array &$t, array $before, array $after): void
    {
        foreach (['net', 'tax', 'grand'] as $k) {
            $t['before_'.$k] += (float) $before[$k];
            $t['after_'.$k] += (float) $after[$k];
            $t['diff_'.$k] += (float) $after[$k] - (float) $before[$k];
        }
    }

    private static function rounded(array $totals): array
    {
        foreach ($totals as $group => $t) {
            foreach ($t as $k => $v) {
                $totals[$group][$k] = round($v, 2);
            }
        }

        return $totals;
    }

    /** أعمدة الأرقام على صف التعديل */
    private static function columns(array $r): array
    {
        $all = $r['totals']['all'];

        return [
            'clients_count' => $r['clients'],
            'invoices_changed' => $r['invoices_changed'],
            'pos_changed' => $r['pos_changed'],
            'skipped_count' => $r['skipped'],
            'unchanged_count' => $r['unchanged'],
            'before_net' => $all['before_net'],
            'before_tax' => $all['before_tax'],
            'before_grand' => $all['before_grand'],
            'after_net' => $all['after_net'],
            'after_tax' => $all['after_tax'],
            'after_grand' => $all['after_grand'],
            'ledger_diff' => $r['ledger_diff'],
        ];
    }

    /** النتيجة للتخزين — صفوف المستندات مقصوصة، والعدّادات كاملة */
    private static function stored(array $r, int $docRows = self::PREVIEW_DOC_ROWS): array
    {
        $r['docs_total'] = count($r['docs']);
        $r['docs'] = array_slice($r['docs'], 0, $docRows);

        return $r;
    }

    /** نفس العدّادات ونفس الإجماليات (فرق أقل من نص قرش) */
    private static function matchesPreview(?array $preview, array $result): bool
    {
        if ($preview === null) {
            return false;
        }

        foreach (['clients', 'invoices_changed', 'pos_changed', 'unchanged', 'skipped'] as $k) {
            if ((int) ($preview[$k] ?? -1) !== (int) $result[$k]) {
                return false;
            }
        }

        foreach (['invoice', 'po', 'all'] as $group) {
            foreach (self::MONEY_KEYS as $k) {
                if (abs((float) ($preview['totals'][$group][$k] ?? INF) - (float) $result['totals'][$group][$k]) >= self::EPS) {
                    return false;
                }
            }
        }

        return abs((float) ($preview['ledger_diff'] ?? INF) - (float) $result['ledger_diff']) < self::EPS;
    }

    /**
     * نطاق السلسلة/الفرع/العميل محسوب بسكوب صاحب المسودة — لو اتمسح،
     * مانقعش على سكوب اللي فاتح الصفحة (أرقام مختلفة عن المعاينة).
     * القايمة على مستوى الشركة فمالهاش دعوة.
     */
    private static function assertCreator(PriceChangeRun $run): void
    {
        if ($run->trigger !== 'list' && ($run->user_id === null || $run->user === null)) {
            throw new Rejected(__('reprice.creator_missing'));
        }
    }

    /** أعمدة الأرقام متصفّرة — للمسودة اللي اتلغت أو اتحفظت للبيع الجديد بس */
    private static function zeroColumns(): array
    {
        return [
            'clients_count' => 0, 'invoices_changed' => 0, 'pos_changed' => 0,
            'skipped_count' => 0, 'unchanged_count' => 0,
            'before_net' => 0, 'before_tax' => 0, 'before_grand' => 0,
            'after_net' => 0, 'after_tax' => 0, 'after_grand' => 0,
            'ledger_diff' => 0,
        ];
    }

    /** قفل صف المسودة (جوه ترانزاكشن المنادي) — ولو مابقتش draft = رفض */
    private static function lockDraft(PriceChangeRun $run): PriceChangeRun
    {
        $locked = PriceChangeRun::whereKey($run->id)->lockForUpdate()->first();

        if ($locked === null || ! $locked->isDraft()) {
            throw new Rejected(__('reprice.not_draft'));
        }

        return $locked;
    }

    private static function flushCaches(): void
    {
        PriceList::flushCache();
        PeriodGuard::flush();
    }

    private static function longRun(): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }
    }
}
