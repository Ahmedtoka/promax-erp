<?php

namespace App\Models;

use App\Models\Concerns\HasBilingualName;
use App\Models\Gl\GlAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * بند مصروف (L4-D8): نوعه، حسابه الافتراضي في الشجرة، العمر الافتراضي
 * للأصل، و`links_vehicle` للبنزين (المصروف بيتربط بعربية).
 */
class ExpenseCategory extends Model
{
    use HasBilingualName;

    public const TYPES = ['operating', 'asset', 'payroll', 'factory'];

    protected $fillable = ['code', 'name', 'name_en', 'type', 'account_id', 'life_months', 'links_vehicle', 'active'];

    protected $attributes = ['links_vehicle' => false, 'active' => true];

    protected function casts(): array
    {
        return ['life_months' => 'integer', 'links_vehicle' => 'boolean', 'active' => 'boolean'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(GlAccount::class, 'account_id');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'category_id');
    }
}
