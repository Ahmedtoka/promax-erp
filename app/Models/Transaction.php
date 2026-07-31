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
        'rebate' => 'خصم تجاري',
        'settlement' => 'تسوية/مقاصة',
        'transfer' => 'قيد تحويل',
        'taxded' => 'ضرائب مخصومة',
        // بضاعة أمانة عند العميل — مش مديونية، ملك بروماكس لحد ما تتباع
        'consignment' => 'بضاعة أمانة',
        // رصيد افتتاحي وقت استيراد العملاء — أساس كشف الحساب
        'opening' => 'رصيد افتتاحي',
    ];

    protected $fillable = [
        'client_id', 'date', 'memo', 'debit', 'credit', 'tax', 'kind',
        'source_type', 'source_id',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
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
