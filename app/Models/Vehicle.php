<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\App;

/**
 * عربية توزيع.
 *
 * ⚠️ العربية **أصل ثابت**، والعهدة بضاعة يوم. العربية بتتنقل بين
 * مندوب وتاني ورقمها مابيتغيرش — فتخزين رقم اللوحة على العهدة كان
 * هيكرّره في كل يوم ويخلّي تغييره مستحيل من مكان واحد.
 */
class Vehicle extends Model
{
    protected $fillable = [
        'plate', 'kind', 'kind_en', 'model_year', 'is_fridge',
        'odometer', 'odometer_at',
        'branch_id', 'rep_id', 'driver_id', 'active', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_fridge' => 'boolean',
            'active' => 'boolean',
            'odometer' => 'integer',
            'odometer_at' => 'datetime',
            'model_year' => 'integer',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function rep(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rep_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function custodies(): HasMany
    {
        return $this->hasMany(Custody::class);
    }

    /**
     * كل التسكينات — الجاري والقديم.
     *
     * ⚠️ `driver_id` فوق عمود «دلوقتي» بيتدعس عليه مع كل نقل.
     * التاريخ كله هنا.
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(VehicleAssignment::class)->orderByDesc('from_date');
    }

    public function readings(): HasMany
    {
        return $this->hasMany(OdometerReading::class)->orderByDesc('read_on');
    }

    /** التسكين الجاري */
    public function currentAssignment(): ?VehicleAssignment
    {
        return $this->assignments()->whereNull('to_date')->first();
    }

    /**
     * كيلومترات مشيتها في فترة.
     *
     * ⚠️ الحساب من **القرايات** مش من فرق العداد الحالي وأقدم قراية.
     * العداد الحالي ممكن يكون اتسجّل بعد الفترة بكتير، فالفرق بيحسب
     * كيلومترات مش من الفترة دي.
     */
    public function distanceBetween(string $from, string $to): ?int
    {
        $rows = $this->readings()
            ->whereBetween('read_on', [$from, $to])
            ->reorder('read_on')->orderBy('km')
            ->get(['km']);

        if ($rows->count() < 2) {
            return null;
        }

        $diff = (int) $rows->last()->km - (int) $rows->first()->km;

        return $diff >= 0 ? $diff : null;
    }

    /** العداد بصيغة معروضة */
    public function odometerLabel(): string
    {
        return $this->odometer > 0
            ? number_format((float) $this->odometer).' '.__('fleet.km')
            : '—';
    }

    /** نوع العربية باللغة الحالية */
    public function kindLabel(): string
    {
        $ar = (string) ($this->kind ?? '');

        if (App::getLocale() !== 'en') {
            return $ar;
        }

        $en = trim((string) ($this->kind_en ?? ''));

        return $en !== '' ? $en : $ar;
    }

    /** اللي على العربية دلوقتي — مندوب وسواق أو مندوب بيسوق */
    public function crewLabel(): string
    {
        $names = collect([$this->rep?->displayName(), $this->driver?->displayName()])
            ->filter()
            ->unique()
            ->all();

        return $names ? implode(' · ', $names) : '—';
    }
}
