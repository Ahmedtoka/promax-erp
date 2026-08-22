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
        'number', 'client_name', 'client_id', 'created_by', 'valid_until',
        'discount_pct', 'tax_pct', 'subtotal', 'discount', 'net', 'tax',
        'grand', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'valid_until' => 'date',
            'discount_pct' => 'float',
            'tax_pct' => 'float',
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

        return $query->where('created_by', $user->id);
    }
}
