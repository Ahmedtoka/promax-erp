<?php

namespace App\Models;

use App\Services\Costing\CostingSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * قيد تكلفة مشتق (الخط ٩): صف لكل أصل (فاتورة/مرتجع، تحويل مستلم، أمر إنتاج
 * مقفول، انحراف شهر). المفتاح `(kind, origin_type, origin_id)` — بيتحدّث في
 * مكانه فالـid ثابت. المبلغ بالطريقتين متخزّن والرسمي بيتختار وقت الترحيل.
 *
 * ⚠️ **اتفاق الإشارة (المهمة ٣):** الكميات والمبالغ (`amount_*`, `qty`)
 * موجبة دايماً حتى في المرتجع. `revenue` موجب للبيع و**سالب للمرتجع**
 * (على القيد وعلى سطوره) — صافي المبيعات = Σ revenue مباشرة. اتجاه الدفتر
 * للمرتجع بيتقلب في `App\Services\Costing\CostGl` (مدين المخزون / دائن
 * التكلفة). البنود التالفة في المرتجع مالهاش سطر (D6).
 * الأصل للبيع والمرتجع = صف `transactions` (origin_type = كلاس `Transaction`).
 */
class CostEntry extends Model
{
    public const KINDS = ['sale', 'return', 'transfer', 'production', 'variance'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'qty' => 'decimal:3', 'revenue' => 'decimal:2',
            'amount_batch' => 'decimal:2', 'amount_wavg' => 'decimal:2', 'amount_transfer' => 'decimal:2',
            'materials' => 'decimal:2', 'overhead' => 'decimal:2',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CostLine::class);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class, 'cost_center_id');
    }

    public function otherCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class, 'other_center_id');
    }

    public function origin(): MorphTo
    {
        return $this->morphTo('origin', 'origin_type', 'origin_id');
    }

    /** مبلغ التكلفة بطريقة معيّنة: batch | wavg */
    public function amount(string $method): float
    {
        return round((float) ($method === 'wavg' ? $this->amount_wavg : $this->amount_batch), 2);
    }

    /** المبلغ بالطريقة الرسمية من الإعدادات */
    public function official(): float
    {
        return $this->amount(CostingSettings::method());
    }

    public function hasFlag(string $flag): bool
    {
        return in_array($flag, array_filter(explode(',', (string) $this->flags)), true);
    }
}
