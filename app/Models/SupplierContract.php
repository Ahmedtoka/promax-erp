<?php

namespace App\Models;

use App\Models\Concerns\HasDocumentNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * ═══════════════════════════════════════════════════════════════
 * عقد مورد — أسعار ثابتة لأصناف في فترة سريان (لين ٥، D5)
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ العقد «سارٍ» لو `active` والتاريخ بين `starts_on` و`ends_on`
 * (الطرفين شاملين). لو عقدين متداخلين على نفس الصنف، الأحدث
 * `starts_on` هو اللي بيحكم.
 */
class SupplierContract extends Model
{
    use HasDocumentNumber;

    protected $fillable = [
        'number', 'supplier_id', 'starts_on', 'ends_on', 'attachment_path',
        'notes', 'active', 'created_by',
    ];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'active' => 'boolean'];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SupplierContractItem::class);
    }

    public static function nextNumber(): string
    {
        return static::nextDocumentNumber('SCON-', 1001);
    }

    public function isLive(?Carbon $on = null): bool
    {
        return $this->statusKey($on) === 'live';
    }

    /** live | expired | future | inactive */
    public function statusKey(?Carbon $on = null): string
    {
        if (! $this->active) {
            return 'inactive';
        }
        $d = ($on ?? now())->copy()->startOfDay();
        if ($d->lt($this->starts_on->copy()->startOfDay())) {
            return 'future';
        }
        if ($d->gt($this->ends_on->copy()->startOfDay())) {
            return 'expired';
        }

        return 'live';
    }

    public function statusClass(?Carbon $on = null): string
    {
        return match ($this->statusKey($on)) {
            'live' => 'b-green',
            'future' => 'b-blue',
            'inactive' => 'b-red',
            default => 'b-gray',
        };
    }

    public function statusLabel(?Carbon $on = null): string
    {
        return __('supplier.contract_status_'.$this->statusKey($on));
    }

    /** سطر السعر الساري للمورد/الصنف في تاريخ معيّن (الأحدث بداية يكسب) */
    public static function liveItemFor(int $supplierId, int $productId, CarbonInterface|string $on): ?SupplierContractItem
    {
        $day = ($on instanceof CarbonInterface ? $on : Carbon::parse($on))->toDateString();

        return SupplierContractItem::query()
            ->join('supplier_contracts', 'supplier_contracts.id', '=', 'supplier_contract_items.supplier_contract_id')
            ->where('supplier_contract_items.product_id', $productId)
            ->where('supplier_contracts.supplier_id', $supplierId)
            ->where('supplier_contracts.active', true)
            ->whereDate('supplier_contracts.starts_on', '<=', $day)
            ->whereDate('supplier_contracts.ends_on', '>=', $day)
            ->orderByDesc('supplier_contracts.starts_on')
            ->orderByDesc('supplier_contracts.id')
            ->select('supplier_contract_items.*')
            ->first();
    }

    /** الصنف ظاهر في أي عقد للمورد (سارٍ أو لا) */
    public static function anyItemFor(int $supplierId, int $productId): bool
    {
        return SupplierContractItem::query()
            ->where('product_id', $productId)
            ->whereHas('contract', fn ($q) => $q->where('supplier_id', $supplierId))
            ->exists();
    }
}
