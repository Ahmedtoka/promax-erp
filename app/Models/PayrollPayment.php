<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** صرف مسير مرتبات من خزنة (كاش) أو بنك (Lane 7 — المهمة ٦). صف لكل طريقة. */
class PayrollPayment extends Model
{
    protected $fillable = ['payroll_run_id', 'safe_id', 'kind', 'date', 'amount', 'paid_by'];

    protected function casts(): array
    {
        return ['date' => 'date', 'amount' => 'decimal:2'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function safe(): BelongsTo
    {
        return $this->belongsTo(Safe::class);
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }
}
