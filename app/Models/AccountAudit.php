<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * صف مراجعة حساب — لسلسلة أو لعميل فردي (٢٨ أغسطس ٢٠٢٦).
 *
 * ⚠️ `their_balance` = الرصيد **اللي العميل قايله**. الرصيد الرسمي
 * من `clients.balance` المحسوب من القيود — الاتنين بيتعرضوا جنب
 * بعض والفرق هو الشغل. **ممنوع أي كود يقرا الرقم ده كرصيد.**
 */
class AccountAudit extends Model
{
    public const TYPES = ['group', 'client'];

    protected $fillable = [
        'entity_type', 'entity_id',
        // ترتيب يدوي لليستة — بيتكتب بالإيد ويتحفظ مع الصف (٢٨/٨)
        'sort',
        'has_account', 'their_balance',
        'has_statement', 'statement_path', 'statement_name',
        // إذون استلام الكشف · الفاتورة الضريبية · تأكيد المدير (٢٨/٨)
        'has_receipt', 'tax_invoice', 'confirmed_at', 'confirmed_by',
        'note', 'reviewed_by', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'sort' => 'integer',
            'has_account' => 'boolean',
            'has_statement' => 'boolean',
            'has_receipt' => 'boolean',
            'tax_invoice' => 'boolean',
            'their_balance' => 'decimal:2',
            'reviewed_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /** رابط تحميل الكشف — null لو مفيش ملف */
    public function statementUrl(): ?string
    {
        return $this->statement_path ? Storage::url($this->statement_path) : null;
    }

    /**
     * حالة الصف في سطر واحد — بتغذي الفلاتر والسامري.
     *
     * سلسلة السؤال زي ما المالك وصفها بالحرف، وكل حالة بتقف عند
     * أول «لا» في السلسلة:
     *
     *   pending   لسه ماتحددش أه ولا لا
     *   no_account   مالوش حساب أصلاً — السلسلة بتقف هنا
     *   no_statement له حساب بس مفيش كشف
     *   no_receipt   له حساب وكشف بس مفيش إذن استلام
     *   full         حساب + كشف + إذن استلام — مظبوط تماماً
     *
     * ⚠️ الفاتورة الضريبية **محور مستقل** مش خطوة في السلسلة —
     * ممكن عميل يبقى مظبوط تماماً ومتعملّهوش فاتورة، والعكس.
     */
    public function state(): string
    {
        if ($this->has_account === null) {
            return 'pending';
        }

        if ($this->has_account === false) {
            return 'no_account';
        }

        if ($this->has_statement !== true) {
            return 'no_statement';
        }

        return $this->has_receipt === true ? 'full' : 'no_receipt';
    }

    /** مظبوط تماماً — حساب وكشف وإذن استلام */
    public function isFull(): bool
    {
        return $this->state() === 'full';
    }
}
