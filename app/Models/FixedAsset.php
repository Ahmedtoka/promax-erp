<?php

namespace App\Models;

use App\Models\Concerns\HasBilingualName;
use App\Models\Gl\GlAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * أصل ثابت — قسط ثابت شهري (L4-D11)، ورصيد افتتاحي للإهلاك (L4-D12).
 * حسابات الإهلاك نفسها في Task 7.
 */
class FixedAsset extends Model
{
    use HasBilingualName;

    public const STATUSES = ['active', 'disposed'];

    protected $fillable = [
        'code', 'name', 'name_en', 'category_id', 'expense_id', 'cost', 'salvage', 'life_months',
        'start_date', 'cost_center_id', 'asset_account_id', 'opening_accum', 'opening_as_of',
        'status', 'disposed_at', 'created_by',
    ];

    protected $attributes = ['status' => 'active', 'salvage' => 0, 'opening_accum' => 0];

    protected function casts(): array
    {
        return [
            'cost' => 'decimal:2', 'salvage' => 'decimal:2', 'opening_accum' => 'decimal:2',
            'life_months' => 'integer', 'start_date' => 'date', 'opening_as_of' => 'date',
            'disposed_at' => 'datetime',
        ];
    }

    /**
     * الكود التالي FA-#### — نفس منطق HasDocumentNumber (أكبر رقم + ١) بس
     * العمود هنا اسمه `code` مش `number`.
     */
    public static function nextNumber(): string
    {
        $max = (int) static::query()
            ->where('code', 'like', 'FA-%')
            ->selectRaw("MAX(CAST(SUBSTRING_INDEX(code, '-', -1) AS UNSIGNED)) as n")
            ->value('n');

        return 'FA-'.($max > 0 ? $max + 1 : 1001);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function assetAccount(): BelongsTo
    {
        return $this->belongsTo(GlAccount::class, 'asset_account_id');
    }

    public function depreciations(): HasMany
    {
        return $this->hasMany(FixedAssetDepreciation::class)->orderBy('period_key');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
