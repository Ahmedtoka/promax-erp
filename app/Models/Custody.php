<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Custody extends Model
{
    use HasFactory;

    protected $table = 'custodies';

    protected $fillable = ['user_id', 'warehouse_id', 'vehicle_id', 'date', 'status', 'closed_at'];

    protected function casts(): array
    {
        return ['date' => 'date', 'closed_at' => 'datetime'];
    }

    public function vehicle(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CustodyItem::class);
    }

    public function pickOrders(): HasMany
    {
        return $this->hasMany(PickOrder::class);
    }

    /** الكمية المتاحة من صنف معيّن في العهدة — للفحص قبل الموافقة على PO */
    public function availableFor(int $productId): int
    {
        return (int) $this->items
            ->where('product_id', $productId)
            ->filter(fn (CustodyItem $i) => $i->batch === null || ! $i->batch->isExpired())
            ->sum(fn (CustodyItem $i) => $i->remaining());
    }

    /**
     * ═══════════════════════════════════════════════════════════
     * البضاعة **المحجوزة** لأوامر توريد مفتوحة  ·  ١٥ أغسطس ٢٠٢٦
     * ═══════════════════════════════════════════════════════════
     *
     * بلاغ المالك: «الرصيد بايظ بتاعه، ومش لاقيه في تجهيز الطلبات
     * ولا العهد».
     *
     * ⚠️ **ده كان أخطر ثغرة في فلو الريفيل.** `availableFor()`
     * بترجّع `assigned - sold - returned - transferred_out` — يعني
     * البضاعة اللي **اتوعد بيها** أمر توريد معلّق لسه محسوبة متاحة،
     * لأن الخصم مابيحصلش غير لحظة التسليم.
     *
     * السيناريو الحقيقي اللي حصل:
     *   المندوب معاه ١٠٠ قطعة.
     *   طلب ريفيل (أ) بـ٨٠ → `canCover` تشوف ١٠٠ → «العربية كفاية»
     *      → **مافيش أمر تجهيز اتعمل خالص**.
     *   طلب ريفيل (ب) بـ٨٠ → لسه مافيش بيع، فـ`canCover` تشوف
     *      ١٠٠ تاني → «العربية كفاية» → ولا أمر تجهيز.
     *   بقى عليه ١٦٠ قطعة يسلّمها من ١٠٠ — الرصيد بايظ، ومحدش
     *   لقى ورقة تجهيز في المخزن لأن مااتعملتش أصلاً.
     *
     * الدالة دي بتقفل الثغرة: البضاعة المرتبطة بأمر مفتوح
     * (`pending`/`arrived`) على نفس المندوب بتتحسب **مشغولة**.
     *
     * ⚠️ `qty - delivered_qty`: الأمر اللي اتسلّم جزئياً بيحجز
     * الباقي بس. و`max(...,0)` عشان التسليم الزيادة (لو حصل)
     * مايطلّعش حجز بالسالب يفتح ثغرة تانية.
     *
     * ⚠️ `$exceptPoId` **ضروري**: `fulfil` بتتنده بعد إنشاء الأمر
     * على طول، فالأمر بيبقى `pending` وهيحجز ضد نفسه ويقول
     * «العربية مش كفاية» غلط.
     */
    public function committedFor(int $productId, ?int $exceptPoId = null): int
    {
        $q = PurchaseOrderItem::query()
            ->where('purchase_order_items.product_id', $productId)
            ->whereHas('purchaseOrder', function ($po) use ($exceptPoId) {
                $po->where('assigned_to', $this->user_id)
                    ->whereIn('status', ['pending', 'arrived']);

                if ($exceptPoId !== null) {
                    $po->whereKeyNot($exceptPoId);
                }
            });

        return (int) $q->get()->sum(
            fn ($i) => max((int) $i->qty - (int) $i->delivered_qty, 0),
        );
    }

    /** المتاح فعلاً للوعد بيه = الموجود ناقص المحجوز لأوامر مفتوحة */
    public function freeFor(int $productId, ?int $exceptPoId = null): int
    {
        return max($this->availableFor($productId) - $this->committedFor($productId, $exceptPoId), 0);
    }

    /**
     * هل العهدة تكفي الكميات دي؟ بيرجع أول صنف ناقص.
     *
     * ⚠️ بيقيس على `freeFor` مش `availableFor` — شوف `committedFor`.
     *
     * @param  array<int, int>  $qtyByProduct
     * @return array{ok: bool, short: array<int, array{product: string, need: int, have: int, committed: int}>}
     */
    public function canCover(array $qtyByProduct, ?int $exceptPoId = null): array
    {
        $this->loadMissing(['items.product', 'items.batch']);
        $short = [];

        foreach ($qtyByProduct as $productId => $need) {
            $need = (int) $need;
            if ($need <= 0) {
                continue;
            }

            $productId = (int) $productId;
            $committed = $this->committedFor($productId, $exceptPoId);
            $have = max($this->availableFor($productId) - $committed, 0);

            if ($have < $need) {
                $short[] = [
                    'product' => Product::find($productId)?->displayName() ?? '#'.$productId,
                    'need' => $need,
                    'have' => $have,
                    'committed' => $committed,
                ];
            }
        }

        return ['ok' => $short === [], 'short' => $short];
    }

    public function remainingUnits(): int
    {
        return $this->items->sum(fn ($i) => $i->remaining());
    }

    public function remainingValue(string $mode = 'new'): float
    {
        return $this->items->sum(fn ($i) => $i->remaining() * $i->product->priceFor($mode));
    }

    public function assignedValue(string $mode = 'new'): float
    {
        return $this->items->sum(fn ($i) => $i->assigned * $i->product->priceFor($mode));
    }

    /**
     * خصم كميات من العهدة بالـ FEFO — الباتش الأقرب انتهاءً يخرج الأول.
     *
     * بتفحص **كل** الكميات الأول وبترفض العملية كلها لو صنف واحد مش متاح،
     * وبعدين بتخصم. ⚠️ ممنوع تزوّد sold مباشرة من بره.
     *
     * @param  array<int, int>  $qtyByProductId  [product_id => qty]
     * @return string|null رسالة الخطأ، أو null لو تمام
     */
    public function deduct(array $qtyByProductId): ?string
    {
        $plan = $this->planDeduction($qtyByProductId);

        if (is_string($plan)) {
            return $plan;
        }

        foreach ($plan as [$item, $qty]) {
            $item->increment('sold', $qty);
        }

        return null;
    }

    /**
     * زي deduct بالظبط بس بترجع الباتشات المستخدمة كمان،
     * عشان الفاتورة تسجّل كل بند بالباتش بتاعه.
     *
     * @param  array<int, int>  $qtyByProductId
     * @return array{lines: array<int, array{item: CustodyItem, qty: int}>, error: ?string}
     */
    public function deductWithBatches(array $qtyByProductId): array
    {
        $plan = $this->planDeduction($qtyByProductId);

        if (is_string($plan)) {
            return ['lines' => [], 'error' => $plan];
        }

        $lines = [];
        foreach ($plan as [$item, $qty]) {
            $item->increment('sold', $qty);
            $lines[] = ['item' => $item, 'qty' => $qty];
        }

        return ['lines' => $lines, 'error' => null];
    }

    /**
     * بتوزّع المطلوب على بنود العهدة بترتيب الصلاحية — من غير ما تخصم.
     *
     * @param  array<int, int>  $qtyByProductId
     * @return array<int, array{0: CustodyItem, 1: int}>|string
     */
    private function planDeduction(array $qtyByProductId)
    {
        $plan = [];

        foreach ($qtyByProductId as $productId => $qty) {
            $qty = (int) $qty;
            if ($qty <= 0) {
                continue;
            }

            // الأقرب انتهاءً الأول؛ البنود اللي من غير باتش (داتا قديمة) في الآخر
            //
            // ⚠️ lockForUpdate ضروري: المندوب ممكن يبعت فاتورتين في نفس اللحظة
            // (دوبل تاب، أو الأبلكيشن بيعيد إرسال ريكوست اتأخر). من غير القفل
            // الاتنين بيقروا remaining() الأصلي، وكل واحدة تعدّي فحص الكفاية،
            // فالعربية تبيع أكتر من اللي فيها. القفل بيخلّي التانية تستنى لحد
            // ما الأولى تكمّت فتقرا الرصيد الحقيقي.
            //
            // القفل بيشتغل فعلاً في نقط الـ API (FieldApiController::storeInvoice
            // و ::deliver) لأنها جوه DB::transaction. في السيدرز الـ SELECT FOR
            // UPDATE بياخد القفل ويسيبه على طول (autocommit) — مش خطأ، والسيدر
            // بيشتغل بمفرده فمفيش تنافس. أي نقطة **إنتاج** جديدة بتنادي هنا
            // لازم تكون جوه DB::transaction وإلا القفل ملغي.
            $items = $this->items()
                ->with(['product', 'batch'])
                ->where('product_id', $productId)
                ->lockForUpdate()
                ->get()
                ->sortBy(fn (CustodyItem $i) => $i->batch?->expires_on?->timestamp ?? PHP_INT_MAX)
                ->values();

            $left = $qty;

            foreach ($items as $item) {
                if ($left <= 0) {
                    break;
                }

                // ممنوع نبيع من باتش منتهي حتى لو موجود في العربية
                if ($item->batch && $item->batch->isExpired()) {
                    continue;
                }

                $take = min($left, $item->remaining());
                if ($take <= 0) {
                    continue;
                }

                $plan[] = [$item, $take];
                $left -= $take;
            }

            if ($left > 0) {
                $name = $items->first()?->product?->displayName()
                    ?? __('stock.product_hash', ['id' => $productId]);

                return __('field.custody_not_enough', ['product' => $name, 'short' => $left]);
            }
        }

        return $plan;
    }

    /** الباتشات اللي قربت تنتهي وهي لسه في العربية */
    public function expiringItems(int $days = 30)
    {
        return $this->items
            ->filter(fn (CustodyItem $i) => $i->remaining() > 0
                && $i->batch !== null
                && $i->batch->daysLeft() <= $days);
    }

    /**
     * ═══ تصحيح إداري للعهدة — «التحميل اتسجّل غلط» (١٢ أغسطس ٢٠٢٦) ═══
     *
     * بياخد **أرقام مستهدفة** بالصنف (مش فروق): المحمَّل الجديد والهدايا
     * الجديدة، وبيظبط العهدة **والمخزن مع بعض** — التصحيح معناه إن
     * المخزن فعلياً ادّى كمية مختلفة عن المتسجّل، فالفرق لازم يرجع
     * للأرفف أو يخرج منها، وإلا الجرد الجاي هيطلع عجز/زيادة وهمية.
     *
     * ⚠️ **مفيش مسار حساب موازي:**
     *   - الزيادة بتمرّ بـ`PickOrder::issueDirect` + `handOver` — نفس
     *     مسار التحميل الرسمي بالحرف (FEFO، باتش على البند، خصم أرفف).
     *   - النقص بيرجع للرف بنفس `PickOrderItem::returnToShelf` بتاعة
     *     فرق الاستلام — من بند التجهيز الأصلي للباتش نفسه.
     *
     * ⚠️ **الأرضية (floor):** المحمَّل الجديد ≥ المباع + المرجّع للمخزن
     * (والمباع شامل تسليمات أوامر التوريد — `deduct` بيزوّد `sold`).
     * والهدايا الجديدة ≥ الموزّع فعلاً. من غير الأرضية دي `remaining()`
     * بيطلع سالب ومعادلة التصفية بتتكسر.
     *
     * ⚠️ بند قديم من غير باتش: العهدة بتتظبط والمخزن مابيتلمسش —
     * البند ده أصلاً ماخصمش من باتش، فمفيش حقيقة مخزنية نرجّع لها.
     *
     * @param  array<int, int>  $assignedTarget  [product_id => المحمَّل الجديد]
     * @param  array<int, int>  $giftTarget      [product_id => هدايا جديدة]
     * @return string|null رسالة الخطأ، أو null لو تمام
     */
    public function adjustTo(array $assignedTarget, array $giftTarget, User $actor, string $reason): ?string
    {
        if ($this->status === 'closed') {
            return __('field.custody_closed');
        }
        if ($this->warehouse === null || $this->user === null) {
            return __('stock.no_warehouse');
        }

        $items = $this->items()->with(['product', 'batch'])->get();

        $sum = fn (int $pid, string $col) => (int) $items->where('product_id', $pid)->sum($col);

        $inc = [];
        $dec = [];
        $incGift = [];
        $decGift = [];
        $touched = [];

        $productIds = array_unique(array_merge(array_keys($assignedTarget), array_keys($giftTarget)));

        foreach ($productIds as $pid) {
            $pid = (int) $pid;
            $product = Product::find($pid);

            if ($product === null) {
                continue;
            }

            if (array_key_exists($pid, $assignedTarget)) {
                $target = max((int) $assignedTarget[$pid], 0);
                $cur = $sum($pid, 'assigned');
                // ⚠️ **والمحوَّل لمندوب تاني جوه الأرضية كمان** (١٤/٨).
                // من غيره تصحيح إداري بعد تحويل كان بيقدر ينزّل المحمَّل
                // تحت اللي خرج فعلاً — و`remaining()` تطلع سالبة.
                $floor = $sum($pid, 'sold') + $sum($pid, 'returned') + $sum($pid, 'transferred_out');

                if ($target < $floor) {
                    return __('field.custody_adjust_floor_err', [
                        'product' => $product->displayName(),
                        'floor' => $floor,
                    ]);
                }

                if ($target > $cur) {
                    $inc[$pid] = $target - $cur;
                } elseif ($target < $cur) {
                    $dec[$pid] = $cur - $target;
                }
                if ($target !== $cur) {
                    $touched[$pid] = true;
                }
            }

            if (array_key_exists($pid, $giftTarget)) {
                $target = max((int) $giftTarget[$pid], 0);
                $cur = $sum($pid, 'gift_assigned');
                $floor = $sum($pid, 'gift_given');

                if ($target < $floor) {
                    return __('field.custody_adjust_floor_err', [
                        'product' => $product->displayName(),
                        'floor' => $floor,
                    ]);
                }

                if ($target > $cur) {
                    $incGift[$pid] = $target - $cur;
                } elseif ($target < $cur) {
                    $decGift[$pid] = $cur - $target;
                }
                if ($target !== $cur) {
                    $touched[$pid] = true;
                }
            }
        }

        if ($touched === []) {
            return __('field.custody_adjust_no_change');
        }

        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($dec, $decGift, $inc, $incGift, $actor, $reason, $touched) {
                // ═══ ١. النقص — يرجع للرف (عكس pull بالحرف) ═══
                foreach ($dec as $pid => $qty) {
                    $this->pullBackToShelf((int) $pid, (int) $qty, false);
                }
                foreach ($decGift as $pid => $qty) {
                    $this->pullBackToShelf((int) $pid, (int) $qty, true);
                }

                // ═══ ٢. الزيادة — أمر تجهيز حقيقي بيتسلّم فوراً ═══
                // نفس مسار التحميل الرسمي: FEFO بيختار الباتش، البضاعة
                // بتخرج من الأرفف في `markReady`، و`handOver` بيكمّل
                // على **العهدة المفتوحة دي نفسها** (عقيدة ١٠/٨).
                if ($inc !== [] || $incGift !== []) {
                    $result = PickOrder::issueDirect(
                        $this->warehouse,
                        $this->user,
                        $inc,
                        $incGift,
                        $actor,
                        __('field.custody_adjust_note', ['reason' => $reason]),
                    );

                    if ($result['error'] !== null) {
                        throw new \App\Exceptions\Rejected($result['error']);
                    }

                    if ($err = $result['order']->handOver($this->user)) {
                        throw new \App\Exceptions\Rejected($err);
                    }

                    // ⚠️ `handOver` بيدوّر على العهدة المفتوحة بنفسه —
                    // لو (بداتا شاذة: صفين مفتوحين) كمّل على عهدة تانية،
                    // نرجّع كل حاجة بدل ما التصحيح ينزل على صف غلط.
                    if ((int) $result['order']->fresh()->custody_id !== (int) $this->id) {
                        throw new \App\Exceptions\Rejected(__('field.custody_adjust_none'));
                    }
                }

                // ═══ ٣. `stocks` صورة من الباتشات — مصالحة ختامية ═══
                foreach (array_keys($touched) as $pid) {
                    \App\Services\StockCounting::resync((int) $pid, (int) $this->warehouse_id);
                }
            });
        } catch (\App\Exceptions\Rejected $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * إنقاص محمَّل (أو هدايا) صنف وإرجاع الفرق للرف — جوه ترانزاكشن بس.
     *
     * بيقلّل من البنود بعكس الـFEFO (الأبعد انتهاءً الأول — اللي كان
     * هيخرج آخر حاجة هو اللي «ماتحمّلش أصلاً»)، وبيرجّع كل كمية لرفّ
     * باتشها عن طريق بند التجهيز الأصلي (`returnToShelf`).
     *
     * @throws \App\Exceptions\Rejected لو الكمية مش متاحة للإنقاص
     */
    private function pullBackToShelf(int $productId, int $qty, bool $gift): void
    {
        $items = $this->items()
            ->with(['product', 'batch'])
            ->where('product_id', $productId)
            ->lockForUpdate()
            ->get()
            ->sortByDesc(fn (CustodyItem $i) => $i->batch?->expires_on?->timestamp ?? -1)
            ->values();

        $left = $qty;

        foreach ($items as $item) {
            if ($left <= 0) {
                break;
            }

            $can = $gift ? $item->giftLeft() : $item->remaining();
            $take = min($left, max($can, 0));

            if ($take <= 0) {
                continue;
            }

            if ($gift) {
                $item->gift_assigned = (int) $item->gift_assigned - $take;
            } else {
                $item->assigned = (int) $item->assigned - $take;
            }
            $item->save();
            $left -= $take;

            $this->restockFromItem($item, $take);
        }

        if ($left > 0) {
            $name = Product::find($productId)?->displayName() ?? '#'.$productId;

            throw new \App\Exceptions\Rejected(__('field.custody_not_enough', [
                'product' => $name,
                'short' => $left,
            ]));
        }
    }

    /**
     * ═══ إرجاع كمية من بند عهدة للرف — الحركة المخزنية لوحدها ═══
     *
     * ⚠️ **المسار الوحيد اللي بيرجّع بضاعة عربية لرف.** اتفصل من
     * `pullBackToShelf` (١٤ أغسطس ٢٠٢٦) عشان تحويل «مندوب ← مخزن»
     * يستخدمه بالحرف بدل ما يكتب خصم/إضافة موازية. الفرق بين
     * النداءين إن `pullBackToShelf` بتنقّص `assigned` كمان (تصحيح
     * إداري: «التحميل اتسجّل غلط»)، والتحويل بيسجّل `returned`
     * (البضاعة اتحمّلت صح ورجعت فعلاً) — والحركة المخزنية واحدة.
     *
     * ⚠️ **مابتلمسش أي عمود على بند العهدة.** اللي بيندهها هو اللي
     * بيقرّر أنهي خانة تتحرّك، وده بيمنع أي مسار يخصم مرتين.
     *
     * ⚠️ لازم تتنادى **جوه** `DB::transaction` — بتكتب على الباتش
     * والرف مع بعض.
     *
     * @param  Warehouse|null  $into  المخزن اللي البضاعة بترجع له
     *                                (الافتراضي مخزن العهدة) — بيستخدم
     *                                في مسار الفولباك بس
     */
    public function restockFromItem(CustodyItem $item, int $qty, ?Warehouse $into = null): void
    {
        if ($qty <= 0) {
            return;
        }

        $item->loadMissing('batch');

        if ($item->batch_id === null || $item->batch === null) {
            // بند قديم من غير باتش (أو باتشه اتمسح) — العهدة بس،
            // مفيش أصل مخزني نرجّع له
            return;
        }

        $productId = (int) $item->product_id;

        // بند التجهيز اللي جاب الكمية دي — نفس الرف اللي طلعت منه
        $poi = PickOrderItem::whereHas('pickOrder',
            fn ($q) => $q->where('custody_id', $this->id))
            ->where('product_id', $productId)
            ->where('batch_id', $item->batch_id)
            ->orderByDesc('id')
            ->first();

        if ($poi !== null) {
            $poi->returnToShelf($qty);

            // ⚠️ نفس دلالة فرق الاستلام الموجودة: «اتجمّع كذا،
            // المستلم فعلياً أقل، والفرق رجع الرف». من غيرها
            // مطابقة التجهيز بالعهدة كانت هتوري فرق وهمي دايماً.
            $poi->qty_received = max((int) $poi->qty_received - $qty, 0);
            $poi->save();
            $poi->pickOrder?->update(['has_variance' => true]);

            return;
        }

        // عهدة قديمة من غير أمر تجهيز — نفس حركة returnToShelf
        // بالحرف (باتش + رف سحب)، مش مسار حساب جديد
        $item->batch->increment('qty_remaining', $qty);
        $item->batch->decrement('qty_issued', $qty);

        $shelf = \App\Services\OpeningStock::pickShelf($into ?? $this->warehouse);
        $row = BatchLocation::firstOrNew([
            'batch_id' => $item->batch_id,
            'location_id' => $shelf->id,
        ]);
        $row->product_id = $productId;
        $row->qty = (int) $row->qty + $qty;
        $row->save();
    }
}
