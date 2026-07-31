<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustodyItem extends Model
{
    use HasFactory;

    protected $fillable = ['custody_id', 'product_id', 'batch_id', 'assigned', 'sold', 'returned'];

    public function custody(): BelongsTo
    {
        return $this->belongsTo(Custody::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function remaining(): int
    {
        return $this->assigned - $this->sold - $this->returned;
    }

    /** حالة صلاحية البند — البنود القديمة من غير باتش بتعتبر ok */
    public function expiryState(): string
    {
        return $this->batch?->expiryState() ?? 'ok';
    }

    public function batchLabel(): string
    {
        return $this->batch?->batch_no ?? '—';
    }
}
