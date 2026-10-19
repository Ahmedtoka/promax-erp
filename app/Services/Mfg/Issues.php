<?php

namespace App\Services\Mfg;

use App\Exceptions\Rejected;
use App\Models\Batch;
use App\Models\BatchLocation;
use App\Models\Product;
use App\Models\ProductionIssue;
use App\Models\ProductionOrder;
use App\Models\User;
use App\Services\OpeningStock;
use App\Services\RawStock;
use App\Services\StockCounting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * صرف الخامات لأمر إنتاج (التصنيع ١٩/١٠، spec §3.5): صرف، صرف زيادة، مرتجع للمخزن.
 *
 * ⚠️ **الخامة بتخرج من `RawStock::issue` بس** (واجهة الخط ٥) — FEFO وقفل
 * الباتشات والرفض قبل أي حركة جوّاه. هنا: قفل صف الأمر الأول، فحص الحالة
 * على الصف المقفول، وصف `production_issues` لكل باتش بتكلفة الوحدة بتاعته.
 * ⚠️ خامات الـBOM بس (D5) — الجينيالوجي يفضل نضيف. فوق المعياري = `extra`.
 * ⚠️ المرتجع (D4) بيرجع لنفس الباتشات اللي الأمر سحب منها، الأحدث صرفاً
 * الأول، ومايعدّيش صافي الأمر في كل باتش.
 */
class Issues
{
    public const ISSUE_KINDS = ['issue', 'extra'];

    /**
     * صرف كمية من خامة للأمر.
     *
     * @return Collection<int, ProductionIssue>
     */
    public static function issue(ProductionOrder $o, Product $material, float $qty, User $by, string $kind = 'issue', ?string $note = null): Collection
    {
        if (! in_array($kind, self::ISSUE_KINDS, true)) {
            throw new Rejected(__('mfg.issue_bad_kind'));
        }

        return DB::transaction(function () use ($o, $material, $qty, $by, $kind, $note) {
            $row = self::lockedOpen($o);
            self::inOrder($row, $material);

            return self::draw($row, $material, $qty, $by, $kind, $note);
        });
    }

    /**
     * صرف المعياري الباقي لكل سطور الأمر في ترانزاكشن واحدة —
     * عجز في أي سطر = ولا حاجة اتصرفت.
     *
     * @return int عدد السطور اللي اتصرفت
     */
    public static function issueStandard(ProductionOrder $o, User $by): int
    {
        return DB::transaction(function () use ($o, $by) {
            $row = self::lockedOpen($o);
            $net = $row->netIssuedAll();
            $count = 0;

            // ترتيب ثابت بالمنتج — أمرين بيصرفوا في نفس الوقت يقفلوا الخامات بنفس الترتيب (مفيش deadlock)
            foreach ($row->lines()->with('product')->orderBy('product_id')->orderBy('id')->get() as $line) {
                $remaining = round((float) $line->std_qty - ($net[$line->product_id] ?? 0.0), 3);

                if ($remaining <= 0.0005 || $line->product === null) {
                    continue;
                }

                // D2: whole units until Lane 5 — الباتشات أعداد صحيحة، فالباقي بيتقرّب لفوق
                $qty = (float) ceil($remaining - 0.0005);

                self::draw($row, $line->product, $qty, $by, 'issue', null);
                $count++;
            }

            return $count;
        });
    }

