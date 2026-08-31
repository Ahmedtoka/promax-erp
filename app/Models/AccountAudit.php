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
        'has_account', 'their_balance',
        'has_statement', 'statement_path', 'statement_name',
        'note', 'reviewed_by', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'has_account' => 'boolean',
            'has_statement' => 'boolean',
            'their_balance' => 'decimal:2',
            'reviewed_at' => 'datetime',
        ];
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** رابط تحميل الكشف — null لو مفيش ملف */
    public function statementUrl(): ?string
    {
        return $this->statement_path ? Storage::url($this->statement_path) : null;
    }

    /**
     * حالة الصف في سطر واحد — بتغذي الفلاتر والسامري.
     *
     * pending = لسه ماتحددش · no_account = مالوش حساب ·
     * no_statement = له حساب ومفيش كشف · done = له حساب وكشفه موصول
     */
    public function state(): string
    {
        if ($this->has_account === null) {
            return 'pending';
        }

        if ($this->has_account === false) {
            return 'no_account';
        }

        return $this->has_statement === true ? 'done' : 'no_statement';
    }
}
