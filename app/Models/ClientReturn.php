<?php

namespace App\Models;

use App\Models\Concerns\HasDocumentNumber;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * مستند مرتجع — بضاعة راجعة من عميل.
 *
 * ⚠️ **الاسم `ClientReturn` مش `Return`** — `Return` كلمة محجوزة في
 * PHP ومايصحّش تبقى اسم كلاس. والجدول `returns` كلمة محجوزة في
 * MySQL كمان، فأي SQL خام عليه لازم backticks.
 *
 * ⚠️ **المستند ده مش «سجل» للقيد — هو مصدر القيد.** القيود بتتولد
 * منه جوه `App\Services\Returns`، والمستند بيمسك أرقام القيود عشان
 * المراجعة تمشي في الاتجاهين.
 */
class ClientReturn extends Model
{
    use HasDocumentNumber, HasFactory;

    protected $table = 'returns';

    /**
     * ═══════════════════════════════════════════════════════════
     * سياسات المرتجع (قرار المالك ٨ أغسطس ٢٠٢٦)
     * ═══════════════════════════════════════════════════════════
     *
     * ⚠️ **السياسة بتتعرّف على العميل**، والمندوب بيختار من المسموح
     * له بيه بس. القيود بتختلف من واحدة للتانية:
     *
     * | السياسة | القيد | يعني |
     * |---|---|---|
     * | `cash` | `return` دائن + `refund` مدين | المندوب ردّ الفلوس في إيده — الرصيد مايتغيّرش، وفلوس التصفية بتقل |
     * | `account` | `return` دائن | بيقلل مديونيته الحالية |
     * | `credit_next` | `return` دائن | نفس القيد، بس النية إنه رصيد يتاخد المرة الجاية — الفرق في المستند مش في الليدجر |
     * | `exchange` | `return` دائن | تبديل وقتها — البديل بيخرج **بفاتورة عادية** بعده على طول |
     *
     * ⚠️ **`account` و`credit_next` و`exchange` نفس القيد بالظبط.**
     * الفرق نية تشغيلية بتتسجّل على المستند — ومحاولة تفريقهم في
     * الليدجر (قيد تحويل مثلاً) كانت هتزوّد صفوف مالهاش مقابل حقيقي.
     *
     * ⚠️ **التبديل مش بياخد قيد مدين هنا.** البضاعة البديلة بتخرج
     * بفاتورة زي أي بيع — بتسعيرتها وضريبتها وخصمها ومن العهدة.
     * لو التبديل كتب المدين بنفسه، البضاعة كانت هتخرج من العربية
     * من غير فاتورة، والتصفية تلاقي عجز. الشاشة بتفتح البيع تلقائياً
     * بعد التبديل عشان الخطوتين مايتفصلوش.
     */
    public const POLICY_CASH = 'cash';
    public const POLICY_ACCOUNT = 'account';
    public const POLICY_EXCHANGE = 'exchange';
    public const POLICY_CREDIT_NEXT = 'credit_next';

    public const POLICIES = [
        self::POLICY_CASH,
        self::POLICY_ACCOUNT,
        self::POLICY_EXCHANGE,
        self::POLICY_CREDIT_NEXT,
    ];

    public const CONDITION_GOOD = 'good';
    public const CONDITION_DAMAGED = 'damaged';
    public const CONDITIONS = [self::CONDITION_GOOD, self::CONDITION_DAMAGED];

    protected $fillable = [
        'number', 'client_id', 'user_id', 'visit_id', 'custody_id',
        'source', 'policy', 'subtotal', 'discount', 'total', 'tax_total', 'grand_total',
        'good_units', 'damaged_units', 'transaction_id', 'refund_transaction_id',
        'idem_key', 'note', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'grand_total' => 'decimal:2',
        ];
    }

    // ---------- Relations ----------

    /** المندوب صاحب المرتجع — لتقارير المرتجعات (٢١/٨) */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function rep(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReturnItem::class, 'return_id');
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }

    // ---------- Helpers ----------

    /**
     * ⚠️ **متستخدمش `filter_var(FILTER_SANITIZE_NUMBER_INT)`** — بتسيب
     * الإشارة السالبة وبتكسر الترقيم (الفخ المعروف في المشروع).
     */
    public static function nextNumber(): string
    {
        // ⚠️ أكبر رقم مش آخر صف — شوف `HasDocumentNumber`
        return static::nextDocumentNumber('RET-', 1001);
    }

    /** اللي العميل بياخده فعلاً — الإجمالي شامل الضريبة، زي الفاتورة */
    public function payable(): float
    {
        return round((float) $this->grand_total, 2);
    }

    public function policyLabel(): string
    {
        return __('field.return_policy_'.$this->policy);
    }

    public function isCash(): bool
    {
        return $this->policy === self::POLICY_CASH;
    }
}
