<?php

namespace App\Models;

use App\Models\Concerns\HasBilingualName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * فرع للشركة — مخزنه ومناطقه وفريقه وعملاؤه.
 *
 * ⚠️ **الفرع `null` معناه «مركزي / كل الفروع»** مش «محروم». الداتا
 * اللي اتعملت قبل الفروع كلها `null` وبتبان للكل، وده صح. القاعدة
 * دي متطبّقة في `Branch::scope()` وفي كل شاشة.
 */
class Branch extends Model
{
    use HasBilingualName;

    protected $fillable = [
        'code', 'name', 'name_en', 'address', 'phone',
        'manager_id', 'lat', 'lng', 'active', 'notes',
    ];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    public function zones(): HasMany
    {
        return $this->hasMany(Zone::class);
    }

    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    /**
     * تقييد أي كويري بفرع اليوزر.
     *
     * ⚠️ **المكان الوحيد** اللي بيتقرر فيه مين بيشوف إيه على مستوى
     * الفرع. لو كل شاشة كتبت الشرط بإيدها، أول شاشة تنساه بتسرّب
     * بيانات فرع لفرع تاني ومحدش يلاحظ.
     *
     * القاعدة:
     *   - الأدمن ومدير القنوات → كل حاجة
     *   - مدير فرع أو موظف فرع → فرعه + المركزي (`null`)
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     */
    public static function scope($query, ?User $user = null, string $column = 'branch_id')
    {
        $user = $user ?? auth()->user();

        if ($user === null || $user->seesAllBranches()) {
            return $query;
        }

        $branchId = $user->branch_id;

        if ($branchId === null) {
            return $query;
        }

        // ⚠️ `orWhereNull` جوه كلوجر — من غير الأقواس الشرط بيتلزق
        // على آخر `where` بس وبيسيب الباقي مفتوح.
        return $query->where(function ($q) use ($branchId, $column) {
            $q->where($column, $branchId)->orWhereNull($column);
        });
    }

    public static function nextCode(): string
    {
        $n = static::count() + 1;

        return 'BR-'.str_pad((string) $n, 2, '0', STR_PAD_LEFT);
    }
}
