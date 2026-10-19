<?php

namespace App\Services\Mfg;

use App\Exceptions\Rejected;
use App\Models\AppNotification;
use App\Models\Batch;
use App\Models\BatchLocation;
use App\Models\BomLine;
use App\Models\ProductionIssue;
use App\Models\ProductionOrder;
use App\Models\ProductSpec;
use App\Models\User;
use App\Services\OpeningStock;
use App\Services\StockCounting;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * قفل أمر الإنتاج (التصنيع ١٩/١٠، spec §3.5، D6 + D9):
 * باتش تام في مخزن التام **موقوف (حجر)** لحد قرار الجودة، وتكلفة الخامات
 * الفعلية، والمعياري للإنتاج الفعلي، والانحراف والهالك لكل خامة.
 *
 * ⚠️ شروط القفل (D6): الحالة `finished`، خامة واحدة على الأقل مصروفة صافي،
 * والإنتاج ≥ ١ (قطع صحيحة، D2). الأمر بيتقفل على صف مقفول — قفل تاني = رفض،
 * وعمره ما يعمل باتش تاني (Review Focus 3).
 * ⚠️ الصلاحية: مواصفة معتمدة فيها `shelf_life_days` ← تاريخ الإنتاج + الأيام
 * (`expiry_source = spec`)، غير كده `Product::expiryFrom()` (`product`).
 * ⚠️ مفيش قيود GL هنا — التكاليف غير المباشرة والقيود بتاعة الخط ٩
 * (`overhead_cost` بيتحسب في الإجمالي بقيمته الحالية، صفر هنا).
 */
