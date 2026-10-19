<?php

namespace App\Models;

use App\Models\Concerns\HasDocumentNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;

/**
 * طلب شراء — صنف واحد وكمية (لين ٥، D11). بيتجمّع عليه عروض الموردين
 * والأدمن بيختار عرض فيتحوّل لأمر شراء (`PurchaseQuote::approve`).
 */
class PurchaseRequest extends Model
{
    use HasDocumentNumber;

    public const STATUSES = ['open', 'quoting', 'ordered', 'cancelled'];

    protected $fillable = [
        'number', 'product_id', 'qty', 'needed_by', 'requested_by', 'cost_center_id',
        'status', 'notes', 'source_type', 'source_id', 'supplier_order_id', 'approved_by', 'approved_at',
    ];

    protected function casts(): array
    {
        return ['needed_by' => 'date', 'qty' => 'integer', 'approved_at' => 'datetime'];
    }

    public static function nextNumber(): string
    {
        return static::nextDocumentNumber('PR-', 1001);
    }

    /** المستند اللي طلب الشراء طالع منه (أمر إنتاج مثلاً) */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * ids الأصناف اللي ليها طلب شغّال (open|quoting) على المستند ده.
     *
     * @param  array<int,int>  $productIds
     * @return array<int,int>
     */
    public static function activeProductIds(Model $for, array $productIds): array
    {
        return static::query()
            ->where('source_type', $for::class)->where('source_id', $for->getKey())
            ->whereIn('product_id', $productIds)->whereIn('status', ['open', 'quoting'])
            ->pluck('product_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /**
     * طلب شراء لكل صنف ناقص على مستند (D14): الكمية مقرّبة لفوق، مركز التكلفة
     * = خط الأمر، الحالة open. لو فيه طلب شغّال لنفس الصنف والمستند بيتعاد
     * استخدامه (والكمية بتزيد للاحتياج الجديد وهو open بس) ومابيتكررش.
     *
     * ⚠️ لازم تتنده جوه transaction الطالب — الصفوف الموجودة بتتقفل.
     *
     * @param  array<int,array{product_id:int, qty:float|int}>  $lines
     * @return Collection<int, PurchaseRequest>
     */
    public static function raiseFor(array $lines, Model $for, User $by): Collection
    {
        $ids = array_values(array_unique(array_map(fn ($l) => (int) $l['product_id'], $lines)));

        $existing = static::query()
            ->where('source_type', $for::class)->where('source_id', $for->getKey())
            ->whereIn('product_id', $ids)->whereIn('status', ['open', 'quoting'])
            ->orderBy('id')->lockForUpdate()->get()->keyBy('product_id');

        $costCenterId = $for->getAttribute('line_id');
        $made = new Collection;

        foreach ($lines as $l) {
            $pid = (int) $l['product_id'];
            // round قبل ceil عشان 12.4000000001 مايبقاش 13 بالغلط
            $qty = max(1, (int) ceil(round((float) $l['qty'], 3)));

            $pr = $existing->get($pid);
            if ($pr) {
                if ($pr->status === 'open' && $qty > $pr->qty) {
                    $pr->forceFill(['qty' => $qty])->save();
                }
            } else {
                $pr = static::create([
                    'number' => static::nextNumber(),
                    'product_id' => $pid,
                    'qty' => $qty,
                    'requested_by' => $by->id,
                    'cost_center_id' => $costCenterId,
                    'status' => 'open',
                    'notes' => __('supplier.pr_from_order', ['number' => $for->getAttribute('number')]),
                    'source_type' => $for::class,
                    'source_id' => $for->getKey(),
                ]);
                $existing->put($pid, $pr);
            }
            $made->push($pr);
        }

        return $made;
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SupplierOrder::class, 'supplier_order_id');
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(PurchaseQuote::class);
    }

    /** لسه بيقبل عروض وإلغاء */
    public function isActive(): bool
    {
        return in_array($this->status, ['open', 'quoting'], true);
    }

    public function statusClass(): string
    {
        return match ($this->status) {
            'open' => 'b-blue',
            'quoting' => 'b-orange',
            'ordered' => 'b-green',
            default => 'b-gray',
        };
    }

    public function statusLabel(): string
    {
        return __('supplier.pr_status_'.$this->status);
    }
}
