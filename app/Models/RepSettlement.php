<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * تصفية مندوب — قفلة الحسابات (2026-08-06).
 *
 * `balance` هو الرصيد المترحّل: **موجب = المندوب عليه فلوس (مدين)**،
 * **سالب = الشركة عليها ليه (دائن)**. التصفية الجاية بتبدأ منه.
 */
class RepSettlement extends Model
{
    protected $fillable = [
        'number', 'user_id', 'from_at', 'to_at', 'invoices_count',
        'cash_sales', 'credit_sales', 'cash_refunds', 'expected',
        'prev_balance', 'received', 'balance', 'note', 'created_by', 'goods_json',
        'cash_collections', 'collections_json',
    ];

    protected function casts(): array
    {
        return [
            // ⚠️ لقطة تاريخية — بتترسم زي ما هي وقت التوقيع
            'goods_json' => 'array',
            'collections_json' => 'array',
            'cash_collections' => 'decimal:2',
            'from_at' => 'datetime',
            'to_at' => 'datetime',
            'cash_sales' => 'decimal:2',
            'credit_sales' => 'decimal:2',
            'cash_refunds' => 'decimal:2',
            'expected' => 'decimal:2',
            'prev_balance' => 'decimal:2',
            'received' => 'decimal:2',
            'balance' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** آخر تصفية للمندوب ده — الرصيد والنافذة بيبدأوا منها */
    public static function lastFor(int $userId): ?self
    {
        return static::where('user_id', $userId)->orderByDesc('to_at')->first();
    }

    public static function nextNumber(): string
    {
        $last = static::query()->orderByDesc('id')->value('number');
        $n = $last ? ((int) preg_replace('/\D+/', '', $last)) + 1 : 1001;

        return 'RS-'.$n;
    }

    public function balanceLabel(): string
    {
        return match (true) {
            (float) $this->balance > 0 => __('settle.rep_owes'),
            (float) $this->balance < 0 => __('settle.rep_credit'),
            default => __('settle.settled_zero'),
        };
    }

    public function balanceClass(): string
    {
        return match (true) {
            (float) $this->balance > 0 => 'b-red',
            (float) $this->balance < 0 => 'b-green',
            default => 'b-gray',
        };
    }
}
