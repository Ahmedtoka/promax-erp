<?php

namespace App\Models;

use App\Models\Concerns\HasBilingualName;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Zone extends Model
{
    use HasBilingualName, HasFactory;

    protected $fillable = ['code', 'name', 'name_en', 'day_label', 'branch_id', 'governorate', 'type', 'lat', 'lng', 'active'];

    /**
     * كود منطقة جديد مضمون إنه فاضي.
     *
     * ⚠️ **مش `count()+1`.** العدّ بيتكسر أول ما منطقة تتمسح أو
     * الأكواد تتخطى العدّ — استيراد عملاء محمد هجر وقع فعلاً على
     * «Duplicate Z50» بالطريقة دي (2026-08-04). بنبدأ من أكبر رقم
     * موجود ونلف لحد ما نلاقي فاضي.
     */
    public static function nextCode(): string
    {
        // الحساب في PHP مش SQL — REGEXP_REPLACE مش موجودة في MySQL 5.7،
        // والمناطق عشرات مش ملايين
        $max = static::query()->pluck('code')
            ->map(fn ($c) => (int) preg_replace('/\D+/', '', (string) $c))
            ->max() ?? 0;

        do {
            $code = 'Z'.str_pad((string) ++$max, 2, '0', STR_PAD_LEFT);
        } while (static::where('code', $code)->exists());

        return $code;
    }

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function governorateLabel(): string
    {
        return \App\Support\Governorates::label($this->governorate);
    }

    public function branch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** المناديب المسئولين عن الزون ده */
    public function reps(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'zone_user')
            ->withPivot('visit_day')
            ->withTimestamps();
    }
}
