<?php

namespace App\Models;

use App\Models\Concerns\HasDocumentNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** كشف حساب بنك للتسوية (L4-D6) — العلامات في bank_statement_marks، مفيش كتابة في gl_* */
class BankStatement extends Model
{
    use HasDocumentNumber;

    public const STATUSES = ['open', 'closed'];

    protected $fillable = [
        'number', 'safe_id', 'date', 'date_from', 'date_to', 'opening_balance', 'closing_balance',
        'note', 'status', 'closed_at', 'closed_by', 'created_by',
    ];

    protected $attributes = ['status' => 'open', 'opening_balance' => 0];

    protected function casts(): array
    {
        return [
            'date' => 'date', 'date_from' => 'date', 'date_to' => 'date', 'closed_at' => 'datetime',
            'opening_balance' => 'decimal:2', 'closing_balance' => 'decimal:2',
        ];
    }

    public static function nextNumber(): string
    {
        return static::nextDocumentNumber('BST-', 1001);
    }

    public function safe(): BelongsTo
    {
        return $this->belongsTo(Safe::class);
    }

    public function marks(): HasMany
    {
        return $this->hasMany(BankStatementMark::class, 'statement_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
