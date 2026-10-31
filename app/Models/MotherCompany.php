<?php

namespace App\Models;

use App\Models\Concerns\HasBilingualName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * الشركة الأم — بتجمع أكتر من سلسلة، وكل سلسلة بفروعها.
 *
 * ⚠️ تجميعة عرض بس: مالهاش عقد ولا خصم، وأرقامها مجموع أرقام الفروع.
 */
class MotherCompany extends Model
{
    use HasBilingualName;

    protected $fillable = ['code', 'name', 'name_en', 'notes', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function groups(): HasMany
    {
        return $this->hasMany(ClientGroup::class, 'mother_company_id');
    }

    /**
     * السلسلة الأساسية: اللي اسمها نفس اسم الشركة (زي «Circle K»).
     * فروعها بتتوزع على سلاسل الشركة لحد ما تفضى.
     */
    public function mainChainId(): ?int
    {
        $names = array_values(array_filter([$this->name, $this->name_en]));

        return ClientGroup::whereIn('name', $names)->orWhereIn('name_en', $names)->orderBy('id')->value('id');
    }

    /** السلاسل الأساسية لكل الشركات الأم */
    public static function mainChainIds(): array
    {
        return static::all()->map->mainChainId()->filter()->unique()->values()->all();
    }

    /** كود فريد من الاسم — نفس فكرة `ClientGroup::nextCode` */
    public static function nextCode(string $name): string
    {
        $slug = trim(strtoupper(preg_replace('/[^A-Za-z0-9]+/', '-', trim($name)) ?? ''), '-');
        $slug = mb_substr($slug !== '' ? $slug : 'MC-'.strtoupper(substr(md5($name), 0, 6)), 0, 34);
        $code = $slug;

        for ($i = 2; static::where('code', $code)->exists(); $i++) {
            $code = $slug.'-'.$i;
        }

        return $code;
    }
}
