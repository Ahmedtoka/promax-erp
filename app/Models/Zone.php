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

    protected $fillable = ['code', 'name', 'name_en', 'day_label', 'branch_id', 'governorate', 'active'];

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
