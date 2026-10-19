<?php

namespace App\Services\Mfg;

use App\Exceptions\Rejected;
use App\Models\Bom;
use App\Models\BomLine;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * قوايم المواد (BOM) — إصدارات واعتماد (التصنيع ١٩/١٠، spec §3.5).
 *
 * مسودة ← معتمدة (والمعتمدة القديمة تتأرشف). الأوامر بتاخد `Bom::approvedFor()` بس.
 *
 * ⚠️ **المعتمدة ماتتعدلش.** أي تعديل = `newVersion()` منسوخة منها، والإنتاج
 * بيفضل على المعتمدة القديمة لحد ما الجديدة تتعتمد (الأدمن بس، D1).
 * ⚠️ الرأس منتج تام بس، والسطور خامة أو تعبئة نشطة بس.
 */
class Boms
{
    /** أنواع الأصناف اللي تنفع سطر في القايمة */
    public const LINE_KINDS = ['raw', 'packaging'];

    /**
     * إصدار جديد (مسودة) — رقمه = أكبر إصدار + 1، والسطور منسوخة من
     * `$copyFrom` (أو من آخر إصدار للمنتج لو مااتحددش).
     *
     * ⚠️ مسودة واحدة مفتوحة لكل منتج — اتنين بيخلّوا الاعتماد يختار بالصدفة.
     */
    public static function newVersion(Product $p, User $by, ?Bom $copyFrom = null): Bom
    {
        return DB::transaction(function () use ($p, $by, $copyFrom) {
            // قفل صف المنتج = إصدارين في نفس اللحظة مايطلعوش بنفس الرقم
            $product = Product::query()->whereKey($p->id)->lockForUpdate()->first();

            if ($product === null || $product->kind !== Product::KIND_FINISHED) {
                throw new Rejected(__('mfg.bom_finished_only'));
            }

            if ($copyFrom !== null && $copyFrom->product_id !== $product->id) {
                throw new Rejected(__('mfg.bom_copy_other_product'));
            }

            if (Bom::query()->where('product_id', $product->id)->where('status', 'draft')->exists()) {
                throw new Rejected(__('mfg.bom_open_exists'));
            }

            $copyFrom ??= Bom::query()->where('product_id', $product->id)->orderByDesc('version')->first();

            $version = (int) Bom::query()->where('product_id', $product->id)->max('version') + 1;

            $bom = Bom::create([
                'product_id' => $product->id,
                'version' => $version,
                'status' => 'draft',
                'waste_pct' => $copyFrom?->waste_pct ?? 0,
                'notes' => $copyFrom?->notes,
                'created_by' => $by->id,
            ]);

            foreach ($copyFrom?->lines()->get() ?? [] as $l) {
                $bom->lines()->create($l->only(['product_id', 'qty', 'basis', 'sort']));
            }

            return $bom;
        });
    }

    /**
     * حفظ سطور المسودة ونسبة الهالك والملاحظات — بيستبدل السطور كلها.
     *
     * @param  list<array{product_id:int|string, qty:float|string, basis:string}>  $lines
     */
    public static function saveLines(Bom $b, array $lines, float $wastePct, ?string $notes): void
    {
        if ($wastePct < 0 || $wastePct > 100) {
            throw new Rejected(__('mfg.bom_waste_range'));
        }

        $clean = [];
        $seen = [];

        foreach (array_values($lines) as $l) {
            $pid = (int) ($l['product_id'] ?? 0);
            $qty = (float) ($l['qty'] ?? 0);
            $basis = (string) ($l['basis'] ?? '');

            if ($qty <= 0) {
                throw new Rejected(__('mfg.bom_qty_positive'));
            }

            if (! in_array($basis, BomLine::BASES, true)) {
                throw new Rejected(__('mfg.bom_bad_basis'));
            }

            if (isset($seen[$pid])) {
                throw new Rejected(__('mfg.bom_line_duplicate'));
            }
            $seen[$pid] = true;

            $clean[] = ['product_id' => $pid, 'qty' => round($qty, 4), 'basis' => $basis];
        }

        $materials = Product::query()->whereIn('id', array_keys($seen))
            ->whereIn('kind', self::LINE_KINDS)->where('active', true)->pluck('id')->all();

        if (count($materials) !== count($seen)) {
            throw new Rejected(__('mfg.bom_line_not_material'));
        }

        DB::transaction(function () use ($b, $clean, $wastePct, $notes) {
            $row = self::locked($b);

            if ($row->status !== 'draft') {
                throw new Rejected(__('mfg.bom_bad_state'));
            }

            $row->lines()->delete();

            foreach ($clean as $i => $l) {
                $row->lines()->create($l + ['sort' => $i + 1]);
            }

            $notes = trim((string) $notes);
            $row->forceFill(['waste_pct' => round($wastePct, 2), 'notes' => $notes === '' ? null : $notes]);
            // تعديل السطور لوحده برضه بيحرّك `updated_at` (فلتر القايمة عليه)
            $row->updated_at = now();
            $row->save();
            $b->setRawAttributes($row->getAttributes(), true);
            $b->unsetRelation('lines');
        });
    }

    /** مسودة فيها سطر واحد على الأقل ← معتمدة، والمعتمدة السابقة ← أرشيف */
    public static function approve(Bom $b, User $by): void
    {
        DB::transaction(function () use ($b, $by) {
            $row = self::locked($b);

            if ($row->status !== 'draft') {
                throw new Rejected(__('mfg.bom_bad_state'));
            }

            if (! $row->lines()->exists()) {
                throw new Rejected(__('mfg.bom_empty'));
            }

            // ⚠️ إعادة فحص وقت الاعتماد: خامة اتوقفت أو اتغيّر نوعها بعد حفظ
            // السطور، أو رأس اتغيّر نوعه — ماتتعتمدش (مراجعة مهمة ٣)
            $head = Product::query()->whereKey($row->product_id)->lockForUpdate()->first();
            if ($head === null || $head->kind !== Product::KIND_FINISHED) {
                throw new Rejected(__('mfg.bom_finished_only'));
            }

            $ids = $row->lines()->pluck('product_id')->unique()->all();
            $ok = Product::query()->whereIn('id', $ids)
                ->whereIn('kind', self::LINE_KINDS)->where('active', true)->lockForUpdate()->count();
            if ($ok !== count($ids)) {
                throw new Rejected(__('mfg.bom_line_not_material'));
            }

            Bom::query()->where('product_id', $row->product_id)
                ->where('status', 'approved')->whereKeyNot($row->id)
                ->lockForUpdate()->get()
                ->each(fn (Bom $old) => $old->forceFill(['status' => 'archived'])->save());

            $row->forceFill([
                'status' => 'approved', 'approved_by' => $by->id, 'approved_at' => now(),
            ])->save();
            $b->setRawAttributes($row->getAttributes(), true);
        });
    }

    private static function locked(Bom $b): Bom
    {
        $row = Bom::query()->whereKey($b->id)->lockForUpdate()->first();

        if ($row === null) {
            throw new Rejected(__('mfg.bom_bad_state'));
        }

        return $row;
    }
}