    /**
     * مرتجع للمخزن — لنفس باتشات الخامة اللي الأمر سحب منها (D4).
     *
     * @return Collection<int, ProductionIssue>
     */
    public static function returnToStore(ProductionOrder $o, Product $material, float $qty, User $by, ?string $note = null): Collection
    {
        if ($qty <= 0) {
            throw new Rejected(__('mfg.qty_positive'));
        }

        // نفس قاعدة D2 — الباتش بيستقبل أعداد صحيحة بس
        if (abs($qty - round($qty)) > 0.0000001) {
            throw new Rejected(__('mfg.raw_whole_units'));
        }

        $need = (int) round($qty);

        return DB::transaction(function () use ($o, $material, $need, $by, $note) {
            $row = self::lockedOpen($o);
            self::inOrder($row, $material);

            $held = $row->netIssuedByBatch($material->id);
            $total = round(array_sum($held), 3);

            if ($need > $total + 0.0005) {
                throw new Rejected(__('mfg.return_exceeds', ['held' => self::fmt($total)]));
            }

            // الأحدث صرفاً الأول + تكلفة الوحدة من آخر صرف على الباتش
            $latest = ProductionIssue::query()->where('production_order_id', $row->id)
                ->where('product_id', $material->id)->where('kind', '!=', 'return')
                ->whereIn('batch_id', array_keys($held))
                ->orderByDesc('id')->get()->unique('batch_id');

            // القفل بنفس ترتيب RawStock (expires_on, id) عشان مايحصلش deadlock مع صرف شغال؛
            // اللفّ تحت لسه بترتيب الأحدث صرفاً ($latest)
            $batches = Batch::query()->whereIn('id', $latest->pluck('batch_id'))
                ->orderBy('expires_on')->orderBy('id')
                ->lockForUpdate()->get()->keyBy('id');

            $rows = collect();
            $left = $need;
            $warehouses = [];

            foreach ($latest as $last) {
                if ($left <= 0) {
                    break;
                }

                $batch = $batches[$last->batch_id] ?? null;
                if ($batch === null) {
                    continue;
                }

                $take = min($left, (int) floor($held[$batch->id] + 0.0005));
                if ($take <= 0) {
                    continue;
                }

                $batch->receive($take);
                self::shelve($batch, $take);
                $warehouses[$batch->warehouse_id] = true;

                $unit = (float) $last->unit_cost;
                $rows->push(ProductionIssue::create([
                    'production_order_id' => $row->id,
                    'product_id' => $material->id,
                    'batch_id' => $batch->id,
                    'kind' => 'return',
                    'qty' => $take,
                    'unit_cost' => $unit,
                    'value' => round($take * $unit, 2),
                    'user_id' => $by->id,
                    'issued_at' => now(),
                    'note' => self::note($note),
                ]));

                $left -= $take;
            }

            if ($left > 0) {
                throw new Rejected(__('mfg.return_exceeds', ['held' => self::fmt($total)]));
            }

            foreach (array_keys($warehouses) as $wid) {
                StockCounting::resync($material->id, (int) $wid);
            }

            return $rows;
        });
    }

    /** صرف فعلي من مخزن خامات الأمر — جوه ترانزاكشن والأمر مقفول */
    private static function draw(ProductionOrder $row, Product $material, float $qty, User $by, string $kind, ?string $note): Collection
    {
        $raw = $row->rawWarehouse()->first();
        if ($raw === null) {
            throw new Rejected(__('mfg.no_warehouse'));
        }

        $at = now();

        return collect(RawStock::issue($raw, $material, $qty, $row))->map(fn (array $l) => ProductionIssue::create([
            'production_order_id' => $row->id,
            'product_id' => $material->id,
            'batch_id' => $l['batch_id'],
            'kind' => $kind,
            'qty' => $l['qty'],
            'unit_cost' => $l['cost'],
            'value' => round($l['qty'] * $l['cost'], 2),
            'user_id' => $by->id,
            'issued_at' => $at,
            'note' => self::note($note),
        ]));
    }

    /**
     * الرجوع للرف: أول رف موجود للباتش، وإلا رف من غير سعة في مخزنه.
     * ⚠️ بعد `receive` — `putAway` بيقارن بالباقي غير المترصّف.
     */
    private static function shelve(Batch $batch, int $qty): void
    {
        $loc = BatchLocation::query()->where('batch_id', $batch->id)
            ->orderBy('id')->lockForUpdate()->first();

        if ($loc !== null) {
            $loc->give($qty);

            return;
        }

        $warehouse = $batch->warehouse()->first();
        if ($warehouse === null) {
            throw new Rejected(__('mfg.no_warehouse'));
        }

        if ($err = BatchLocation::putAway($batch, OpeningStock::pickShelf($warehouse), $qty)) {
            throw new Rejected($err);
        }
    }

    /** قفل صف الأمر والحالة من الصف المقفول (مخطط/جاري/انتهى) */
    private static function lockedOpen(ProductionOrder $o): ProductionOrder
    {
        $row = ProductionOrder::query()->whereKey($o->id)->lockForUpdate()->first();

        if ($row === null || ! $row->isOpen()) {
            throw new Rejected(__('mfg.order_bad_state'));
        }

        return $row;
    }

    /** خامات الأمر بس (D5) */
    private static function inOrder(ProductionOrder $row, Product $material): void
    {
        if (! $row->lines()->where('product_id', $material->id)->exists()) {
            throw new Rejected(__('mfg.not_in_bom'));
        }
    }

    private static function note(?string $note): ?string
    {
        $note = trim((string) $note);

        return $note === '' ? null : $note;
    }

    private static function fmt(float $v): string
    {
        return rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.') ?: '0';
    }
}
