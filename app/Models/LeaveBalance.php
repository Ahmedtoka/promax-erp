<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * رصيد إجازة موظف لسنة ونوع (Lane 7 — المهمة ٣).
 * ⚠️ `used` بيزيد في الاعتماد بس (`Leave::decide` بـ`lockForUpdate`).
 */
class LeaveBalance extends Model
{
    protected $fillable = ['employee_id', 'year', 'type', 'entitled', 'used', 'note'];

    protected $attributes = ['used' => 0];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'entitled' => 'decimal:1',
            'used' => 'decimal:1',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function remaining(): float
    {
        return max(0.0, round((float) $this->entitled - (float) $this->used, 1));
    }

    public function typeLabel(): string
    {
        return __('hrm.leave_type_'.$this->type);
    }
}
