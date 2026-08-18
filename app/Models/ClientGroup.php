<?php

namespace App\Models;

use App\Models\Concerns\HasBilingualName;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * سلسلة / مجموعة عملاء — زي Circle K وجورميه وبونجور
 */
class ClientGroup extends Model
{
    use HasBilingualName, HasFactory;

    protected $fillable = [
        'code', 'name', 'name_en', 'channel_id', 'sub_channel',
        'notes', 'active', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /** عقد السلسلة — كل فروعها بتورثه لو مالهاش عقد خاص */
    public function contract(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Contract::class, 'group_id');
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class, 'group_id');
    }

    // ---------- تجميعات ----------

    public function branchesCount(): int
    {
        return $this->clients()->count();
    }

    public function purchases(): float
    {
        return (float) $this->clients()->sum('purchases');
    }

    public function collections(): float
    {
        return (float) $this->clients()->sum('collections');
    }

    public function balance(): float
    {
        return (float) $this->clients()->sum('balance');
    }

    public function returns(): float
    {
        return (float) $this->clients()->sum('returns');
    }

    public function collectionRate(): float
    {
        $p = $this->purchases();

        return $p > 0 ? $this->collections() / $p : 0;
    }

    public function subChannelLabel(): ?string
    {
        return Channel::subChannelLabel($this->sub_channel);
    }


    /**
     * كود ثابت (deterministic) للسلسلة — مهم يفضل ثابت علشان الـ seeder
     * بيستخدم updateOrCreate على الكود ومايعملش نسخ مكررة كل مرة.
     * الأسماء العربية مالهاش حروف لاتينية فبنستخدم hash قصير بدل ما الكل يطلع 'GRP'.
     */
    /**
     * كود سلسلة فريد من اسمها.
     *
     * ⚠️ الأكواد بتتولّد من الاسم بعد تنضيفه، و«Metro 1» و«Metro-1»
     * بيدّوا نفس الكود. العمود unique، فالتصادم بيرمي استثناء بيرجّع
     * استيراد العملاء كله. بنزوّد لاحقة لحد ما نلاقي كود فاضي.
     */
    public static function nextCode(string $name): string
    {
        $slug = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '-', trim($name)) ?? '');
        $slug = trim($slug, '-');

        if ($slug === '') {
            $slug = 'GRP-'.strtoupper(substr(md5($name), 0, 6));
        }

        $slug = mb_substr($slug, 0, 34);
        $code = $slug;

        for ($i = 2; static::where('code', $code)->exists() && $i < 500; $i++) {
            $code = $slug.'-'.$i;
        }

        return mb_substr($code, 0, 40);
    }
}
