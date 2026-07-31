<?php

namespace App\Services;

use App\Exceptions\Rejected;
use App\Models\Batch;
use App\Models\BatchLocation;
use App\Models\Stock;
use App\Models\StockCount;
use App\Models\StockCountItem;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * الجرد — المكان الوحيد اللي بيحرّك المخزون بالعد
 * ═══════════════════════════════════════════════════════════════
 *
 * الدورة: فتح (لقطة) ← إدخال العد ← اعتماد (تحريك).
 *
 * ⚠️ **الاعتماد بس هو اللي بيحرّك.** فتح الجرد وإدخال الأرقام
 * مايلمسوش رصيد ولا باتش. لو الحركة اتعملت مع كل إدخال، أي غلطة
 * كتابة بتبوّظ المخزون فوراً ومحدش يعرف يرجّعها.
 *
 * ⚠️ الحركة كلها **جوه ترانزاكشن واحدة** مع `lockForUpdate()`. جرد
 * بيتعتمد وفاتورة بتتعمل في نفس اللحظة من غير قفل = الاتنين يقروا
 * نفس الرصيد والتاني يمسح الأول.
 */
class StockCounting
{
    /**
     * فتح جرد جديد — بيولّد ورقة العد من رصيد السيستم الحالي.
     *
     * @param  bool  $includeZero  ياخد الباتشات الفاضية كمان
     */
    public static function open(
        Warehouse $warehouse,
        User $user,
        ?string $date = null,
        bool $includeZero = false,
    ): StockCount {
        return DB::transaction(function () use ($warehouse, $user, $date, $includeZero) {
            // ⚠️ جردين مفتوحين على نفس المخزن = الاتنين بياخدوا لقطة
            // ونتيجة التاني بتمسح نتيجة الأول. الفحص **جوه** الترانزاكشن
            // وبقفل — بره الترانزاكشن ضغطتين في نفس اللحظة بيعدّوا الاتنين.
            $open = StockCount::where('warehouse_id', $warehouse->id)
                ->whereIn('status', ['draft', 'counting'])
                ->lockForUpdate()
                ->first();

            if ($open) {
                throw new Rejected(__('count.already_open', ['number' => $open->number]));
            }

            $count = StockCount::create([
                'number' => StockCount::nextNumber(),
                'warehouse_id' => $warehouse->id,
                'status' => 'counting',
                'started_by' => $user->id,
                'count_date' => $date ?: today()->toDateString(),
            ]);

            $batches = Batch::with('product')
                ->where('warehouse_id', $warehouse->id)
                ->when(! $includeZero, fn ($q) => $q->where('qty_remaining', '>', 0))
                ->orderBy('product_id')
                ->orderBy('expires_on')
                ->get();

            $rows = [];
            foreach ($batches as $batch) {
                $qty = (int) $batch->qty_remaining;

                $rows[] = [
                    'stock_count_id' => $count->id,
                    'product_id' => $batch->product_id,
                    'batch_id' => $batch->id,
                    'expected_qty' => $qty,
                    'system_qty' => $qty,
                    'counted_qty' => null,
                    'difference' => 0,
                    // ⚠️ التكلفة لقطة دلوقتي — قيمة الفرق بتتحسب بتكلفة
                    // وقت الجرد، ومابتتغيرش لو التكلفة اتعدّلت بعدين
                    'cost' => Pricing::costFor($batch->product, $batch),
                    'value_diff' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if ($rows) {
                StockCountItem::insert($rows);
            }

            $count->update(['lines' => count($rows)]);

            return $count->fresh();
        });
    }

    /**
     * حفظ الأرقام المعدودة.
     *
     * @param  array<int, array{counted: int|null, reason: ?string, notes: ?string}>  $entries
     *                                                                                          مفتاحها id السطر
     */
    public static function record(StockCount $count, array $entries): int
    {
        if (! $count->isOpen()) {
            throw new Rejected(__('count.not_open'));
        }

        $saved = 0;

        DB::transaction(function () use ($count, $entries, &$saved) {
            $items = $count->items()->get()->keyBy('id');

            foreach ($entries as $id => $entry) {
                $item = $items->get((int) $id);

                if ($item === null) {
                    continue;
                }

                $counted = $entry['counted'];

                // ⚠️ فرق بين «فاضي» و «صفر». الفاضي معناه لسه مااتعدش،
                // والصفر معناه اتعدّ ومالقيناش حاجة — وده عجز كامل.
                // خلطهم بيخلّي الصفوف اللي اتنسيت تتحسب عجز.
                $counted = ($counted === null || $counted === '')
                    ? null
                    : max(0, (int) $counted);

                $item->update([
                    'counted_qty' => $counted,
                    'difference' => $counted === null ? 0 : $counted - (int) $item->expected_qty,
                    'reason' => $entry['reason'] ?? null,
                    'notes' => $entry['notes'] ?? null,
                ]);

                $saved++;
            }
        });

        return $saved;
    }

    /**
     * الاعتماد — هنا وهنا بس المخزون بيتحرّك.
     */
    public static function approve(StockCount $count, User $user): StockCount
    {
        if (! $count->isOpen()) {
            throw new Rejected(__('count.not_open'));
        }

        $counted = $count->items()->whereNotNull('counted_qty')->count();

        if ($counted === 0) {
            throw new Rejected(__('count.nothing_counted'));
        }

        return DB::transaction(function () use ($count, $user) {
            // ⚠️ **الفحص تاني، جوه الترانزاكشن وبقفل على صف الجرد.**
            // الفحص اللي فوق على نسخة قديمة من غير قفل: ضغطتين على
            // «اعتماد» بيعدّوه الاتنين، والتانية بتلاقي الأرصدة
            // اتظبطت خلاص فبتحسب كل الفروق صفر وتمسح سجل العجز
            // كله. المخزون بيفضل صح والدليل بيضيع.
            $count = StockCount::whereKey($count->id)->lockForUpdate()->first();

            if ($count === null || ! $count->isOpen()) {
                throw new Rejected(__('count.not_open'));
            }

            $diffLines = 0;
            $qtyDiff = 0;
            $valueDiff = 0.0;
            $touched = [];
            $skipped = 0;

            $items = $count->items()
                ->whereNotNull('counted_qty')
                ->lockForUpdate()
                ->get();

            // ⚠️ ترتيب ثابت للأقفال (بالـ id) — بيمنع الديد لوك لو
            // جردين على مخزنين بيلمسوا نفس الباتشات.
            foreach ($items->sortBy('batch_id') as $item) {
                if ($item->batch_id === null) {
                    $skipped++;

                    continue;
                }

                $batch = Batch::whereKey($item->batch_id)->lockForUpdate()->first();

                // الباتش اتمسح بعد فتح الجرد — بنعدّه ونقوله لليوزر
                // بدل ما نبلعه في صمت
                if ($batch === null) {
                    $skipped++;

                    continue;
                }

                $systemQty = (int) $batch->qty_remaining;
                $countedQty = (int) $item->counted_qty;
                $difference = $countedQty - $systemQty;

                $item->update([
                    // الرصيد الحقيقي ساعة الاعتماد — لو خالف اللي وقت
                    // الفتح يبقى فيه حركة حصلت والعد شغال
                    'system_qty' => $systemQty,
                    'difference' => $difference,
                    'value_diff' => round($difference * (float) $item->cost, 2),
                ]);

                if ($difference === 0) {
                    continue;
                }

                // ⚠️ العد هو الحقيقة. بنكتب المعدود مباشرة بدل ما نجمع
                // الفرق — الجمع بيراكم أي غلط سابق في الرصيد.
                $batch->update([
                    'qty_remaining' => $countedQty,
                    // العجز بيتسجّل كتوالف عشان الفرق بين المستلم
                    // والمتبقي يفضل مفسَّر ومايبقاش رقم سايب
                    'qty_damaged' => (int) $batch->qty_damaged + max(0, -$difference),
                ]);

                // ⚠️ **الأرفف كمان.** `batches.qty_remaining` المفروض
                // يساوي مجموع `batch_locations.qty`. لو عدّلنا الباتش
                // بس، شاشة المخزن وأوامر التجهيز بيفضلوا يشوفوا
                // الكمية القديمة على الرف ويحجزوا بضاعة الجرد شطبها.
                self::resyncLocations($batch, $difference);

                $diffLines++;
                $qtyDiff += $difference;
                $valueDiff += round($difference * (float) $item->cost, 2);
                $touched[$item->product_id] = true;
            }

            // ⚠️ `stocks` تجميعة بتتحسب من الباتشات، فلازم تتعاد بعد
            // أي تعديل. من غير ده شاشة المخزون بترقم غير الباتشات
            // وحد بيلاقي رقمين مختلفين لنفس الصنف.
            // ⚠️ **بمخزن الجرد بس.** الجرد بيتعمل في مخزن واحد
            // (`stock_counts.warehouse_id`)، فإعادة حساب باقي المخازن
            // شغل زيادة — وأخطر من كده، لو باتش في مخزن تاني اتغير
            // بره الجرد، إعادة حسابه هنا بتخلّي أثره يبان كأنه نتيجة
            // اعتماد الجرد ده.
            foreach (array_keys($touched) as $productId) {
                self::resync((int) $productId, (int) $count->warehouse_id);
            }

            $count->update([
                'status' => 'approved',
                'approved_by' => $user->id,
                'approved_at' => now(),
                'diff_lines' => $diffLines,
                'qty_diff' => $qtyDiff,
                'value_diff' => round($valueDiff, 2),
            ]);

            $out = $count->fresh();

            // ⚠️ العدّاد ده بيتحط **بعد** الحفظ. `setAttribute` قبل
            // `update()` بيخلّي Eloquent يشوفه dirty ويحطه في جملة
            // الـ UPDATE — و `skipped_lines` مش عمود، فالجملة بترمي
            // «Unknown column» والاعتماد كله بيترجع.
            if ($skipped > 0) {
                $out->setAttribute('skipped_lines', $skipped);
            }

            return $out;
        });
    }

    public static function cancel(StockCount $count): void
    {
        if (! $count->isOpen()) {
            throw new Rejected(__('count.not_open'));
        }

        $count->update(['status' => 'cancelled']);
    }

    /**
     * توزيع فرق الجرد على أرفف الباتش.
     *
     * ⚠️ العجز بيتشال من **أقرب رف للانتهاء** (FEFO)، والزيادة بتتحط
     * على أول رف. من غير ده الباتش والأرفف بيختلفوا، و
     * `Warehouse::availableFor()` بيرجع كمية مش موجودة فأمر التجهيز
     * بيتقبل وبيفشل عند التنفيذ.
     */
    private static function resyncLocations(Batch $batch, int $difference): void
    {
        $rows = BatchLocation::where('batch_id', $batch->id)
            ->lockForUpdate()
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            return;   // باتش لسه مااترصّفش على رف — مفيش حاجة نزامنها
        }

        if ($difference > 0) {
            $first = $rows->first();
            $first->update(['qty' => (int) $first->qty + $difference]);

            return;
        }

        // عجز: بنشيل من الأرفف لحد ما نغطّي الفرق
        $left = -$difference;

        foreach ($rows as $row) {
            if ($left <= 0) {
                break;
            }

            $take = min($left, (int) $row->qty);
            $row->update(['qty' => (int) $row->qty - $take]);
            $left -= $take;
        }
    }

    /**
     * إعادة حساب رصيد الصنف من باتشاته — **لكل مخزن على حدة**.
     *
     * ⚠️ الباتشات هي المصدر، و `stocks` تجميعة. لو الاتنين اتكتبوا
     * كل واحد لوحده هيختلفوا، ومحدش هيعرف مين الصح.
     *
     * ⚠️ **كانت بتكتب صف واحد للصنف من غير مخزن.** بعد ما المخزون
     * بقى صف لكل (صنف، مخزن) كانت بتعمل حاجة من اتنين: تلاقي أول صف
     * بالصدفة وتكتب فيه إجمالي الشركة كلها — فرصيد العاشر يتحط على
     * المعادي والإجمالي يتضاعف؛ أو ماتلاقيش صف وتحاول تعمل واحد من
     * غير `warehouse_id` فيرمي SQLSTATE 1364 وترانزاكشن اعتماد الجرد
     * كلها ترجع. الاتنين حصلوا في نفس اليوم اللي المايجريشن نزل فيه.
     *
     * @param  int|null  $warehouseId  مخزن واحد، أو null لكل المخازن
     *                                 اللي فيها باتشات للصنف ده
     */
    public static function resync(int $productId, ?int $warehouseId = null): void
    {
        // ⚠️ **المخازن اللي فيها صف رصيد كمان، مش الباتشات بس.** لو
        // آخر باتش في مخزن خلص، مافيش باتشات هناك خالص — ولو مشيناها
        // على الباتشات بس، صف الرصيد القديم بيفضل بكميته وبيقول إن
        // المخزن مليان وهو فاضي.
        $ids = $warehouseId !== null
            ? [$warehouseId]
            : Batch::where('product_id', $productId)->distinct()->pluck('warehouse_id')
                ->merge(Stock::where('product_id', $productId)->distinct()->pluck('warehouse_id'))
                ->filter()->unique()->all();

        foreach ($ids as $id) {
            $rows = Batch::where('product_id', $productId)->where('warehouse_id', $id);

            $total = (int) (clone $rows)->sum('qty_remaining');
            $blocked = (int) (clone $rows)->where('blocked', true)->sum('qty_remaining');

            Stock::updateOrCreate(
                ['product_id' => $productId, 'warehouse_id' => $id],
                [
                    'qty' => $total,
                    'hold_qty' => $blocked,
                    'good_qty' => $total - $blocked,
                    'counted_at' => today(),
                ],
            );
        }
    }
}
