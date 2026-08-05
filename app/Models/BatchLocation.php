<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * رصيد باتش معيّن على رف معيّن.
 * Quantity of one batch sitting on one shelf location.
 *
 * ⚠️ ده مصدر الحقيقة للكمية الموجودة فعلياً في المخزن.
 * ممنوع تعدّل qty يدوي — استخدم:
 *     BatchLocation::putAway()  الترصيف بعد الاستلام
 *     BatchLocation::moveTo()   نقل من رف لرف
 *     $row->take($qty)          السحب عند التجهيز
 */
class BatchLocation extends Model
{
    use HasFactory;

    protected $fillable = ['batch_id', 'location_id', 'product_id', 'qty'];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    // ==================== الحركة ====================

    /**
     * ترصيف كمية من باتش على رف. بتزوّد لو الباتش موجود على الرف ده بالفعل.
     * بترجع رسالة خطأ أو null.
     */
    public static function putAway(Batch $batch, Location $location, int $qty): ?string
    {
        if ($qty <= 0) {
            return __('stock.qty_must_be_positive');
        }

        if ($location->warehouse_id !== $batch->warehouse_id) {
            return __('stock.location_other_warehouse');
        }

        // ⚠️ بلوكات FEFO (2026-08-06): **منع نهائي** — الباتش بيترصّف
        // في البلوك المطابق لعمره المتبقي بس، والرسالة بتقول البلوك الصح.
        if ($err = \App\Support\LifeBands::guard($location, $batch)) {
            return $err;
        }

        // ممنوع نرصّف أكتر من اللي مستلم فعلاً في الباتش
        $unshelved = $batch->qty_remaining - $batch->shelvedQty();
        if ($qty > $unshelved) {
            return __('stock.putaway_exceeds_batch', [
                'batch' => $batch->batch_no,
                'available' => max($unshelved, 0),
            ]);
        }

        $free = $location->freeCapacity();
        if ($free !== null && $qty > $free) {
            return __('stock.location_full', ['location' => $location->code, 'free' => $free]);
        }

        $row = static::firstOrNew([
            'batch_id' => $batch->id,
            'location_id' => $location->id,
        ]);
        $row->product_id = $batch->product_id;
        $row->qty = (int) $row->qty + $qty;
        $row->save();

        return null;
    }

    /** نقل كمية من الرف ده لرف تاني */
    public function moveTo(Location $target, int $qty): ?string
    {
        if ($qty <= 0 || $qty > $this->qty) {
            return __('stock.move_exceeds_location', ['available' => $this->qty]);
        }
        if ($target->id === $this->location_id) {
            return __('stock.move_same_location');
        }
        if ($target->warehouse_id !== $this->location->warehouse_id) {
            return __('stock.location_other_warehouse');
        }

        // ⚠️ نفس حارس البلوكات — النقل لبلوك النطاق الصح بس (وده اللي
        // بيخلي «إعادة التوزين» تنقل البضاعة اللي كبرت لنطاق أقل وتقف)
        if ($this->batch && ($err = \App\Support\LifeBands::guard($target, $this->batch))) {
            return $err;
        }

        $free = $target->freeCapacity();
        if ($free !== null && $qty > $free) {
            return __('stock.location_full', ['location' => $target->code, 'free' => $free]);
        }

        $this->decrement('qty', $qty);

        $row = static::firstOrNew([
            'batch_id' => $this->batch_id,
            'location_id' => $target->id,
        ]);
        $row->product_id = $this->product_id;
        $row->qty = (int) $row->qty + $qty;
        $row->save();

        $this->pruneIfEmpty();

        return null;
    }

    /** سحب كمية من الرف — بيتنادى عند التجهيز */
    public function take(int $qty): ?string
    {
        if ($qty <= 0 || $qty > $this->qty) {
            return __('stock.move_exceeds_location', ['available' => $this->qty]);
        }

        $this->decrement('qty', $qty);
        $this->pruneIfEmpty();

        return null;
    }

    /** رجوع كمية للرف (إلغاء تجهيز مثلاً) */
    public function give(int $qty): void
    {
        if ($qty > 0) {
            $this->increment('qty', $qty);
        }
    }

    private function pruneIfEmpty(): void
    {
        if ($this->fresh()?->qty <= 0) {
            $this->delete();
        }
    }

    // ==================== سكوبات ====================

    /** المتاح للبيع بس — بيستبعد المنتهي والموقوف */
    public function scopeSellable(Builder $q): Builder
    {
        // مؤهّل بالجدول عشان scopeFefo بيعمل join على batches و locations
        return $q->where('batch_locations.qty', '>', 0)
            ->whereHas('batch', fn ($b) => $b->sellable());
    }

    public function scopeInWarehouse(Builder $q, int $warehouseId): Builder
    {
        return $q->whereHas('location', fn ($l) => $l->where('warehouse_id', $warehouseId));
    }

    /**
     * ترتيب الـ FEFO: الأقرب انتهاءً الأول، وبعدين رف السحب (pick face).
     * ده الترتيب اللي أمر التجهيز بيمشي بيه.
     */
    public function scopeFefo(Builder $q): Builder
    {
        return $q->join('batches', 'batches.id', '=', 'batch_locations.batch_id')
            ->join('locations', 'locations.id', '=', 'batch_locations.location_id')
            ->orderBy('batches.expires_on')
            ->orderByDesc('locations.is_pick_face')
            ->orderBy('locations.stand')
            ->orderBy('locations.level')
            ->select('batch_locations.*');
    }
}
