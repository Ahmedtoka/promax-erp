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
     * هل العهدة تكفي الكميات دي؟ بيرجع أول صنف ناقص.
     *
     * @param  array<int, int>  $qtyByProduct
     * @return array{ok: bool, short: array<int, array{product: string, need: int, have: int}>}
     */
    public function canCover(array $qtyByProduct): array
    {
        $this->loadMissing(['items.product', 'items.batch']);
        $short = [];

        foreach ($qtyByProduct as $productId => $need) {
            $need = (int) $need;
            if ($need <= 0) {
                continue;
            }

            $have = $this->availableFor((int) $productId);
            if ($have < $need) {
                $short[] = [
                    'product' => Product::find($productId)?->displayName() ?? '#'.$productId,
                    'need' => $need,
                    'have' => $have,
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
}
