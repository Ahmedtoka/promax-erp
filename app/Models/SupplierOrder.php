<?php

namespace App\Models;

use App\Exceptions\Rejected;
use App\Services\StockCounting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * أمر شراء لمورد — SPO
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **ده مش `PurchaseOrder`.** الاسمين متشابهين والاتجاه معكوس:
 * `PurchaseOrder` طلبية من عميل بتخرج بضاعة، و`SupplierOrder` طلبية
 * لمورد بتدخل بضاعة. الخلط بينهم هو أخطر غلطة في القسم ده.
 *
 * الدورة: open → (استلامات) → received → closed، أو cancelled.
 */
class SupplierOrder extends Model
{
    protected $fillable = [
        'number', 'supplier_id', 'warehouse_id', 'status',
        'ordered_on', 'expected_on', 'total', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'ordered_on' => 'date',
            'expected_on' => 'date',
            'total' => 'decimal:2',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SupplierOrderItem::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(SupplierInvoice::class);
    }

    // ═══════════════════════════════════════════════════════════

    public static function nextNumber(): string
    {
        $last = static::query()->orderByDesc('id')->value('number');
        $n = $last ? ((int) preg_replace('/\D+/', '', $last)) + 1 : 1001;

        return 'SPO-'.$n;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['open', 'received'], true);
    }

    /** المتبقي استلامه لكل صنف */
    public function outstanding(): array
    {
        return $this->items
            ->mapWithKeys(fn (SupplierOrderItem $i) => [
                $i->product_id => max(0, $i->qty - $i->received_qty),
            ])
            ->filter(fn ($q) => $q > 0)
            ->all();
    }

    /**
     * استلام بضاعة على الأمر — بيعمل إذن استلام حقيقي وباتشات.
     *
     * ⚠️ **الاستلام الجزئي مسموح ومقصود** — المورد بيبعت على دفعات.
     * كل استلام بيزوّد `received_qty`، ولما كل الأصناف تكتمل الأمر
     * بيبقى `received`.
     *
     * ⚠️ **نفس مسار المخزن الرسمي**: `GoodsReceipt` + `Batch` +
     * `resync`. لو كتبنا في `stocks` مباشرة، الباتشات مش هتعرف عن
     * البضاعة دي حاجة وأول جرد أو أمر تجهيز FEFO هيقع.
     *
     * @param  array<int, array{qty: int, batch_no: string, produced_on: ?string, expires_on: ?string}>  $lines  مفتاحها product_id
     */
    public function receive(array $lines, User $by): GoodsReceipt
    {
        return DB::transaction(function () use ($lines, $by) {
            // ⚠️ قفل الأمر — استلامين في نفس اللحظة كانوا هيعدّوا
            // الكمية مرتين على نفس الصنف
            $order = static::whereKey($this->id)->lockForUpdate()->first();

            if (! $order->isOpen()) {
                throw new Rejected(__('supplier.order_not_open'));
            }

            $itemsByProduct = $order->items()->get()->keyBy('product_id');

            $receipt = GoodsReceipt::create([
                'number' => GoodsReceipt::nextNumber(),
                'warehouse_id' => $order->warehouse_id,
                'supplier_id' => $order->supplier_id,
                'supplier_order_id' => $order->id,
                'received_on' => today(),
                'status' => 'posted',
                'supplier' => $order->supplier->displayName(),
                'reference' => $order->number,
                'created_by' => $by->id,
            ]);

            foreach ($lines as $productId => $line) {
                $qty = (int) ($line['qty'] ?? 0);

                if ($qty <= 0) {
                    continue;
                }

                /** @var ?SupplierOrderItem $item */
                $item = $itemsByProduct->get((int) $productId);

                if ($item === null) {
                    throw new Rejected(__('supplier.product_not_on_order'));
                }

                // ⚠️ **مافيش استلام أكتر من المطلوب.** الزيادة غالباً
                // غلطة كتابة — ولو المورد فعلاً بعت زيادة، زوّد الكمية
                // في الأمر الأول عشان الفاتورة والاستلام يتطابقوا.
                if ($item->received_qty + $qty > $item->qty) {
                    throw new Rejected(__('supplier.over_receipt', [
                        'product' => $item->product->displayName(),
                        'left' => $item->qty - $item->received_qty,
                    ]));
                }

                $product = $item->product;

                $batch = Batch::firstOrNew([
                    'product_id' => $product->id,
                    'batch_no' => trim((string) ($line['batch_no'] ?? '')) ?: $receipt->number,
                    'warehouse_id' => $order->warehouse_id,
                ]);

                $produced = $line['produced_on'] ?? null;

                $batch->fill([
                    'goods_receipt_id' => $receipt->id,
                    'produced_on' => $produced,
                    // ⚠️ `expires_on` NOT NULL — بتتحسب لو ماتكتبتش
                    'expires_on' => ($line['expires_on'] ?? null)
                        ?: ($produced ? $product->expiryFrom($produced)->toDateString() : null)
                        ?: now()->addMonths($product->shelfLife())->toDateString(),
                    // ⚠️ تكلفة الباتش من سعر الشراء الفعلي — الربحية
                    // بتتحسب منها، وسعر الأمر هو الحقيقة مش القياسي.
                    'cost' => (float) $item->unit_cost,
                ]);
                $batch->qty_received = (int) $batch->qty_received + $qty;
                $batch->qty_remaining = (int) $batch->qty_remaining + $qty;
                $batch->save();

                $item->increment('received_qty', $qty);

                StockCounting::resync($product->id, $order->warehouse_id);
            }

            // اكتمل؟
            $left = $order->items()->get()
                ->sum(fn (SupplierOrderItem $i) => max(0, $i->qty - $i->received_qty));

            if ($left === 0) {
                $order->update(['status' => 'received']);
            }

            return $receipt;
        });
    }

    public function statusClass(): string
    {
        return match ($this->status) {
            'open' => 'b-blue',
            'received' => 'b-green',
            'closed' => 'b-gray',
            'cancelled' => 'b-red',
            default => 'b-gray',
        };
    }

    public function statusLabel(): string
    {
        return __('supplier.status_'.$this->status);
    }
}
