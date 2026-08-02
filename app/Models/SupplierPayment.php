<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * دفعة لمورد — بتقلل المستحق.
 *
 * ⚠️ زي الفاتورة: `record()` بتعمل الدفعة وقيدها المدين مع بعض.
 */
class SupplierPayment extends Model
{
    public const METHODS = ['cash', 'transfer', 'cheque'];

    protected $fillable = [
        'number', 'supplier_id', 'paid_on', 'amount', 'method',
        'reference', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'paid_on' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function nextNumber(): string
    {
        $last = static::query()->orderByDesc('id')->value('number');
        $n = $last ? ((int) preg_replace('/\D+/', '', $last)) + 1 : 1001;

        return 'SPAY-'.$n;
    }

    public static function record(array $attributes): self
    {
        return DB::transaction(function () use ($attributes) {
            $payment = static::create($attributes + ['number' => static::nextNumber()]);

            $payment->supplier->post(
                'payment',
                $payment->paid_on->toDateString(),
                (float) $payment->amount,
                0,
                __('supplier.txn_payment_memo', ['number' => $payment->number]),
                $payment,
            );

            return $payment;
        });
    }

    public function methodLabel(): string
    {
        return __('supplier.method_'.$this->method);
    }
}
