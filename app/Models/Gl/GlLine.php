<?php

namespace App\Models\Gl;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GlLine extends Model
{
    protected $table = 'gl_lines';

    protected $fillable = ['entry_id', 'account_id', 'cost_center_id', 'debit', 'credit', 'memo', 'overridden', 'rule_account_id', 'slot'];

    protected function casts(): array
    {
        return ['overridden' => 'bool'];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(GlEntry::class, 'entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(GlAccount::class, 'account_id');
    }

    public function overrides(): HasMany
    {
        return $this->hasMany(GlLineOverride::class, 'line_id');
    }

    public function costCenter(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\CostCenter::class);
    }
}
