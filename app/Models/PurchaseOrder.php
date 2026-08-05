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

    /** حالات موافقة الحسابات — null = الفلو القديم من غير موافقة خالص */
    public const APPROVALS = [
        'pending' => ['مستني الحسابات', 'b-orange'],
        'approved' => ['اعتمدته الحسابات', 'b-green'],
        'rejected' => ['مرفوض', 'b-red'],
    ];

    protected $fillable = [
        'number', 'client_id', 'source', 'sheet_path', 'sheet_name', 'address', 'assigned_to', 'status',
        'price_mode', 'total', 'tax_total', 'grand_total',
        'arrived_at', 'delivered_at', 'due_date',
        'approval_status', 'approved_by', 'approved_at', 'approval_note',
        'was_edited', 'edited_by', 'edited_at', 'due_at', 'warehouse_id', 'pick_order_id', 'created_by',
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
            'due_at' => 'datetime',
            'approved_at' => 'datetime',
            'was_edited' => 'boolean',
            'edited_at' => 'datetime',
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

    // ═══════════ موافقة الحسابات (كي أكاونت) — 2026-08-04 ═══════════

    /** الأمر ده ماشي في فلو الموافقة أصلاً؟ null = فلو قديم */
    public function needsApproval(): bool
    {
        return $this->approval_status !== null;
    }

    /** لسه مستني قرار الحسابات — مش المفروض يظهر للمندوب ولا يتجهز */
    public function awaitingApproval(): bool
    {
        return $this->approval_status === 'pending';
    }

    public function approvalLabel(): string
    {
        $key = 'enums.po_approval.'.$this->approval_status;

        return \Illuminate\Support\Facades\Lang::has($key)
            ? __($key)
            : (self::APPROVALS[$this->approval_status][0] ?? '—');
    }

    public function approvalClass(): string
    {
        return self::APPROVALS[$this->approval_status][1] ?? 'b-gray';
    }

    /** متأخر: عدّى معاده وماتسلمش (والمرفوض مش متأخر — اتقفل خلاص) */
    public function isLate(): bool
    {
        return $this->due_at !== null
            && $this->due_at->isPast()
            && ! in_array($this->status, ['delivered', 'cancelled'], true)
            && $this->approval_status !== 'rejected';
    }

    /** إجمالي المسلَّم فعلاً بالقطع — والفرق = qtyTotal() − ده */
    public function deliveredQtyTotal(): int
    {
        return $this->items->sum('delivered_qty');
    }

    /** قيمة المسلَّم فعلاً (صافي + ضريبته) — اللي اتقيّد في كشف الحساب */
    public function deliveredValue(): float
    {
        return round($this->items->sum(function ($i) {
            $net = (float) $i->price * (int) $i->delivered_qty;

            return $net + round($net * (float) ($i->tax_rate ?? 0), 2);
        }), 2);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function pickOrder(): BelongsTo
    {
        return $this->belongsTo(PickOrder::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** آخر واحد عدّل الأمر (تراك 2026-08-05) */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }

    public static function nextNumber(): string
    {
        $last = static::query()->orderByDesc('id')->value('number');
        $n = $last ? ((int) preg_replace('/\D+/', '', $last)) + 1 : 2001;

        return 'PO-'.$n;
    }
}