class Closing
{
    public static function close(ProductionOrder $o, int $outputQty, User $by, ?CarbonInterface $producedOn = null): ProductionOrder
    {
        if ($outputQty < 1) {
            throw new Rejected(__('mfg.output_positive'));
        }

        $producedOn = Carbon::parse(($producedOn ?? today())->format('Y-m-d'))->startOfDay();

        $row = DB::transaction(function () use ($o, $outputQty, $by, $producedOn) {
            $row = ProductionOrder::query()->whereKey($o->id)->lockForUpdate()->first();

            if ($row === null || $row->status !== 'finished') {
                throw new Rejected(__('mfg.order_bad_state'));
            }

            $net = $row->netIssuedAll();
            if (! collect($net)->contains(fn (float $q) => $q > 0.0005)) {
                throw new Rejected(__('mfg.close_no_materials'));
            }

            $product = $row->product()->lockForUpdate()->first();
            $fg = $row->warehouse()->first();
            if ($product === null || $fg === null) {
                throw new Rejected(__('mfg.no_warehouse'));
            }

            // رقم التشغيلة ممكن يكون اتاخد من وقت الإنشاء (باتش اتعمل يدوي)
            if (Orders::batchNoTaken($product->id, $row->batch_no, $row->id)) {
                throw new Rejected(__('mfg.batch_no_taken'));
            }

            $spec = ProductSpec::approvedFor($product);
            if ($spec !== null && (int) $spec->shelf_life_days > 0) {
                $expires = $producedOn->copy()->addDays((int) $spec->shelf_life_days);
                $source = 'spec';
            } else {
                $expires = $product->expiryFrom($producedOn);
                $source = 'product';
            }

            $materialCost = $row->materialCost();

            $batch = Batch::create([
                'product_id' => $product->id,
                'warehouse_id' => $fg->id,
                'batch_no' => $row->batch_no,
                'produced_on' => $producedOn->toDateString(),
                'expires_on' => $expires->toDateString(),
                'qty_received' => $outputQty,
                'qty_remaining' => $outputQty,
                'cost' => round($materialCost / $outputQty, 2),
                'blocked' => true,
                'notes' => $row->number,
            ]);

            if ($err = BatchLocation::putAway($batch->fresh(), OpeningStock::pickShelf($fg), $outputQty)) {
                throw new Rejected($err);
            }

            StockCounting::resync($product->id, $fg->id);

            // ═══ الفعلي قصاد المعياري لكل خامة (D9) ═══
            $values = self::netValues($row);
            $bom = $row->bom()->first();
            $sumActual = 0.0;
            $sumStd = 0.0;
            $sumBase = 0.0;

            foreach ($row->lines()->get() as $l) {
                $probe = new BomLine(['qty' => $l->bom_qty, 'basis' => $l->basis]);
                $base = round($probe->baseFor($outputQty), 3);
                $std = $bom ? $bom->stdFor($probe, $outputQty) : $base;

                $actualQty = round($net[$l->product_id] ?? 0.0, 3);
                $actualValue = $values[$l->product_id] ?? 0.0;
                $avg = $actualQty > 0.0005 ? $actualValue / $actualQty : 0.0;

                $actualCost = round($actualValue, 2);
                $stdCost = round($std * $avg, 2);
                $baseValue = round($base * $avg, 2);

                $l->forceFill([
                    'base_qty_actual' => $base,
                    'std_qty_actual' => $std,
                    'actual_qty' => $actualQty,
                    'actual_cost' => $actualCost,
                    'std_cost' => $stdCost,
                    'variance_qty' => round($actualQty - $std, 3),
                    'variance_value' => round($actualCost - $stdCost, 2),
                    'waste_pct' => $actualQty > 0.0005 ? round(($actualQty - $base) / $actualQty * 100, 2) : null,
                ])->save();

                $sumActual += $actualCost;
                $sumStd += $stdCost;
                $sumBase += $baseValue;
            }

            $total = round($materialCost + (float) $row->overhead_cost, 2);

            $row->forceFill([
                'output_qty' => $outputQty,
                'produced_on' => $producedOn->toDateString(),
                'expires_on' => $expires->toDateString(),
                'expiry_source' => $source,
                'fg_batch_id' => $batch->id,
                'material_cost' => $materialCost,
                'std_material_cost' => round($sumStd, 2),
                'total_cost' => $total,
                'unit_cost' => round($total / $outputQty, 4),
                'waste_pct' => $sumActual > 0.005 ? round(($sumActual - $sumBase) / $sumActual * 100, 2) : null,
                'yield_pct' => $row->planned_qty > 0 ? round($outputQty / $row->planned_qty * 100, 2) : null,
                'status' => 'closed',
                'closed_at' => now(),
                'closed_by' => $by->id,
                'qc_status' => 'quarantine',
            ])->save();

            return $row;
        });

        $o->setRawAttributes($row->getAttributes(), true);

        self::notifyQuality($row);

        return $row;
    }

    /**
     * صافي قيمة الصرف لكل خامة (صرف + زيادة − مرتجع) — كويري واحدة.
     *
     * @return array<int, float> product_id ⇒ value
     */
    private static function netValues(ProductionOrder $o): array
    {
        return ProductionIssue::query()->where('production_order_id', $o->id)
            ->groupBy('product_id')
            ->select('product_id', DB::raw("SUM(CASE WHEN kind = 'return' THEN -value ELSE value END) AS net"))
            ->pluck('net', 'product_id')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    /** الجودة بتتبلّغ إن فيه باتش في الحجر مستني قرارها */
    private static function notifyQuality(ProductionOrder $o): void
    {
        $o->loadMissing('product');
        $number = $o->number;
        $batchNo = $o->batch_no;
        $product = $o->product;
        $qty = (int) $o->output_qty;
        $link = route('mfg.qc', [], false);

        User::query()->where('role', 'quality')->where('active', true)->get()
            ->each(fn (User $u) => AppNotification::send(
                $u,
                fn () => __('mfg.qc_waiting_title', ['batch' => $batchNo]),
                fn () => __('mfg.qc_waiting_body', [
                    'number' => $number,
                    'product' => $product?->displayName() ?? '',
                    'qty' => number_format($qty),
                ]),
                good: true,
                link: $link,
            ));
    }
}
