<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * مستند (فاتورة/أمر توريد) اتلمس أو اتخطى في تعديل أسعار.
 * سجل بس — مفيش updated_at.
 */
class PriceChangeDoc extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'price_change_docs';

    protected $fillable = [
        'run_id', 'doc_type', 'doc_id', 'number', 'client_id', 'doc_date', 'payment',
        'outcome', 'reason', 'reason_detail',
        'before_net', 'before_tax', 'before_grand', 'after_net', 'after_tax', 'after_grand',
        'lines',
    ];

    protected function casts(): array
    {
        return [
            'doc_date' => 'date',
            'lines' => 'array',
            'before_net' => 'decimal:2',
            'before_tax' => 'decimal:2',
            'before_grand' => 'decimal:2',
            'after_net' => 'decimal:2',
            'after_tax' => 'decimal:2',
            'after_grand' => 'decimal:2',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PriceChangeRun::class, 'run_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function reasonLabel(): ?string
    {
        if ($this->reason === null) {
            return null;
        }

        return __('reprice.skip_'.$this->reason, ['detail' => $this->reason_detail]);
    }

    /** المستند الأصلي (فاتورة أو أمر توريد) — null لو اتمسح */
    public function document(): Invoice|PurchaseOrder|null
    {
        return $this->doc_type === 'invoice'
            ? Invoice::find($this->doc_id)
            : PurchaseOrder::find($this->doc_id);
    }
}
