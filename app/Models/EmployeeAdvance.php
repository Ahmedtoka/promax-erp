<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * سلفة موظف (Lane 7 — المهمة ٥). بتتقسّط على شهور وبتتخصم من المرتب؛
 * القسط الأخير بياخد الباقي (`min(القسط, المتبقي)`).
 */
class EmployeeAdvance extends Model
{
    public const STATUSES = ['open', 'closed', 'void'];

    protected $fillable = [
        'number', 'employee_id', 'date', 'amount', 'installments', 'installment_amount',
        'start_period', 'recovered', 'safe_id', 'status', 'note', 'created_by',
    ];

    protected $attributes = ['status' => 'open', 'recovered' => 0, 'installments' => 1];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'amount' => 'decimal:2',
            'installment_amount' => 'decimal:2',
            'recovered' => 'decimal:2',
            'installments' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function safe(): BelongsTo
    {
        return $this->belongsTo(Safe::class);
    }

    public function remaining(): float
    {
        return round(max(0.0, (float) $this->amount - (float) $this->recovered), 2);
    }

    /** قسط شهر `YYYY-MM`: صفر لو السلفة مش مفتوحة أو لسه ماوصلناش لشهر البداية */
    public function installmentFor(string $period): float
    {
        if ($this->status !== 'open' || (string) $this->start_period > $period) {
            return 0.0;
        }

        $remaining = $this->remaining();
        $inst = (float) $this->installment_amount;

        // ⚠️ القسط الأخير بياخد الباقي كله — 1000÷3 = 333.33 ×3 بيسيب قرش يتيم
        // كان هيتحول لقسط رابع بـ0.01.
        $paidCount = $inst > 0 ? (int) floor((float) $this->recovered / $inst + 1e-6) : 0;
        if ($paidCount >= (int) $this->installments - 1) {
            return $remaining;
        }

        return round(min($inst, $remaining), 2);
    }

    /** ADV-1001، ADV-1002… — feature-dev §2 */
    public static function nextNumber(): string
    {
        $max = 1000;

        foreach (static::query()->where('number', 'like', 'ADV-%')->pluck('number') as $n) {
            $max = max($max, (int) preg_replace('/\D+/', '', (string) $n));
        }

        return 'ADV-'.($max + 1);
    }
}
