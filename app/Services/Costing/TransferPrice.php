<?php

namespace App\Services\Costing;

use App\Exceptions\Rejected;
use App\Models\Product;
use App\Models\ProductTransferPrice;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * سعر التحويل الداخلي (D10) — رقم إداري مش سعر بيع (`Pricing` مالوش دعوة).
 * تاريخ كامل في `product_transfer_prices`؛ الساري = آخر سعر تاريخ سريانه
 * في اليوم أو قبله. الاعتماد بيضيف صف جديد ساري من النهارده (الأدمن بس —
 * الحارس في الكنترولر).
 */
final class TransferPrice
{
    /** @var array<string, ?ProductTransferPrice> لكل (صنف، يوم) — `flush()` قبل أي مزامنة */
    private static array $memo = [];

    public static function effective(int $productId, CarbonInterface $date): ?ProductTransferPrice
    {
        $key = $productId.'|'.$date->toDateString();
        if (array_key_exists($key, self::$memo)) {
            return self::$memo[$key];
        }

        return self::$memo[$key] = ProductTransferPrice::where('product_id', $productId)
            ->whereDate('effective_from', '<=', $date->toDateString())
            ->orderByDesc('effective_from')->orderByDesc('id')
            ->first();
    }

    /**
     * مستوى التنبيه (D10): `above` التكلفة > سعر التحويل، `near` التكلفة ≥
     * `cost_alert_pct`% منه، `ok`، أو `none` لو مفيش سعر تحويل.
     */
    public static function alertLevel(float $unitCost, ?ProductTransferPrice $tp): string
    {
        if ($tp === null || (float) $tp->price <= 0) {
            return 'none';
        }
        $price = (float) $tp->price;

        return match (true) {
            $unitCost > $price => 'above',
            $unitCost >= round($price * CostingSettings::alertPct() / 100, 4) => 'near',
            default => 'ok',
        };
    }

    public static function flush(): void
    {
        self::$memo = [];
    }

    public static function approve(Product $p, float $price, User $by, ?float $unitCost, ?float $markupPct, ?string $note): ProductTransferPrice
    {
        if ($price <= 0) {
            throw new Rejected(__('costing.tp_price_required'));
        }

        self::flush();

        return DB::transaction(fn () => ProductTransferPrice::create([
            'product_id' => $p->id,
            'price' => round($price, 2),
            'unit_cost' => round($unitCost ?? (float) $p->cost, 4),
            'markup_pct' => $markupPct === null ? null : round($markupPct, 2),
            'effective_from' => today()->toDateString(),
            'approved_by' => $by->id,
            'approved_at' => now(),
            'note' => $note === null ? null : mb_substr($note, 0, 255),
        ]));
    }
}
