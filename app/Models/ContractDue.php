<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * استحقاق عقد لفترة — خصم دوري أو رسم أو حجز ضمان.
 *
 * ⚠️ الصف بيتولّد محسوب ومش مقيّد. القيد بيحصل بس لما حد يرحّله
 * من الشاشة، وساعتها بيتربط بالـ transaction اللي اتعمل — فمفيش
 * استحقاق بيتقيّد مرتين ولا قيد من غير أصل.
 */
class ContractDue extends Model
{
    use HasFactory;

    public const KIND_REBATE = 'rebate';
    public const KIND_FEE = 'fee';
    public const KIND_WITHHOLDING = 'withholding';

    public const STATUS_DUE = 'due';
    public const STATUS_SETTLED = 'settled';
    public const STATUS_WAIVED = 'waived';

    public const STATUS_CLASS = [
        self::STATUS_DUE => 'b-orange',
        self::STATUS_SETTLED => 'b-green',
        self::STATUS_WAIVED => 'b-gray',
    ];

    protected $fillable = [
        'contract_id', 'client_id', 'contract_clause_id', 'kind', 'basis',
        'period_start', 'period_end', 'basis_amount', 'pct', 'amount',
        'status', 'settled_at', 'settled_by', 'transaction_id', 'note',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'basis_amount' => 'decimal:2',
            'pct' => 'decimal:4',
            'amount' => 'decimal:2',
            'settled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // عدّاد السايدبار متكاش — نمسحه مع أي تغيير عشان مايبقاش قديم
        $bust = fn () => cache()->forget('nav.open_dues');

        static::saved($bust);
        static::deleted($bust);
    }

    // ==================== العلاقات ====================

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function clause(): BelongsTo
    {
        return $this->belongsTo(ContractClause::class, 'contract_clause_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function settledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by');
    }

    // ==================== العرض ====================

    public function statusLabel(): string
    {
        return __('client.due_status_'.$this->status);
    }

    public function statusClass(): string
    {
        return self::STATUS_CLASS[$this->status] ?? 'b-gray';
    }

    public function kindLabel(): string
    {
        return __('client.due_kind_'.$this->kind);
    }

    /** وصف الفترة — سنة أو ربع أو شهر حسب طول المدة */
    public function periodLabel(): string
    {
        $start = $this->period_start;
        $end = $this->period_end;

        if ($start === null || $end === null) {
            return '—';
        }

        $months = (int) round($start->diffInMonths($end)) + 1;

        return match (true) {
            $months >= 12 => (string) $start->year,
            $months >= 3 => 'Q'.(int) ceil($start->month / 3).' '.$start->year,
            default => $start->format('Y-m'),
        };
    }

    /** البند اللي الاستحقاق اتولّد منه، بلغة الواجهة */
    public function label(): string
    {
        return $this->clause?->displayLabel() ?? $this->kindLabel();
    }

    public function isDue(): bool
    {
        return $this->status === self::STATUS_DUE;
    }

    // ==================== النطاقات ====================

    public function scopeDue(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_DUE);
    }

    public function scopeSettled(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_SETTLED);
    }
}
