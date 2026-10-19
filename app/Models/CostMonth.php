<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** شهر تكاليف مقفول — صف واحد لكل YYYY-MM (الخط ٩، D9) */
class CostMonth extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['closed_at' => 'datetime', 'summary' => 'array'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CostMonthLine::class);
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
