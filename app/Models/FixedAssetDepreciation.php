<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** قسط إهلاك شهر واحد لأصل — صف واحد لكل أصل لكل YYYY-MM (unique) */
class FixedAssetDepreciation extends Model
{
    protected $fillable = ['fixed_asset_id', 'period_key', 'date', 'amount', 'created_by'];

    protected function casts(): array
    {
        return ['date' => 'date', 'amount' => 'decimal:2'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
