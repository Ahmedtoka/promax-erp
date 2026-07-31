<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Stock extends Model
{
    use HasFactory;

    protected $fillable = ['product_id', 'warehouse_id', 'qty', 'hold_qty', 'good_qty', 'counted_at'];

    protected function casts(): array
    {
        return ['counted_at' => 'date'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * ⚠️ **الصف بقى لكل (صنف، مخزن).** قبل كده كان صف واحد لكل صنف
     * معناه «الشركة كلها عندها كام» — من غير أي فكرة عن المكان.
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * ⚠️ **السليم = الإجمالي − الهولد.** الحساب هنا مش في كل شاشة:
     * لما كان بيتكتب بالإيد، شاشة كانت بتنساه والرقم يطلع مختلف عن
     * شاشة تانية لنفس الصنف.
     */
    public function syncGood(): void
    {
        $this->good_qty = max(0, (int) $this->qty - (int) $this->hold_qty);
    }

    public function value(): float
    {
        return $this->qty * $this->product->sellingPrice();
    }
}
