<?php

namespace App\Services\Mfg;

use App\Exceptions\Rejected;
use App\Models\Batch;
use App\Models\Bom;
use App\Models\CostCenter;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\Setting;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Idempotency;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * أوامر الإنتاج (التصنيع ١٩/١٠، spec §3.5) — الإنشاء والبدء والانتهاء والإلغاء.
 *
 * مخطط ← جاري ← انتهى (والقفل والجودة في مهامهم).
 *
 * ⚠️ **الأمر بياخد الـBOM المعتمدة بس** (`Bom::approvedFor`) وبيصوّر سطورها
 * وقت الإنشاء — اعتماد إصدار جديد بعد كده مابيغيّرش أمر مفتوح.
 * ⚠️ `start`/`finish` بيتندهوا من الويب ومن الأبلكيشن (مهمة ٨) — مفيش منطق
 * كنترولر هنا، وبيرجّعوا الأمر من الداتابيز. نفس `idem_key` = نفس الأمر.
 */
class Orders
{
    /** إعدادات المخازن الافتراضية (D10) */
    public const SETTING_RAW = 'mfg_raw_warehouse_id';

    public const SETTING_FG = 'mfg_fg_warehouse_id';

    /**
     * أمر جديد (مخطط) + لقطة سطور الـBOM المعتمدة.
     *
     * @param  array{product_id:int|string, line_id:int|string, planned_qty:int|string, batch_no:string,
     *               planned_on:string|\DateTimeInterface, warehouse_id?:int|string|null,
     *               raw_warehouse_id?:int|string|null, notes?:?string}  $data
     */
    public static function create(array $data, User $by): ProductionOrder
    {
        $qty = (int) ($data['planned_qty'] ?? 0);
        if ($qty < 1 || (float) $data['planned_qty'] != $qty) {
            throw new Rejected(__('mfg.planned_qty_whole'));
        }

        $batchNo = trim((string) ($data['batch_no'] ?? ''));
        if ($batchNo === '') {
            throw new Rejected(__('mfg.batch_no_required'));
        }

        $line = CostCenter::query()->find((int) ($data['line_id'] ?? 0));
        if ($line === null || $line->kind !== 'line' || ! $line->active) {
            throw new Rejected(__('mfg.not_a_line'));
        }

        $fg = self::warehouse($data['warehouse_id'] ?? null, self::SETTING_FG);
        $raw = self::warehouse($data['raw_warehouse_id'] ?? null, self::SETTING_RAW);

        $notes = trim((string) ($data['notes'] ?? ''));

        return DB::transaction(function () use ($data, $by, $qty, $batchNo, $line, $fg, $raw, $notes) {
            // قفل صف المنتج = أمرين بنفس رقم التشغيلة في نفس اللحظة مايعدّوش
            $product = Product::query()->whereKey((int) ($data['product_id'] ?? 0))->lockForUpdate()->first();

            if ($product === null || $product->kind !== Product::KIND_FINISHED) {
                throw new Rejected(__('mfg.order_finished_only'));
            }

            $bom = Bom::approvedFor($product);
            if ($bom === null || ! $bom->lines()->exists()) {
                throw new Rejected(__('mfg.no_approved_bom'));
            }

            if (self::batchNoTaken($product->id, $batchNo)) {
                throw new Rejected(__('mfg.batch_no_taken'));
            }

            $order = ProductionOrder::create([
                'number' => ProductionOrder::nextNumber(),
                'product_id' => $product->id,
                'bom_id' => $bom->id,
                'line_id' => $line->id,
                'warehouse_id' => $fg->id,
                'raw_warehouse_id' => $raw->id,
                'batch_no' => $batchNo,
                'planned_qty' => $qty,
                'planned_on' => $data['planned_on'],
                'status' => 'planned',
                'created_by' => $by->id,
                'notes' => $notes === '' ? null : $notes,
            ]);

            foreach ($bom->lines()->get() as $l) {
                $order->lines()->create([
                    'product_id' => $l->product_id,
                    'basis' => $l->basis,
                    'bom_qty' => $l->qty,
                    'std_qty' => $bom->stdFor($l, $qty),
                ]);
            }

            return $order;
        });
    }

    /**
     * رقم التشغيلة مستخدم للمنتج ده؟ — في أمر تاني (غير الملغي) أو في باتش موجود.
     *
     * ⚠️ الملغي مابيحجزش الرقم — ماعملش باتش ولا هيعمل.
     */
    public static function batchNoTaken(int $productId, string $batchNo): bool
    {
        return ProductionOrder::query()->where('product_id', $productId)->where('batch_no', $batchNo)
            ->where('status', '!=', 'cancelled')->exists()
            || Batch::query()->where('product_id', $productId)->where('batch_no', $batchNo)->exists();
    }

