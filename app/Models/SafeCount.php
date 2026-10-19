<?php

namespace App\Models;

use App\Models\Concerns\HasDocumentNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** جرد خزنة يومي (L4-D5): المتوقع من الدفتر، المعدود، والفرق = المعدود − المتوقع */
class SafeCount extends Model
{
    use HasDocumentNumber;

    public const STATUSES = ['posted', 'void'];

    protected $fillable = [
        'number', 'safe_id', 'date', 'expected', 'counted', 'difference', 'note',
        'status', 'voided_at', 'voided_by', 'created_by',
    ];

    protected $attributes = ['status' => 'posted'];

    protected function casts(): array
    {
        return [
            'date' => 'date', 'voided_at' => 'datetime',
            'expected' => 'decimal:2', 'counted' => 'decimal:2', 'difference' => 'decimal:2',
        ];
    }

    public static function nextNumber(): string
    {
        return static::nextDocumentNumber('SC-', 1001);
    }

    public function safe(): BelongsTo
    {
        return $this->belongsTo(Safe::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}
