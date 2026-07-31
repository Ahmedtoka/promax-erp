<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Invoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'number', 'client_id', 'user_id', 'visit_id', 'payment', 'price_list',
        'subtotal', 'discount_pct', 'discount_source', 'discount', 'total',
        'tax_total', 'grand_total', 'eta_status', 'eta_uuid', 'eta_submitted_at',
        'cost_total', 'lat', 'lng',
    ];

    /** حالات الرفع لمصلحة الضرائب */
    public const ETA_STATUS = ['none', 'ready', 'exported', 'submitted', 'rejected'];

    public const ETA_CLASS = [
        'none' => 'b-gray',
        'ready' => 'b-orange',
        'exported' => 'b-blue',
        'submitted' => 'b-green',
        'rejected' => 'b-red',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount_pct' => 'decimal:4',
            'discount' => 'decimal:2',
            'total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'eta_submitted_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function transactions(): MorphMany
    {
        return $this->morphMany(Transaction::class, 'source');
    }

    public function paymentLabel(): string
    {
        // المسمى بييجي من lang/{ar,en}/enums.php — والثابت القديم fallback
        $key = 'enums.payment.'.$this->payment;

        return \Illuminate\Support\Facades\Lang::has($key)
            ? __($key)
            : ($this->payment === 'cash' ? 'كاش' : 'آجل');
    }

    public static function nextNumber(): string
    {
        $last = static::query()->orderByDesc('id')->value('number');
        $n = $last ? ((int) preg_replace('/\D+/', '', $last)) + 1 : 1001;

        return 'INV-'.$n;
    }

    // ==================== الربحية ====================

    /**
     * الربح = المبيعات − التكلفة. التكلفة لقطة وقت البيع فمابتتغيرش.
     *
     * ⚠️ الأساس `total` (الصافي قبل الضريبة) مش `grand_total`. الضريبة
     * فلوس المصلحة بتعدّي علينا — لو دخلت في الربح، كل صنف هيبان
     * ربحه أعلى بنسبة الضريبة وهامش الربح هيطلع كذب.
     */
    public function profit(): float
    {
        return round((float) $this->total - (float) $this->cost_total, 2);
    }

    public function marginPct(): float
    {
        $total = (float) $this->total;

        return $total > 0 ? round($this->profit() / $total, 4) : 0.0;
    }

    /**
     * اللي العميل بيدفعه فعلاً — صافي + ضريبة.
     *
     * ⚠️ استخدمها في أي مكان بيعرض «المستحق» أو بيقيّد في كشف الحساب.
     * `total` صافي المبيعات وبس. الفواتير القديمة `grand_total` فيها
     * بيساوي `total` لأنها اتعملت من غير ضريبة فعلاً.
     */
    public function payable(): float
    {
        // ⚠️ **مفيش `grand > 0 ? grand : total`.** التخمين ده كان
        // بيخبّي أي مسار نسي يحسب الضريبة (بيرجع الصافي في صمت بدل
        // ما يبان صفر)، وكان هيدفع إشعار خصم بقيمته الصافية بدل
        // الإجمالي. المايجريشن ملّى كل صف قديم، فالعمود موثوق.
        return round((float) $this->grand_total, 2);
    }

    public function hasTax(): bool
    {
        return (float) $this->tax_total > 0;
    }

    public function etaStatusLabel(): string
    {
        return __('tax.eta_status_'.($this->eta_status ?: 'none'));
    }

    public function etaStatusClass(): string
    {
        return self::ETA_CLASS[$this->eta_status] ?? 'b-gray';
    }

    /** قائمة السعر اللي الفاتورة اتعملت بيها */
    public function priceListLabel(): string
    {
        return \App\Services\Pricing::listLabel($this->price_list ?? 'new');
    }

    /** ترجمة مصدر الخصم المخزّن — بدون ما نرجع نسأل العميل */
    public function discountSourceLabel(): string
    {
        $key = $this->discount_source ?: 'no_discount';

        return \Illuminate\Support\Facades\Lang::has('client.'.$key)
            ? __('client.'.$key)
            : __('common.discount');
    }
}
