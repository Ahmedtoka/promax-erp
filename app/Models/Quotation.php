<?php

namespace App\Models;

use App\Models\Concerns\HasDocumentNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * عرض سعر (كوتيشن) — مستند عرض محفوظ بسجله (٢١ أغسطس ٢٠٢٦).
 *
 * ⚠️ **مش قيد**: مفيش أي أثر على دفتر عميل ولا مخزون — ورقة تفاوض
 * بتتطبع وتتبعت، وسجلها بيقول مين طلّع إيه لمين وبكام.
 */
class Quotation extends Model
{
    use HasDocumentNumber;

    protected $fillable = [
        'number', 'client_name', 'client_id', 'price_list_id', 'price_list_name',
        'created_by', 'valid_until',
        'discount_pct', 'extra_pct', 'tax_pct', 'tax_inclusive',
        'subtotal', 'discount', 'net', 'tax',
        'grand', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'valid_until' => 'date',
            'discount_pct' => 'float',
            'extra_pct' => 'float',
            'tax_pct' => 'float',
            'tax_inclusive' => 'boolean',
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'net' => 'decimal:2',
            'tax' => 'decimal:2',
            'grand' => 'decimal:2',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public static function nextNumber(): string
    {
        return static::nextDocumentNumber('QT-', 1001);
    }

    /**
     * سكوب الرؤية — الأدمن بيشوف الكل، وغيره **عروضه هو بس**
     * (قرار المالك ٢١/٨: «كل مدير شايف عروض أسعاره بس»).
     */
    public function scopeVisibleTo($query, ?User $user)
    {
        if ($user === null || $user->isAdmin()) {
            return $query;
        }

        // ⚠️ **المدير بيشوف عروضه وعروض فريقه (٢٣/٨).** السكوب القديم
        // كان `created_by = هو نفسه بس` — فالمدير كان بيفتح السجل
        // يلاقيه فاضي (معظم العروض بيطلّعها الأدمن أو فريقه) ويبلّغ
        // «مش شايف عروض الأسعار». نفس عقيدة الفريق في كل السيستم.
        if (in_array($user->role, ['manager', 'branch_manager'], true)) {
            return $query->where(fn ($q) => $q
                ->where('created_by', $user->id)
                ->orWhereIn('created_by', User::where('manager_id', $user->id)->select('id')));
        }

        return $query->where('created_by', $user->id);
    }
}
