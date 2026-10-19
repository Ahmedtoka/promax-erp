<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** نتيجة فحص سطر عند الاستلام — pass دخل باتش، fail اتسجل على مرتجع مورد */
class ReceiptInspection extends Model
{
    protected $fillable = [
        'goods_receipt_id', 'supplier_order_item_id', 'product_id', 'qty', 'result',
        'note', 'photo_path', 'batch_id', 'supplier_return_id', 'inspected_by',
    ];

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function supplierReturn(): BelongsTo
    {
        return $this->belongsTo(SupplierReturn::class);
    }
}
