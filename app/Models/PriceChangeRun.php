<?php

namespace App\Models;

use App\Models\Concerns\HasDocumentNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * تعديل أسعار (Lane 13) — مسودة بتتعاين وتتأكد، أو تعديل اتطبق.
 *
 * ⚠️ المسودة **مش بتغيّر أي حاجة** — المعاينة بتشتغل جوه transaction
 * بترجع في الآخر. الأرقام المتخزنة في `preview` هي اللي التأكيد
 * بيقارن بيها قبل ما يكتب.
 */
class PriceChangeRun extends Model
{
    use HasDocumentNumber;

    protected $table = 'price_change_runs';

    protected $fillable = [
        'number', 'user_id', 'trigger', 'status',
        'price_list_id', 'group_id', 'client_id', 'invoice_id',
        'scope', 'since', 'payload', 'item_changes', 'preview', 'summary',
        'clients_count', 'invoices_changed', 'pos_changed', 'skipped_count', 'unchanged_count',
        'before_net', 'before_tax', 'before_grand', 'after_net', 'after_tax', 'after_grand',
        'ledger_diff', 'error', 'applied_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $run) {
            if (empty($run->number)) {
                $run->number = static::nextNumber();
            }
        });
    }

    public static function nextNumber(): string
    {
        return static::nextDocumentNumber('PCR-', 1001);
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'item_changes' => 'array',
            'preview' => 'array',
            'summary' => 'array',
            'since' => 'date',
            'applied_at' => 'datetime',
            'before_net' => 'decimal:2',
            'before_tax' => 'decimal:2',
            'before_grand' => 'decimal:2',
            'after_net' => 'decimal:2',
            'after_tax' => 'decimal:2',
            'after_grand' => 'decimal:2',
            'ledger_diff' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ClientGroup::class, 'group_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function docs(): HasMany
    {
        return $this->hasMany(PriceChangeDoc::class, 'run_id');
    }

    public function triggerLabel(): string
    {
        return __('reprice.trigger_'.$this->trigger);
    }

    public function statusLabel(): string
    {
        return __('reprice.status_'.$this->status);
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }
}
