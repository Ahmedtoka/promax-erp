<?php

namespace App\Models\Gl;

use App\Models\CostCenter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * رصيد افتتاحي يدوي على حساب (ومركز تكلفة اختياري) — بيدخل قيد الافتتاح
 * الواحد (`origin=opening`) لما المعالج يرحّل الافتتاحي. مش سطر قيد بنفسه.
 */
class GlOpeningLine extends Model
{
    protected $table = 'gl_opening_lines';

    protected $fillable = ['account_id', 'cost_center_id', 'debit', 'credit', 'note', 'created_by'];

    protected function casts(): array
    {
        return ['debit' => 'decimal:2', 'credit' => 'decimal:2'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(GlAccount::class, 'account_id');
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }
}
