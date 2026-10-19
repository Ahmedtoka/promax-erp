<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** جزاء مالي على موظف (Lane 7 — المهمة ٥). `payslip_id` بيتملى لما يتخصم من المرتب. */
class HrPenalty extends Model
{
    protected $fillable = ['employee_id', 'date', 'amount', 'reason', 'payslip_id', 'created_by'];

    protected function casts(): array
    {
        return ['date' => 'date', 'amount' => 'decimal:2'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
