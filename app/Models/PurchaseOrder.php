<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class PurchaseOrder extends Model
{
    use HasFactory;

    public const STATUSES = [
        'pending' => ['مستني', 'b-gray'],
        'arrived' => ['جاري التسليم', 'b-orange'],
        'delivered' => ['اتسلم', 'b-green'],
        'cancelled' => ['ملغي', 'b-red'],
    ];

    /** الأمر اتولد من طلب ريفيل بتاع بروموتر — ثابت عشان مانقارنش بنص حر */
    public const SOURCE_REPLENISHMENT = 'replenishment';

    protected $fillable = [
        'number', 'client_id', 'source', 'address', 'assigned_to', 'status',
        'price_mode', 'total', 'tax_total', 'grand_total',
        'arrived_at', 'delivered_at', 'due_date',
    ];

    protected function casts(): array
    {
        return [
            'total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'arrived_at' => 'datetime',
            'delivered_at' => 'datetime',
            'due_date' => 'date',
        ];
    }

    /**
     * اللي العميل بيدفعه — صافي + ضريبة.
     *
     * ⚠️ نفس قاعدة الفاتورة: `total` صافي المبيعات، و `grand_total` هو
     * اللي بيتقيّد في كشف الحساب. الأوامر القديمة `grand_total` فيها
     * بيساوي `total` لأنها اتعملت من غير ضريبة.
     */
    public function payable(): float
    {
        // ⚠️ **مفيش `grand > 0 ? grand : total`.** التخمين ده كان
        // بيخبّي أي مسار نسي يحسب الضريبة (بيرجع الصافي في صمت بدل
        // ما يبان صفر)، وكان هيدفع إشعار خصم بقيمته الصافية بدل
        // الإجمالي. المايجريشن ملّى كل صف قديم، فالعمود موثوق.
        return round((float) $this->grand_total, 2);
    }

    public function fromReplenishment(): bool
    {
        return $this->source === self::SOURCE_REPLENISHMENT;
    }

    /**
     * المصدر للعرض. الأوامر اللي جاية من طلب ريفيل ليها مسمى مترجم،
     * وأي مصدر تاني (اسم عميل أو منصة) بيتعرض زي ما هو.
     */
    public function sourceLabel(): string
    {
        if ($this->fromReplenishment()) {
            return __('field.replenishment');
        }

        return $this->source ?: '—';
    }

    public function sourceClass(): string
    {
        return $this->fromReplenishment() ? 'b-orange' : 'b-purple';
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function transactions(): MorphMany
    {
        return $this->morphMany(Transaction::class, 'source');
    }

    public function statusLabel(): string
    {
        // المسمى بييجي من lang/{ar,en}/enums.php — والثابت القديم fallback
        $key = 'enums.po_status.'.$this->status;

        return \Illuminate\Support\Facades\Lang::has($key)
            ? __($key)
            : (self::STATUSES[$this->status][0] ?? $this->status);
    }

    public function statusClass(): string
    {
        return self::STATUSES[$this->status][1] ?? 'b-gray';
    }

    public function qtyTotal(): int
    {
        return $this->items->sum('qty');
    }

    public static function nextNumber(): string
    {
        $last = static::query()->orderByDesc('id')->value('number');
        $n = $last ? ((int) preg_replace('/\D+/', '', $last)) + 1 : 2001;

        return 'PO-'.$n;
    }
}
