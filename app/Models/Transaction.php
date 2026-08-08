<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Transaction extends Model
{
    use HasFactory;

    public const KINDS = [
        'sale' => 'فاتورة/مبيعات',
        'collection' => 'تحصيل نقدي',
        'return' => 'مرتجع',
        // ⚠️ **الكود بيكتب `refund` من زمان والثابت ماكانش فيه**
        // (تدقيق ٨/٨/٢٠٢٦) — أي شاشة بتترجم النوع من `KINDS` كانت
        // بتعرض المفتاح الخام «refund» للمحاسب، وأي فاليديشن
        // `Rule::in(array_keys(KINDS))` كان بيرفض القيد.
        // ده رد فلوس مرتجع كاش: مدين، بيلغي القيد الدائن بتاع المرتجع.
        'refund' => 'رد فلوس مرتجع',
        'rebate' => 'خصم تجاري',
        'settlement' => 'تسوية/مقاصة',
        'transfer' => 'قيد تحويل',
        'taxded' => 'ضرائب مخصومة',
        // بضاعة أمانة عند العميل — مش مديونية، ملك بروماكس لحد ما تتباع
        'consignment' => 'بضاعة أمانة',
        // رصيد افتتاحي وقت استيراد العملاء — أساس كشف الحساب
        'opening' => 'رصيد افتتاحي',
    ];

    /**
     * القيود اللي بتمثّل **مديونية على العميل ليها تاريخ استحقاق**.
     *
     * ⚠️ **مش كل قيد مدين.** `refund` (رد فلوس مرتجع كاش) و`transfer`
     * و`taxded` كلهم مدين كمان — ولو دخلوا في توزيع الأعمار، قيد
     * `refund` بتاريخ النهارده بياخد نصيبه الأول (التوزيع من الأحدث
     * للأقدم) فبيخبّي مديونية قديمة متأخرة فعلاً. كان غير مؤثر وقت
     * ما الـ`refund` ماكانش بيحصل إلا لعميل كاش صافي رصيده صفر؛
     * العميل المختلط عنده الاتنين مع بعض (2026-08-08).
     */
    public const DEBT_KINDS = ['sale', 'opening'];

    /**
     * ═══════════════════════════════════════════════════════════
     * طرق التحصيل (قرار المالك ٨ أغسطس ٢٠٢٦)
     * ═══════════════════════════════════════════════════════════
     *
     * ⚠️ **الطريقة مش بتغيّر المحاسبة — بتغيّر المطابقة.** القيد
     * `collection` واحد في كل الحالات، بس المحاسب بيقفل اليومية
     * محتاج يعرف كام كاش في الخزنة وكام شيك في الدرج وكام تحويل
     * لازم يتطابق مع كشف البنك.
     *
     * ⚠️ **الشيك بيدخل حساب العميل فوراً زي الكاش** (قرار المالك
     * صراحةً) — مش مستني تاريخ الاستحقاق ولا التحصيل من البنك.
     * أي تغيير في ده قرار مالك جديد مش تحسين تقني.
     *
     * ⚠️ **اللي مش نقدي بياخد ريفرنس إجباري** — رقم عملية الماكينة
     * أو رقم الشيك أو رقم التحويل. من غيره المطابقة بتبقى مستحيلة
     * والفرق بيتكتشف آخر الشهر.
     */
    public const METHOD_CASH = 'cash';
    public const METHOD_CARD = 'card';
    public const METHOD_CHEQUE = 'cheque';
    public const METHOD_TRANSFER = 'transfer';

    public const METHODS = [
        self::METHOD_CASH,
        self::METHOD_CARD,
        self::METHOD_CHEQUE,
        self::METHOD_TRANSFER,
    ];

    /** الطرق اللي محتاجة ريفرنس — كل حاجة ما عدا النقدي */
    public const METHODS_NEED_REF = [
        self::METHOD_CARD,
        self::METHOD_CHEQUE,
        self::METHOD_TRANSFER,
    ];

    protected $fillable = [
        'client_id', 'date', 'memo', 'debit', 'credit', 'tax', 'kind',
        'method', 'reference', 'cheque_bank', 'cheque_due', 'proof_path', 'idem_key',
        'source_type', 'source_id',
    ];

    /** صورة إثبات التحصيل الميداني — شيك/تحويل/سكرين محفظة */
    public function proofUrl(): ?string
    {
        return $this->proof_path ? asset('storage/'.$this->proof_path) : null;
    }

    public function methodLabel(): ?string
    {
        return $this->method === null ? null : __('client.pay_method_'.$this->method);
    }

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'cheque_due' => 'date',
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function kindLabel(): string
    {
        // المسمى بييجي من lang/{ar,en}/enums.php — والثابت القديم fallback
        $key = 'enums.transaction.'.$this->kind;

        return \Illuminate\Support\Facades\Lang::has($key)
            ? __($key)
            : (self::KINDS[$this->kind] ?? $this->kind);
    }
}
