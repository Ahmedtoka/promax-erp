<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * بند في أمر تحويل — صنف بباتشه وتاريخ إنتاجه.
 */
class StockTransferItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'stock_transfer_id', 'product_id', 'source_batch_id', 'batch_no',
        'produced_on', 'expires_on', 'qty_sent', 'qty_received', 'qty_short', 'cost', 'notes',
        // التحويل الميداني (١٤/٨): بند العهدة الأصلي ومصدر بضاعته
        // **مجمّد** لحظة التحويل — البند ممكن يتغيّر بعدها، والورقة لأ
        'custody_item_id', 'source', 'source_ref_id',
    ];

    protected function casts(): array
    {
        return [
            'produced_on' => 'date',
            'expires_on' => 'date',
            'cost' => 'decimal:2',
        ];
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * الباتش اللي البضاعة طلعت منه في المخزن المرسل.
     *
     * ⚠️ **مش الباتش اللي في المخزن المستقبِل.** نفس رقم التشغيلة
     * بيتعمل له صف مستقل في كل مخزن، فالاتنين ليهم `id` مختلف —
     * وخلطهم بيخلّي العجز يترد على المخزن الغلط.
     */
    public function sourceBatch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'source_batch_id');
    }

    public function custodyItem(): BelongsTo
    {
        return $this->belongsTo(CustodyItem::class);
    }

    /** «عهدة عادية» / «بضاعة أمر توريد» / «جت بتحويل» — للتحويل الميداني */
    public function sourceLabel(): ?string
    {
        if ($this->source === null) {
            return null;
        }

        $key = array_key_exists((string) $this->source, CustodyItem::SOURCES)
            ? (string) $this->source
            : 'legacy';

        return __('stock.src_'.$key);
    }

    public function sourceClass(): string
    {
        return CustodyItem::SOURCES[(string) $this->source] ?? 'b-gray';
    }

    /** رقم أمر التوريد أو التحويل اللي البضاعة جت منه */
    public function sourceRefLabel(): ?string
    {
        $ref = (int) $this->source_ref_id;

        if ($ref <= 0) {
            return null;
        }

        return match ((string) $this->source) {
            'purchase_order' => PurchaseOrder::find($ref)?->number,
            'transfer' => StockTransfer::find($ref)?->number,
            default => null,
        };
    }

    public function hasVariance(): bool
    {
        return $this->qty_received !== null
            && (int) $this->qty_received !== (int) $this->qty_sent;
    }

    public function variance(): int
    {
        return (int) ($this->qty_received ?? $this->qty_sent) - (int) $this->qty_sent;
    }
}
