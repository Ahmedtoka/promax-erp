<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * باتش إنتاج — الوحدة اللي بنتتبع بيها الصلاحية.
 * Production batch — the unit that carries the expiry date.
 *
 * ⚠️ ممنوع تعدّل qty_remaining يدوي. استخدم:
 *     $batch->issue($qty)   لما تطلع للعربية
 *     $batch->receive($qty) لما ترجع
 *     $batch->writeOff($qty) للتالف
 */
class Batch extends Model
{
    use HasFactory;

    /** عتبات التنبيه بالأيام — القرار متسجل في سكيل promax-inventory */
    public const WARN_DAYS = 90;   // أصفر
    public const DANGER_DAYS = 30; // أحمر

    protected $fillable = [
        'product_id', 'warehouse_id', 'goods_receipt_id', 'batch_no',
        'produced_on', 'expires_on',
        'qty_received', 'qty_remaining', 'qty_issued', 'qty_damaged',
        // الكمية والوحدة زي ما اتكتبوا في الإذن (٢٨/٨) — مرساة
        // «إعادة الحساب» لو مضاعِف الوحدة على الصنف اتصحح بعدين
        'entry_qty', 'entry_unit',
        'cost', 'blocked', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'produced_on' => 'date',
            'expires_on' => 'date',
            'cost' => 'decimal:2',
            'blocked' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
    }

    /** الأرفف اللي الباتش ده متوزّع عليها */
    public function locations(): HasMany
    {
        return $this->hasMany(BatchLocation::class);
    }

    /** الكمية المترصّفة فعلاً على أرفف */
    public function shelvedQty(): int
    {
        return (int) $this->locations()->sum('qty');
    }

    /** مستلم ولسه على الأرض — لازم يترصّف */
    public function unshelvedQty(): int
    {
        return max((int) $this->qty_remaining - $this->shelvedQty(), 0);
    }

    public function isFullyShelved(): bool
    {
        return $this->unshelvedQty() === 0;
    }

    /** أكواد الأرفف مفصولة بفاصلة — للعرض في الجداول */
    public function locationCodes(): string
    {
        $codes = $this->locations()->with('location')->get()
            ->filter(fn (BatchLocation $bl) => $bl->qty > 0)
            ->map(fn (BatchLocation $bl) => $bl->location?->code.' ('.$bl->qty.')')
            ->filter();

        return $codes->isEmpty() ? '—' : $codes->join(', ');
    }

    public function custodyItems(): HasMany
    {
        return $this->hasMany(CustodyItem::class);
    }

    public function invoiceItems(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    // ==================== الصلاحية ====================

    /** كام يوم فاضل؟ بالسالب لو منتهي */
    public function daysLeft(): int
    {
        return (int) now()->startOfDay()->diffInDays($this->expires_on, false);
    }

    public function isExpired(): bool
    {
        return $this->daysLeft() < 0;
    }

    /** ينفع يتباع؟ */
    public function isSellable(): bool
    {
        return ! $this->blocked && ! $this->isExpired() && $this->qty_remaining > 0;
    }

    /** expired | danger | warn | ok */
    public function expiryState(): string
    {
        // ⚠️ باتش من غير تاريخ انتهاء (رصيد أول مدة مثلاً) مش «خطر» —
        // daysLeft بترجع 0 للـnull وكان بيطلع أحمر في كل التقارير.
        if ($this->expires_on === null) {
            return 'ok';
        }

        $days = $this->daysLeft();

        return match (true) {
            $days < 0 => 'expired',
            $days <= self::DANGER_DAYS => 'danger',
            $days <= self::WARN_DAYS => 'warn',
            default => 'ok',
        };
    }

    public function expiryClass(): string
    {
        return match ($this->expiryState()) {
            'expired' => 'b-red',
            'danger' => 'b-red',
            'warn' => 'b-orange',
            default => 'b-green',
        };
    }

    public function expiryLabel(): string
    {
        $days = $this->daysLeft();

        return match ($this->expiryState()) {
            'expired' => __('stock.expired_ago', ['days' => abs($days)]),
            default => __('stock.days_left', ['days' => $days]),
        };
    }

    // ==================== الحركة ====================

    /**
     * إخراج كمية من المخزن (تحميل عربية / تسليم مباشر).
     * بيرجع رسالة خطأ أو null لو تمام.
     */
    public function issue(int $qty): ?string
    {
        if ($qty <= 0) {
            return __('stock.qty_must_be_positive');
        }
        if ($this->blocked) {
            return __('stock.batch_blocked', ['batch' => $this->batch_no]);
        }
        if ($this->isExpired()) {
            return __('stock.batch_expired', ['batch' => $this->batch_no]);
        }
        if ($qty > $this->qty_remaining) {
            return __('stock.batch_not_enough', [
                'batch' => $this->batch_no,
                'available' => $this->qty_remaining,
            ]);
        }

        $this->decrement('qty_remaining', $qty);
        $this->increment('qty_issued', $qty);

        return null;
    }

    /** رجوع كمية للمخزن (مرتجع من عربية) */
    public function receive(int $qty): void
    {
        if ($qty <= 0) {
            return;
        }

        $this->increment('qty_remaining', $qty);
        $this->decrement('qty_issued', min($qty, $this->qty_issued));
    }

    /** تالف / مسحوب — بيخرج من المتاح خالص */
    public function writeOff(int $qty): ?string
    {
        if ($qty <= 0 || $qty > $this->qty_remaining) {
            return __('stock.batch_not_enough', [
                'batch' => $this->batch_no,
                'available' => $this->qty_remaining,
            ]);
        }

        $this->decrement('qty_remaining', $qty);
        $this->increment('qty_damaged', $qty);

        return null;
    }

    // ==================== سكوبات ====================

    /** الباتشات اللي ينفع تتباع، بترتيب الـ FEFO (الأقرب انتهاءً الأول) */
    public function scopeSellable(Builder $q): Builder
    {
        return $q->where('blocked', false)
            ->where('qty_remaining', '>', 0)
            ->whereDate('expires_on', '>=', now()->toDateString())
            ->orderBy('expires_on')
            ->orderBy('id');
    }

    public function scopeExpiringWithin(Builder $q, int $days): Builder
    {
        return $q->where('qty_remaining', '>', 0)
            ->whereDate('expires_on', '>=', now()->toDateString())
            ->whereDate('expires_on', '<=', now()->addDays($days)->toDateString());
    }

    public function scopeExpired(Builder $q): Builder
    {
        return $q->where('qty_remaining', '>', 0)
            ->whereDate('expires_on', '<', now()->toDateString());
    }
}
