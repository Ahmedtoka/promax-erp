<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** حوض تكاليف خط إنتاج في شهر مقفول، وهو أصل قيد الانحراف (kind = variance) */
class CostMonthLine extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'direct' => 'decimal:2', 'shared' => 'decimal:2', 'payroll' => 'decimal:2',
            'depreciation' => 'decimal:2', 'pool' => 'decimal:2', 'applied' => 'decimal:2',
            'variance' => 'decimal:2', 'hours' => 'decimal:2', 'output_kg' => 'decimal:3',
            'rate' => 'decimal:4',
        ];
    }

    public function month(): BelongsTo
    {
        return $this->belongsTo(CostMonth::class, 'cost_month_id');
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class, 'line_id');
    }
}
