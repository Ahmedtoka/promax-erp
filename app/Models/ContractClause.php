<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * بند في عقد — نسبة أو مبلغ، بتوقيت محدّد.
 *
 * ⚠️ نوع واحد بس بيوصل للفاتورة: `invoice_discount`.
 * أي بند تاني تكلفة حقيقية بس بتتسوّى في وقت تاني، وممنوع
 * يتجمع على خصم الفاتورة وإلا العميل ياخد خصمه مرتين.
 */
class ContractClause extends Model
{
    use HasFactory;

    /** الأنواع بترتيب العرض في كارت العميل */
    public const KINDS = [
        'invoice_discount' => 'b-green',
        'rebate' => 'b-blue',
        'collection' => 'b-purple',
        'rent' => 'b-orange',
        'listing_fee' => 'b-gray',
        'opening_fee' => 'b-gray',
        'marketing' => 'b-purple',
        'withholding' => 'b-red',
        'penalty' => 'b-red',
        'returns' => 'b-gray',
        'credit' => 'b-gray',
        'tax_withheld' => 'b-gray',
        'other' => 'b-gray',
    ];

    /** الأنواع اللي نسبها بتتجمع في "إجمالي الخصم الحقيقي" */
    public const DEDUCTION_KINDS = ['invoice_discount', 'rebate', 'collection', 'rent'];

    /** الأنواع اللي بتتحسب كتكلفة ثابتة مش نسبة */
    public const FEE_KINDS = ['listing_fee', 'opening_fee', 'marketing', 'rent'];

    public const BASES = [
        'per_invoice', 'monthly', 'quarterly', 'annual',
        'one_off', 'per_item', 'per_branch', 'on_event', 'agreed',
    ];

    protected $fillable = [
        'contract_id', 'kind', 'label', 'label_en', 'pct', 'amount',
        'basis', 'raw_amount', 'raw_amount_en', 'note', 'note_en',
        'is_alternative', 'is_uncertain', 'sort', 'preset',
    ];

    protected function casts(): array
    {
        return [
            'pct' => 'decimal:4',
            'amount' => 'decimal:2',
            'is_alternative' => 'boolean',
            'is_uncertain' => 'boolean',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /**
     * ⚠️ بترجّع string دايماً. لو بند اتضاف يدوي من غير ترجمة إنجليزية،
     * بنعرض نص بديل بدل ما نرمي TypeError أو نعرض عربي في شاشة إنجليزية.
     */
    /** استحقاقات اتولّدت من البند ده */
    public function dues(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ContractDue::class);
    }

    public function displayLabel(): string
    {
        return $this->pick($this->label, $this->label_en)
            ?? __('client.clause_untranslated');
    }

    /** النص الأصلي للمبلغ زي ما هو في العقد */
    public function displayRaw(): ?string
    {
        return $this->pick($this->raw_amount, $this->raw_amount_en);
    }

    public function displayNote(): ?string
    {
        return $this->pick($this->note, $this->note_en);
    }

    /**
     * ⚠️ في الإنجليزي بنرجّع الإنجليزي أو **لا شيء** — ممنوع نرجّع العربي
     * كـ fallback. عرض نص عربي في شاشة إنجليزية أسوأ من خانة فاضية.
     */
    private function pick(?string $ar, ?string $en): ?string
    {
        return app()->getLocale() === 'en' ? ($en ?: null) : ($ar ?: null);
    }

    public function kindLabel(): string
    {
        return __('client.clause_kind_'.$this->kind);
    }

    public function kindClass(): string
    {
        return self::KINDS[$this->kind] ?? 'b-gray';
    }

    public function basisLabel(): string
    {
        return __('client.clause_basis_'.$this->basis);
    }

    /**
     * البند بيتحسب في الإجماليات؟
     * البديل لأ (عشان مايتعدش مرتين)، وغير المؤكد لأ (قراءة مشكوك فيها).
     */
    public function counts(): bool
    {
        return ! $this->is_alternative && ! $this->is_uncertain;
    }

    /** الرقم المعروض — نسبة أو مبلغ أو النص الأصلي لو الاتنين فاضيين */
    public function valueLabel(): string
    {
        if ($this->pct !== null && (float) $this->pct != 0.0) {
            return number_format((float) $this->pct * 100, 2).'%';
        }
        if ($this->amount !== null && (float) $this->amount > 0) {
            return number_format((float) $this->amount).' '.__('common.currency');
        }

        // ⚠️ displayRaw() مش raw_amount — الأخير عربي دايماً وكان بيتسرّب
        // للواجهة الإنجليزية في الـ 15 بند اللي مالهمش نسبة ولا مبلغ.
        return $this->displayRaw() ?: '—';
    }
}
