<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * بند في أمر تجهيز — صنف من باتش معيّن من رف معيّن.
 * One line of a picking order: a product, from a batch, on a shelf.
 */
class PickOrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'pick_order_id', 'product_id', 'batch_id', 'location_id',
        'qty_requested', 'qty_picked', 'qty_received', 'variance_note',
    ];

    public function pickOrder(): BelongsTo
    {
        return $this->belongsTo(PickOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function locationCode(): string
    {
        return $this->location?->code ?? '—';
    }

    public function batchNo(): string
    {
        return $this->batch?->batch_no ?? '—';
    }

    public function expiresOn(): ?string
    {
        return $this->batch?->expires_on?->format('Y-m-d');
    }

    /** فيه فرق بين اللي اتجهّز واللي المندوب استلمه؟ */
    public function hasVariance(): bool
    {
        return $this->qty_received !== null
            && (int) $this->qty_received !== (int) $this->qty_picked;
    }

    // ==================== الحركة ====================

    /**
     * سحب الكمية من الرف فعلياً وتسجيلها كـ qty_picked.
     * بيتنادى من PickOrder::markReady جوه ترانزاكشن.
     */
    public function pull(int $qty): ?string
    {
        $row = $this->currentRow();

        if ($row === null) {
            return __('stock.pick_row_gone', [
                'product' => $this->product?->displayName() ?? '#'.$this->product_id,
                'location' => $this->locationCode(),
            ]);
        }

        if ($qty > $row->qty) {
            return __('stock.pick_row_short', [
                'location' => $this->locationCode(),
                'available' => $row->qty,
            ]);
        }

        if ($err = $row->take($qty)) {
            return $err;
        }

        // qty_remaining على الباتش بيتقل، و qty_issued بيزيد
        $this->batch?->decrement('qty_remaining', $qty);
        $this->batch?->increment('qty_issued', $qty);

        $this->update(['qty_picked' => $qty]);

        return null;
    }

    /** رجوع الفرق للرف اللي طلع منه */
    public function returnToShelf(int $qty): void
    {
        if ($qty <= 0) {
            return;
        }

        $row = $this->currentRow();

        if ($row === null) {
            // الصف اتمسح لأنه فضي — بنعمله من جديد
            $row = BatchLocation::create([
                'batch_id' => $this->batch_id,
                'location_id' => $this->location_id,
                'product_id' => $this->product_id,
                'qty' => 0,
            ]);
        }

        $row->give($qty);

        $this->batch?->increment('qty_remaining', $qty);
        $this->batch?->decrement('qty_issued', $qty);
    }

    private function currentRow(): ?BatchLocation
    {
        return BatchLocation::where('batch_id', $this->batch_id)
            ->where('location_id', $this->location_id)
            ->first();
    }
}
