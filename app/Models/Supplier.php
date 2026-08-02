<?php

namespace App\Models;

use App\Models\Concerns\HasBilingualName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ═══════════════════════════════════════════════════════════════
 * المورد — الطرف اللي بنشتري منه
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **الرصيد من الدفتر وبس.** `balance` تجميعة من
 * `supplier_transactions` — أي كتابة مباشرة عليه بتفكّه عن دفتره
 * ومحدش يعرف بعدها الرقمين مين فيهم الصح. عدّل بالقيود ونادي
 * `recalculate()`.
 *
 * ⚠️ **موجب = علينا له.** عكس العملاء: رصيد العميل الموجب فلوس
 * لينا عنده، ورصيد المورد الموجب فلوس عليه... لأ — فلوس علينا ليه.
 */
class Supplier extends Model
{
    use HasBilingualName;

    protected $fillable = [
        'code', 'name', 'name_en', 'phone', 'contact_person', 'address',
        'tax_id', 'payment_days', 'notes', 'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'balance' => 'decimal:2',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(SupplierOrder::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(SupplierInvoice::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(SupplierTransaction::class);
    }

    // ═══════════════════════════════════════════════════════════

    public static function nextCode(): string
    {
        $last = static::query()->orderByDesc('id')->value('code');
        $n = $last ? ((int) preg_replace('/\D+/', '', $last)) + 1 : 1;

        return 'SUP-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
    }

    /** إعادة حساب الرصيد من الدفتر — المصدر الوحيد */
    public function recalculate(): void
    {
        $sums = $this->transactions()
            ->selectRaw('COALESCE(SUM(credit), 0) as c, COALESCE(SUM(debit), 0) as d')
            ->first();

        $this->forceFill([
            'balance' => round((float) $sums->c - (float) $sums->d, 2),
        ])->save();
    }

    /**
     * قيد في دفتر المورد + تحديث الرصيد — البوابة الوحيدة للكتابة.
     *
     * ⚠️ جوه ترانزاكشن اللي بينادي — القيد والمستند (فاتورة/دفعة)
     * لازم يعيشوا أو يموتوا مع بعض.
     */
    public function post(string $kind, string $date, float $debit, float $credit, ?string $memo = null, ?Model $source = null): SupplierTransaction
    {
        // ⚠️ **قفل صف المورد يسلسل القيود.** من غيره، دفعتين في نفس
        // اللحظة: كل واحدة بتحسب SUM من سنابشوت مش شايف التانية،
        // وآخر كتابة للرصيد بتكسب — قيد في الدفتر مش باين في الرصيد.
        static::whereKey($this->id)->lockForUpdate()->first();

        $txn = $this->transactions()->create([
            'date' => $date,
            'kind' => $kind,
            'debit' => round($debit, 2),
            'credit' => round($credit, 2),
            'memo' => $memo,
            'source_type' => $source?->getMorphClass(),
            'source_id' => $source?->getKey(),
        ]);

        $this->recalculate();

        return $txn;
    }
}
