<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * جرد مخزن.
 *
 * ⚠️ الجرد **مايحركش مخزون** غير عند الاعتماد. قبل كده هو ورقة عد
 * وبس. اللي بيحرّك المخزون هو `StockCounting::approve()` — المكان
 * الوحيد، وجوه ترانزاكشن.
 */
class StockCount extends Model
{
    protected $fillable = [
        'number', 'warehouse_id', 'status', 'started_by', 'approved_by',
        'count_date', 'approved_at', 'lines', 'diff_lines', 'qty_diff', 'value_diff', 'notes',
    ];

    public const STATUS = ['draft', 'counting', 'approved', 'cancelled'];

    public const STATUS_CLASS = [
        'draft' => 'b-gray',
        'counting' => 'b-orange',
        'approved' => 'b-green',
        'cancelled' => 'b-red',
    ];

    /** أسباب الفرق — بتظهر كقائمة عشان التقارير تبقى قابلة للتجميع */
    public const REASONS = ['damage', 'expiry', 'theft', 'entry_error', 'found', 'other'];

    protected function casts(): array
    {
        return [
            'count_date' => 'date',
            'approved_at' => 'datetime',
            'value_diff' => 'decimal:2',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockCountItem::class);
    }

    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['draft', 'counting'], true);
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function statusLabel(): string
    {
        return __('count.status_'.$this->status);
    }

    public function statusClass(): string
    {
        return self::STATUS_CLASS[$this->status] ?? 'b-gray';
    }

    public static function nextNumber(): string
    {
        $last = static::query()->orderByDesc('id')->value('number');

        // ⚠️ ممنوع filter_var(FILTER_SANITIZE_NUMBER_INT) — بيسيب
        // الإشارة السالبة وبيكسّر الترقيم
        $n = $last ? ((int) preg_replace('/\D+/', '', $last)) + 1 : 1001;

        return 'CNT-'.$n;
    }
}
