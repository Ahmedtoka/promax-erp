<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** قفل يوم — سنابشوت أرقام اليومية بعد تصفيات المناديب (2026-08-06). */
class DayClose extends Model
{
    protected $fillable = [
        'date', 'invoices_count', 'clients_count', 'sales_cash', 'sales_credit',
        'sales_net', 'returns_total', 'collections_total',
        'pos_delivered_count', 'pos_delivered_value',
        'settlements_count', 'settlements_received', 'settlements_balance',
        'notes', 'closed_by',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'sales_cash' => 'decimal:2', 'sales_credit' => 'decimal:2',
            'sales_net' => 'decimal:2', 'returns_total' => 'decimal:2',
            'collections_total' => 'decimal:2', 'pos_delivered_value' => 'decimal:2',
            'settlements_received' => 'decimal:2', 'settlements_balance' => 'decimal:2',
        ];
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
