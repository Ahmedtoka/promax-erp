<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * سطر دفتر متعلّم عليه في كشف بنك. ⚠️ المرجع الثابت `line_key` مش
 * `gl_line_id` — إعادة البناء بتغيّر أرقام السطور (L4-D6).
 */
class BankStatementMark extends Model
{
    public $timestamps = false;

    protected $fillable = ['statement_id', 'line_key', 'gl_line_id', 'amount', 'line_date', 'marked_by', 'marked_at'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'line_date' => 'date', 'marked_at' => 'datetime', 'gl_line_id' => 'integer'];
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class, 'statement_id');
    }

    public function marker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by');
    }
}
