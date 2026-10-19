<?php

namespace App\Services;

use App\Models\SupplierContract;
use App\Models\SupplierOrder;
use Carbon\CarbonInterface;

/**
 * ═══════════════════════════════════════════════════════════════
 * قواعد موافقة أمر الشراء (لين ٥، D4/D5)
 * ═══════════════════════════════════════════════════════════════
 *
 * - سطر مغطّى بعقد ساري وسعره ≤ سعر العقد (+٠٫٠٠٥) → مايحتاجش موافقة
 *   حتى لو المورد/الصنف جديد (العقد أقوى من الجِدّة).
 * - سعر أعلى من العقد → `above_contract`.
 * - الصنف على عقود للمورد بس مفيش واحد ساري → `contract_expired`.
 * - مفيش عقد: مورد مالوش أي أمر سابق → `new_supplier`، ومورد معروف
 *   بس الصنف جديد عليه → `new_material`.
 * «معروف» = أمر سابق للمورد حالته مش `cancelled`/`pending` فيه الصنف.
 */
class PurchaseApproval
{
    private const EPS = 0.005;

    /**
     * @param  array<int, array{qty:int, unit_cost:float}>  $lines  مفتاحها product_id
     * @return array{reasons: list<array{code:string, product_id:int, price:?float, contract_price:?float}>, contract_id: ?int, contract_prices: array<int,float>}
     */
    public function assess(int $supplierId, array $lines, CarbonInterface|string $on): array
    {
        $reasons = [];
        $contractId = null;
        $prices = [];

        $knownOrders = SupplierOrder::query()
            ->where('supplier_id', $supplierId)
            ->whereNotIn('status', ['cancelled', 'pending']);
        $supplierKnown = (clone $knownOrders)->exists();

        foreach ($lines as $productId => $line) {
            $productId = (int) $productId;
            $price = round((float) ($line['unit_cost'] ?? 0), 2);
            $live = SupplierContract::liveItemFor($supplierId, $productId, $on);

            if ($live !== null) {
                $prices[$productId] = (float) $live->unit_price;
                $contractId ??= (int) $live->supplier_contract_id;

                if ($price > (float) $live->unit_price + self::EPS) {
                    $reasons[] = ['code' => 'above_contract', 'product_id' => $productId,
                        'price' => $price, 'contract_price' => (float) $live->unit_price];
                }

                continue;
            }

            if (SupplierContract::anyItemFor($supplierId, $productId)) {
                $reasons[] = ['code' => 'contract_expired', 'product_id' => $productId,
                    'price' => $price, 'contract_price' => null];

                continue;
            }

            if (! $supplierKnown) {
                $reasons[] = ['code' => 'new_supplier', 'product_id' => $productId,
                    'price' => $price, 'contract_price' => null];
            } elseif (! (clone $knownOrders)->whereHas('items', fn ($q) => $q->where('product_id', $productId))->exists()) {
                $reasons[] = ['code' => 'new_material', 'product_id' => $productId,
                    'price' => $price, 'contract_price' => null];
            }
        }

        return ['reasons' => $reasons, 'contract_id' => $contractId, 'contract_prices' => $prices];
    }

    /** نص السبب للشاشة — اسم الصنف بيجي من الكنترولر (`product`) */
    public static function reasonText(array $reason, ?string $productName = null): string
    {
        return __('supplier.reason_'.$reason['code'], [
            'product' => $productName ?? ('#'.($reason['product_id'] ?? '')),
            'price' => number_format((float) ($reason['price'] ?? 0), 2),
            'contract' => number_format((float) ($reason['contract_price'] ?? 0), 2),
        ]);
    }
}
