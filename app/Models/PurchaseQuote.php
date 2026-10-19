<?php

namespace App\Models;

use App\Exceptions\Rejected;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * عرض سعر من مورد على طلب شراء. اعتماده (أدمن بس) بيعمل أمر شراء
 * بسعر العرض — القبول ده هو الموافقة، فالأمر مابيعدّيش على
 * `PurchaseApproval` تاني.
 */
class PurchaseQuote extends Model
{
    protected $fillable = [
        'purchase_request_id', 'supplier_id', 'unit_price', 'lead_days',
        'payment_terms', 'attachment_path', 'notes', 'status', 'created_by',
    ];

    protected function casts(): array
    {
        return ['unit_price' => 'decimal:2', 'lead_days' => 'integer'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class, 'purchase_request_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * يعتمد العرض ويفتح أمر الشراء — معاملة واحدة وقفل على صف الطلب
     * (دبل كليك = أمر واحد).
     */
    public function approve(User $by, int $warehouseId): SupplierOrder
    {
        return DB::transaction(function () use ($by, $warehouseId) {
            $request = PurchaseRequest::whereKey($this->purchase_request_id)->lockForUpdate()->firstOrFail();

            if (! $request->isActive()) {
                throw new Rejected(__('supplier.request_already_ordered'));
            }

            $quote = static::whereKey($this->id)->firstOrFail();
            $qty = (int) $request->qty;
            $price = round((float) $quote->unit_price, 2);
            $today = now()->startOfDay();

            $order = SupplierOrder::create([
                'number' => SupplierOrder::nextNumber(),
                'supplier_id' => $quote->supplier_id,
                'warehouse_id' => $warehouseId,
                'status' => 'open',
                'ordered_on' => $today->toDateString(),
                'expected_on' => $quote->lead_days !== null ? $today->copy()->addDays($quote->lead_days)->toDateString() : null,
                'total' => round($qty * $price, 2),
                'notes' => $request->notes,
                'created_by' => $by->id,
                'approval_status' => 'approved',
                'approval_reasons' => null,
                'approved_by' => $by->id,
                'approved_at' => now(),
                'purchase_request_id' => $request->id,
                'purchase_quote_id' => $quote->id,
                'cost_center_id' => $request->cost_center_id,
            ]);

            $order->items()->create([
                'product_id' => $request->product_id,
                'qty' => $qty,
                'unit_cost' => $price,
                'received_qty' => 0,
            ]);

            static::where('purchase_request_id', $request->id)->where('id', '!=', $quote->id)
                ->update(['status' => 'rejected']);
            $quote->update(['status' => 'approved']);

            $request->update([
                'status' => 'ordered',
                'supplier_order_id' => $order->id,
                'approved_by' => $by->id,
                'approved_at' => now(),
            ]);

            $this->refresh();

            return $order;
        });
    }
}
