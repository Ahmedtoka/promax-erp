<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustodyItem extends Model
{
    use HasFactory;

    /**
     * مصدر البضاعة اللي في البند ده (١٤ أغسطس ٢٠٢٦).
     *
     * ⚠️ **`legacy` مش `custody`.** الصفوف اللي اتكتبت قبل المايجريشن
     * مصدرها مش متسجّل — وتسميتها «عهدة عادية» كذب على المستخدم.
     * الشاشات بتوريها «مصدر غير محدد — بضاعة قديمة».
     *
     * الليبل من `lang/{ar,en}/stock.php` بمفتاح `src_<value>`.
     *
     * @var array<string, string>
     */
    public const SOURCES = [
        'custody' => 'b-blue',
        'purchase_order' => 'b-purple',
        'transfer' => 'b-orange',
        'legacy' => 'b-gray',
    ];

    // ⚠️ `returned` = مرتجع المندوب **للمخزن**، و`returned_in` = مرتجع
    // **من العملاء** جوه العربية — اتنين مختلفين ومحدش يبيع من التاني
    protected $fillable = ['custody_id', 'product_id', 'batch_id', 'assigned', 'sold', 'returned',
        // ⚠️ `damaged_in` = مرتجع عملاء **تالف** — بضاعة مش قابلة
        // للبيع، بتتسلّم للمخزن لوحدها وقت التصفية
        'returned_in', 'damaged_in', 'gift_assigned', 'gift_given',
        // ⚠️ `transferred_out` = اتحوّل لمندوب **تاني** — مش مباع ومش
        // مرجّع للمخزن، وله حدّه المستقل في معادلة التصفية
        'transferred_out', 'source', 'source_ref_id'];

    public function custody(): BelongsTo
    {
        return $this->belongsTo(Custody::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function remaining(): int
    {
        return $this->assigned - $this->sold - $this->returned - (int) $this->transferred_out;
    }

    /** حالة صلاحية البند — البنود القديمة من غير باتش بتعتبر ok */
    public function expiryState(): string
    {
        return $this->batch?->expiryState() ?? 'ok';
    }

    public function batchLabel(): string
    {
        return $this->batch?->batch_no ?? '—';
    }

    // ==================== المصدر ====================

    public function sourceKey(): string
    {
        $source = (string) ($this->source ?: 'legacy');

        return array_key_exists($source, self::SOURCES) ? $source : 'legacy';
    }

    public function sourceLabel(): string
    {
        return __('stock.src_'.$this->sourceKey());
    }

    public function sourceClass(): string
    {
        return self::SOURCES[$this->sourceKey()];
    }

    /**
     * «بضاعة أمر التوريد PO-1042» / «جت بتحويل TRF-1007» — النص اللي
     * المالك طلبه بالنص: أعرف القطع دي بتاعة أنهي مصدر بالظبط.
     */
    public function sourceRefLabel(): ?string
    {
        $ref = (int) $this->source_ref_id;

        if ($ref <= 0) {
            return null;
        }

        return match ($this->sourceKey()) {
            'purchase_order' => PurchaseOrder::find($ref)?->number,
            'transfer' => StockTransfer::find($ref)?->number,
            default => null,
        };
    }

    /**
     * الهدايا اللي لسه في العربية.
     *
     * ⚠️ **الرقم ده لازم يوصل صفر قبل قفل العهدة.** الهدية اللي
     * مااتوزّعتش ومارجعتش المخزن هي بضاعة ضايعة مسجّلة كأنها
     * اتصرفت تسويق.
     */
    public function giftLeft(): int
    {
        return max((int) $this->gift_assigned - (int) $this->gift_given, 0);
    }
}
