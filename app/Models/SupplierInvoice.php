<?php

namespace App\Models;

use App\Models\Concerns\HasDocumentNumber;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * فاتورة مورد — مستحق علينا.
 *
 * ⚠️ **الفاتورة بتتسجل بقيدها في ترانزاكشن واحدة** عبر `record()`.
 * فاتورة من غير قيد = مستحق مش بيبان في رصيد المورد، والمحاسب يدفع
 * من كشف ناقص.
 */
class SupplierInvoice extends Model
{
    use HasDocumentNumber;

    protected $fillable = [
        'number', 'supplier_id', 'supplier_order_id', 'supplier_ref',
        'invoice_date', 'due_on', 'subtotal', 'tax', 'total', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'due_on' => 'date',
            'subtotal' => 'decimal:2',
            'tax' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SupplierOrder::class, 'supplier_order_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function nextNumber(): string
    {
        // ⚠️ أكبر رقم مش آخر صف — شوف `HasDocumentNumber`
        return static::nextDocumentNumber('SINV-', 1001);
    }

    /** فاتورة + قيدها الدائن — البوابة الوحيدة للإنشاء */
    public static function record(array $attributes): self
    {
        return DB::transaction(function () use ($attributes) {
            $invoice = static::create($attributes + ['number' => static::nextNumber()]);

            $invoice->supplier->post(
                'invoice',
                $invoice->invoice_date->toDateString(),
                0,
                (float) $invoice->total,
                __('supplier.txn_invoice_memo', ['number' => $invoice->number]),
                $invoice,
            );

            return $invoice;
        });
    }
}
