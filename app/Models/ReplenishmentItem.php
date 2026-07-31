<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReplenishmentItem extends Model
{
    use HasFactory;

    protected $fillable = ['replenishment_request_id', 'product_id', 'qty'];

    public function request(): BelongsTo
    {
        return $this->belongsTo(ReplenishmentRequest::class, 'replenishment_request_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