    /** مخطط ← جاري. نفس `idem_key` تاني = نفس الأمر من غير تغيير. */
    public static function start(ProductionOrder $o, User $by, ?string $idemKey = null): ProductionOrder
    {
        return self::step($o, $by, Idempotency::MFG_START, $idemKey, function (ProductionOrder $row) use ($by) {
            if ($row->status !== 'planned') {
                throw new Rejected(__('mfg.order_bad_state'));
            }

            $row->forceFill(['status' => 'in_progress', 'started_at' => now(), 'started_by' => $by->id])->save();
        });
    }

    /**
     * جاري ← انتهى بكمية الإنتاج. الساعات = الدقايق من البدء ÷ ٦٠ (رقمين عشريين)
     * — دي اللي تكلفة الخط بتتوزع عليها (الخط ٩).
     */
    public static function finish(ProductionOrder $o, User $by, int $outputQty, ?string $idemKey = null): ProductionOrder
    {
        return self::step($o, $by, Idempotency::MFG_FINISH, $idemKey, function (ProductionOrder $row) use ($by, $outputQty) {
            if ($row->status !== 'in_progress') {
                throw new Rejected(__('mfg.order_bad_state'));
            }

            if ($outputQty < 1) {
                throw new Rejected(__('mfg.output_positive'));
            }

            $now = now();
            $hours = $row->started_at
                ? round(abs($row->started_at->diffInMinutes($now)) / 60, 2)
                : null;

            $row->forceFill([
                'status' => 'finished', 'finished_at' => $now, 'finished_by' => $by->id,
                'output_qty' => $outputQty, 'hours' => $hours,
            ])->save();
        });
    }

    /** إلغاء مخطط أو جاري — بشرط مفيش خامة لسه مصروفة عليه صافي */
    public static function cancel(ProductionOrder $o, User $by): void
    {
        DB::transaction(function () use ($o) {
            $row = self::locked($o);

            if (! in_array($row->status, ['planned', 'in_progress'], true)) {
                throw new Rejected(__('mfg.order_bad_state'));
            }

            if (collect(Requirement::issuedNet($row))->contains(fn (float $q) => $q > 0.0005)) {
                throw new Rejected(__('mfg.cancel_has_issues'));
            }

            $row->forceFill(['status' => 'cancelled'])->save();
            $o->setRawAttributes($row->getAttributes(), true);
        });
    }

    /**
     * خطوة حالة واحدة بمنع التكرار (نمط `Idempotency`):
     * `find()` الأول ← التغيير و`remember()` جوه الترانزاكشن ← اليونيك يمسك السباق.
     *
     * @param  callable(ProductionOrder): void  $change
     */
    private static function step(ProductionOrder $o, User $by, string $action, ?string $idemKey, callable $change): ProductionOrder
    {
        if ($prev = self::already($by, $action, $idemKey, $o)) {
            return $prev;
        }

        try {
            DB::transaction(function () use ($o, $by, $action, $idemKey, $change) {
                $row = self::locked($o);
                $change($row);
                Idempotency::remember($by, $action, $idemKey, $row);
            });
        } catch (QueryException $e) {
            if (! Idempotency::isDuplicate($e) || ! ($prev = self::already($by, $action, $idemKey, $o))) {
                throw $e;
            }

            return $prev;
        }

        $fresh = ProductionOrder::query()->findOrFail($o->id);
        $o->setRawAttributes($fresh->getAttributes(), true);

        return $fresh;
    }

    /** الأمر اللي المفتاح ده اتسجل عليه — ومفتاح أمر تاني مرفوض مش متجاهَل */
    private static function already(User $by, string $action, ?string $idemKey, ProductionOrder $o): ?ProductionOrder
    {
        $prev = Idempotency::find($by, $action, $idemKey, ProductionOrder::class);

        if ($prev !== null && $prev->id !== $o->id) {
            throw new Rejected(__('mfg.idem_other_order'));
        }

        return $prev;
    }

    /**
     * مخزن الأمر: المختار، وإلا إعداد التصنيع، وإلا أول مخزن مصنع نشط (D10).
     */
    private static function warehouse(mixed $picked, string $setting): Warehouse
    {
        $id = (int) ($picked ?: Setting::read($setting));

        $w = $id > 0 ? Warehouse::query()->whereKey($id)->where('active', true)->first() : null;

        if ($w === null && ! $picked) {
            $w = Warehouse::query()->where('type', Warehouse::TYPE_FACTORY)->where('active', true)->orderBy('id')->first();
        }

        if ($w === null) {
            throw new Rejected(__('mfg.no_warehouse'));
        }

        return $w;
    }

    private static function locked(ProductionOrder $o): ProductionOrder
    {
        $row = ProductionOrder::query()->whereKey($o->id)->lockForUpdate()->first();

        if ($row === null) {
            throw new Rejected(__('mfg.order_bad_state'));
        }

        return $row;
    }
}
