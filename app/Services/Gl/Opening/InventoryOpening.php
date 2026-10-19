<?php

namespace App\Services\Gl\Opening;

use App\Contracts\Gl\OpeningProvider;
use App\Models\Batch;
use App\Models\CostCenter;
use App\Models\Custody;
use App\Models\Gl\GlAccount;
use App\Services\Pricing;
use Illuminate\Support\Carbon;

/**
 * ═══ سطر المخزون في قيد الافتتاح (D6) ═══
 *
 * قيمة **النهارده** مش قيمة يوم البداية — المخزون مالوش تاريخ رجعي:
 *   • الباتشات في المخازن: `qty_remaining × Pricing::costFor(product, batch)`
 *     (المحجوز والمنتهي محسوبين — لسه بضاعة على الرف).
 *   • العهد المفتوحة: باقي كل بند (`CustodyItem::remaining()`) بتكلفة باتشه
 *     (أو تكلفة الصنف لو البند من غير باتش). الهدايا مش محسوبة (بند منفصل).
 *     الباتش اتخصم منه وقت التسليم، فمفيش عدّ مرتين.
 *
 * سطر مدين واحد لكل مركز تكلفة: مركز المخزن (`CostCenter::forWarehouse`)،
 * والعهدة على مركز مخزنها. مخزن مالوش مركز = سطر من غير مركز.
 */
class InventoryOpening implements OpeningProvider
{
    public function key(): string
    {
        return 'inventory';
    }

    public function label(): string
    {
        return __('golive.opening_src_inventory');
    }

    public function lines(Carbon $asOf): array
    {
        /** @var array<string, float> $byCenter مفتاح المركز ('' = من غير مركز) */
        $byCenter = [];
        $centers = [];
        $centerOf = function ($warehouse) use (&$centers): ?int {
            if ($warehouse === null) {
                return null;
            }

            return $centers[$warehouse->id] ??= CostCenter::forWarehouse($warehouse)?->id;
        };

        Batch::with(['product', 'warehouse'])->where('qty_remaining', '>', 0)->orderBy('id')
            ->chunk(500, function ($batches) use (&$byCenter, $centerOf) {
                foreach ($batches as $b) {
                    if ($b->product === null) {
                        continue;
                    }
                    $k = (string) $centerOf($b->warehouse);
                    $byCenter[$k] = ($byCenter[$k] ?? 0.0) + $b->qty_remaining * Pricing::costFor($b->product, $b);
                }
            });

        Custody::with(['warehouse', 'items.product', 'items.batch'])
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '<>', 'closed'))
            ->orderBy('id')
            ->chunk(200, function ($custodies) use (&$byCenter, $centerOf) {
                foreach ($custodies as $c) {
                    $k = (string) $centerOf($c->warehouse);
                    foreach ($c->items as $item) {
                        $left = $item->remaining();
                        if ($left <= 0 || $item->product === null) {
                            continue;
                        }
                        $byCenter[$k] = ($byCenter[$k] ?? 0.0) + $left * Pricing::costFor($item->product, $item->batch);
                    }
                }
            });

        $account = GlAccount::findKey('inventory')->id;
        $lines = [];
        foreach ($byCenter as $k => $v) {
            $v = round($v, 2);
            if ($v < 0.005) {
                continue;
            }
            $lines[] = [
                'account_id' => $account,
                'cost_center_id' => $k === '' ? null : (int) $k,
                'debit' => $v,
                'credit' => 0.0,
                'memo' => $this->label(),
            ];
        }

        return $lines;
    }
}
