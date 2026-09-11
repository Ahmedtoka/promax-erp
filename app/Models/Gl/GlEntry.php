<?php

namespace App\Models\Gl;

use App\Models\Concerns\HasDocumentNumber;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class GlEntry extends Model
{
    use HasDocumentNumber;

    public const ORIGINS = ['auto', 'manual', 'reversal', 'opening'];

    protected $table = 'gl_entries';

    protected $fillable = [
        'number', 'date', 'period_key', 'memo', 'origin', 'source_type', 'source_id',
        'rule_key', 'needs_review', 'reverses_entry_id', 'created_by', 'edited_at', 'edited_by',
    ];

    protected function casts(): array
    {
        return ['date' => 'date', 'needs_review' => 'bool', 'edited_at' => 'datetime'];
    }

    public static function nextNumber(): string
    {
        return static::nextDocumentNumber('JV-', 1001);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(GlLine::class, 'entry_id')->orderBy('id');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_entry_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function totalDebit(): float
    {
        return round((float) $this->lines->sum('debit'), 2);
    }

    public function totalCredit(): float
    {
        return round((float) $this->lines->sum('credit'), 2);
    }
}
