<?php

namespace App\Models\Gl;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

/**
 * حساب في **مسودة** شجرة حسابات العميل — الملف المستلم زي ما هو.
 *
 * ⚠️ مالوش أي علاقة بـ`GlAccount` ولا بقواعد الترحيل ولا بالقيود: دي ورقة شغل
 * المالك بيظبط فيها الأسماء والأكواد والترتيب. الكود هنا ممكن يتكرر أو يبقى
 * فاضي عن قصد — الملف فيه كده، والتنضيف قراره هو.
 *
 * الربط الوحيد المسموح (`feed`): حساب «كي أكاونت» بيعرض مبيعات أوامر التوريد،
 * وحساب «كاش فان» بيعرض مبيعات فواتير العربيات — عرض بس، مفيش قيد.
 */
class CoaDraftAccount extends Model
{
    protected $table = 'coa_draft_accounts';

    protected $fillable = [
        'parent_id', 'sort', 'code', 'name', 'name_ar', 'qb_type', 'balance',
        'description', 'tax_line', 'feed', 'note', 'source_row', 'source_path',
    ];

    public const FEEDS = ['sales_ka', 'sales_van'];

    /** الحقول اللي بتتعدّل من الشاشة خانة خانة */
    public const EDITABLE = ['code', 'name', 'name_ar', 'qb_type', 'note', 'feed'];

    protected function casts(): array
    {
        return ['balance' => 'decimal:2'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort')->orderBy('id');
    }

    /** الملفات ممكن تترفع قبل `migrate` */
    public static function ready(): bool
    {
        static $ready = null;

        return $ready ??= (bool) rescue(fn () => Schema::hasTable('coa_draft_accounts'), false, false);
    }

    /** إخوات الحساب (نفس الأب) بالترتيب */
    public function siblings()
    {
        return static::where('parent_id', $this->parent_id)->orderBy('sort')->orderBy('id');
    }

    /** آخر ترتيب تحت أب معيّن + 1 */
    public static function nextSort(?int $parentId): int
    {
        return (int) static::where('parent_id', $parentId)->max('sort') + 1;
    }

    /** الحساب ده أو أي حد من ولاده هو `$id`؟ — عشان النقل مايعملش دايرة */
    public function hasDescendant(int $id): bool
    {
        $stack = [$this->id];
        $guard = 0;

        while ($stack !== [] && $guard++ < 5000) {
            $kids = static::whereIn('parent_id', $stack)->pluck('id')->all();

            if (in_array($id, $kids, true)) {
                return true;
            }

            $stack = $kids;
        }

        return false;
    }
}
