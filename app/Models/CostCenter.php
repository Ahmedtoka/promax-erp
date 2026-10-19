<?php

namespace App\Models;

use App\Models\Concerns\HasBilingualName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * مركز تكلفة — بُعد على سطر القيد (`gl_lines.cost_center_id`)، مش
 * حساب في الشجرة. شجرة المحاسب فضلت زي ما هي (التصميم §٤.١).
 *
 * الهرم: الشركة `CO` ← المصنع `FAC` ← خطوط الإنتاج، والشركة ← الفروع
 * (`MAADI` أولهم). الأرباح والخسائر بالمستويات الخمسة بتتجمع منه.
 */
class CostCenter extends Model
{
    use HasBilingualName;

    public const KINDS = ['company', 'factory', 'line', 'branch'];

    public const CODE_COMPANY = 'CO';

    public const CODE_MAADI = 'MAADI';

    protected $fillable = ['code', 'name', 'name_en', 'parent_id', 'kind', 'branch_id', 'warehouse_id', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    /**
     * الجذر الواحد. ⚠️ **مابترجعش null:** بتتعمل لو مش موجودة — القواعد
     * والمعالج بيعتمدوا عليها، و`null` هنا كان هيبقى سطر قيد بلا مركز
     * في صمت.
     */
    public static function company(): self
    {
        return static::firstOrCreate(
            ['code' => self::CODE_COMPANY],
            ['name' => 'الشركة', 'name_en' => 'Company', 'kind' => 'company', 'active' => true],
        );
    }

    /** مركز المعادي — مركز البيع الافتراضي (التصميم §٣.١.٢). نفس قاعدة `company()`. */
    public static function maadi(): self
    {
        return static::firstOrCreate(
            ['code' => self::CODE_MAADI],
            ['name' => 'المعادي', 'name_en' => 'Maadi', 'kind' => 'branch',
                'parent_id' => static::company()->id, 'active' => true],
        );
    }

    /**
     * مركز المخزن: المركز اللي المخزن بيشاور عليه، وإلا المركز اللي
     * بيشاور على المخزن، وإلا مركز فرع المخزن، وإلا null.
     */
    public static function forWarehouse(?Warehouse $w): ?self
    {
        if ($w === null) {
            return null;
        }

        if ($w->cost_center_id && ($own = static::find($w->cost_center_id))) {
            return $own;
        }

        if ($row = static::where('warehouse_id', $w->id)->orderBy('id')->first()) {
            return $row;
        }

        return $w->branch_id
            ? static::where('kind', 'branch')->where('branch_id', $w->branch_id)->orderBy('id')->first()
            : null;
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** خطوط الإنتاج تحت المركز (أولاده من نوع `line`) */
    public function lines(): HasMany
    {
        return $this->children()->where('kind', 'line');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
