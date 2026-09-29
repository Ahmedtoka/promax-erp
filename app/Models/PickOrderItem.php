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
        'qty_requested', 'qty_picked', 'qty_received', 'gift_qty', 'variance_note',
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

        if ($row !== null && $qty <= (int) $row->qty) {
            return $this->pullFrom($row, $qty);
        }

        return $this->pullReplanned($qty, $row);
    }

    /** السحب الفعلي من صف واحد — الرف بيقل والباتش بيقل وبيتسجل المسحوب */
    private function pullFrom(BatchLocation $row, int $qty): ?string
    {
        if ($err = $row->take($qty)) {
            return $err;
        }

        // qty_remaining على الباتش بيتقل، و qty_issued بيزيد
        Batch::whereKey($row->batch_id)->decrement('qty_remaining', $qty);
        Batch::whereKey($row->batch_id)->increment('qty_issued', $qty);

        $this->update(['qty_picked' => $qty]);

        return null;
    }

    /**
     * ═══ الرف المخطط فضي أو ناقص — خد من المتاح بالـFEFO (٢٩/٩) ═══
     *
     * بلاغ المالك: «أوردر الأونلاين بيقول البضاعة مش موجودة وهي موجودة».
     * أمر التجهيز بيخطط باتش ورف وقت رفعه **من غير حجز**، فأمر تاني
     * (أمر توريد) خطط على نفس الصف وسحبه الأول — وأمرنا لقى الصف فاضي
     * والصنف موجود في باتش أو رف تاني.
     *
     * الترتيب: الرف المخطط الأول (أقل تغيير)، وبعده باقي المتاح بالـFEFO.
     * لو صف واحد يكفي البند كله → البند بيتنقل عليه (والهدية معاه زي ما
     * هي). غير كده بيتقسم: أول نصيب على البند نفسه وكل نصيب تاني بند جديد
     * بنفس الصنف — فالباتش المسجل هو اللي البضاعة خرجت منه فعلاً (التكلفة
     * والصلاحية والمرتجع لنفس الرف).
     *
     * لو المتاح كله مايكفيش → رفض برسالة «مش كفاية، المتاح X» ومفيش حاجة
     * بتتحرك (الترانزاكشن في `markReady` بترجع كله).
     */
    private function pullReplanned(int $qty, ?BatchLocation $planned): ?string
    {
        $warehouse = $this->pickOrder->warehouse;

        $others = PickOrder::sellableRows($warehouse, (int) $this->product_id)
            ->lockForUpdate()->get()
            ->reject(fn ($r) => $planned !== null && (int) $r->id === (int) $planned->id)
            ->filter(fn ($r) => (int) $r->qty > 0);

        $rows = collect($planned !== null && (int) $planned->qty > 0 ? [$planned] : [])->concat($others)->values();
        $available = (int) $rows->sum('qty');

        if ($available < $qty) {
            return __('stock.pick_not_enough', [
                'product' => $this->product?->displayName() ?? '#'.$this->product_id,
                'available' => $available,
            ]);
        }

        // صف واحد يكفي؟ البند كله يتنقل عليه
        $single = $rows->first(fn ($r) => (int) $r->qty >= $qty);

        if ($single !== null) {
            $this->repoint($single);

            return $this->pullFrom($single, $qty);
        }

        // مفيش صف لوحده يكفي — تقسيم بالـFEFO
        $left = $qty;
        $first = true;

        foreach ($rows as $r) {
            if ($left <= 0) {
                break;
            }

            $take = min($left, (int) $r->qty);

            if ($first) {
                $this->repoint($r);
                $this->update(['qty_requested' => $take]);
                $target = $this;
                $first = false;
            } else {
                $target = static::create([
                    'pick_order_id' => $this->pick_order_id,
                    'product_id' => $this->product_id,
                    'batch_id' => $r->batch_id,
                    'location_id' => $r->location_id,
                    'qty_requested' => $take,
                    'qty_picked' => 0,
                    'gift_qty' => 0,
                ]);
            }

            if ($err = $target->pullFrom($r, $take)) {
                return $err;
            }

            $left -= $take;
        }

        return null;
    }

    /** البند بقى على باتش/رف تاني — والعلاقات القديمة المحمّلة تتشال */
    private function repoint(BatchLocation $row): void
    {
        if ((int) $row->batch_id === (int) $this->batch_id && (int) $row->location_id === (int) $this->location_id) {
            return;
        }

        $this->update(['batch_id' => $row->batch_id, 'location_id' => $row->location_id]);
        $this->unsetRelation('batch');
        $this->unsetRelation('location');
    }

    /** رجوع الفرق للرف اللي طلع منه */
    public function returnToShelf(int $qty): void
    {
        if ($qty <= 0) {
            return;
        }

        $row = $this->currentRow();

        if ($row === null) {
            // ⚠️ **الرف الأصلي ممكن يكون اتمسح من السيستم خالص**
            // (`pick_order_items.location_id` عليها `nullOnDelete` —
            // إعادة تنظيم الأرفف ٥/٩ مسحت M01/Q01/Y01 وخلّت البنود
            // القديمة location_id = null). الإنشاء بـnull كان بيرمي
            // 1048 على اللايف (٦/٩) — بنرصّف على رف السحب بدل ما نقع.
            $location = $this->location_id
                ?? \App\Services\OpeningStock::pickShelf(
                    $this->batch?->warehouse
                        ?? $this->pickOrder->warehouse,
                )->id;

            // الصف اتمسح لأنه فضي — بنعمله من جديد
            $row = BatchLocation::create([
                'batch_id' => $this->batch_id,
                'location_id' => $location,
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
        // رف البند اتمسح؟ يبقى مفيش صف حالي أصلاً — و`= null` في
        // SQL عمرها ما بتطابق، فالشرط الصريح أوضح وأأمن
        if ($this->location_id === null) {
            return null;
        }

        return BatchLocation::where('batch_id', $this->batch_id)
            ->where('location_id', $this->location_id)
            ->first();
    }
}
