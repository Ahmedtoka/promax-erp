<?php

namespace App\Models;

use App\Models\Concerns\HasBilingualName;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * مخزن — المصنع أو فرع بيوزّع منه المناديب.
 * Warehouse — the factory, or a branch the reps load from.
 */
class Warehouse extends Model
{
    use HasBilingualName, HasFactory;

    public const TYPE_FACTORY = 'factory';
    public const TYPE_BRANCH = 'branch';

    protected $fillable = [
        'code', 'name', 'name_en', 'type', 'address', 'lat', 'lng', 'manager_id', 'branch_id', 'active',
    ];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function branch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class)->orderBy('stand')->orderBy('level');
    }

    public function batches(): HasMany
    {
        return $this->hasMany(Batch::class);
    }

    /**
     * أرصدة الأصناف في المخزن ده.
     *
     * ⚠️ **جديدة.** قبل كده `stocks` كانت صف واحد لكل صنف على مستوى
     * الشركة — مافيش فكرة «رصيد المخزن ده». الرقم اللي كان بيتعرض
     * على شاشة المخزن كان إجمالي الشركة.
     */
    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class)->latest();
    }

    public function pickOrders(): HasMany
    {
        return $this->hasMany(PickOrder::class)->latest();
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    /** تحويلات داخلة لهذا المخزن */
    public function incomingTransfers(): HasMany
    {
        return $this->hasMany(StockTransfer::class, 'to_warehouse_id')->latest();
    }

    public function outgoingTransfers(): HasMany
    {
        return $this->hasMany(StockTransfer::class, 'from_warehouse_id')->latest();
    }

    public function isFactory(): bool
    {
        return $this->type === self::TYPE_FACTORY;
    }

    public function typeLabel(): string
    {
        return __('stock.warehouse_type_'.$this->type);
    }

    // ==================== الأرصدة ====================

    /** إجمالي الوحدات المتاحة للبيع في المخزن ده */
    public function availableUnits(): int
    {
        return (int) BatchLocation::query()
            ->whereHas('location', fn ($q) => $q->where('warehouse_id', $this->id))
            ->whereHas('batch', fn ($q) => $q->sellable())
            ->sum('qty');
    }

    /** كمية منتج معيّن متاحة هنا */
    public function availableFor(int $productId): int
    {
        return (int) BatchLocation::query()
            ->where('product_id', $productId)
            ->whereHas('location', fn ($q) => $q->where('warehouse_id', $this->id))
            ->whereHas('batch', fn ($q) => $q->sellable())
            ->sum('qty');
    }

    /** بضاعة مستلمة ولسه مترصّفتش على رف */
    public function unshelvedUnits(): int
    {
        return (int) $this->batches()
            ->where('qty_remaining', '>', 0)
            ->get()
            ->sum(fn (Batch $b) => max($b->qty_remaining - $b->shelvedQty(), 0));
    }

    public static function defaultBranch(): ?self
    {
        return static::where('type', self::TYPE_BRANCH)->where('active', true)->orderBy('id')->first();
    }

    /**
     * المخزن اللي الرصيد الافتتاحي بينزل فيه لما محدش يحدّد.
     *
     * ⚠️ **قاعدة واحدة لكل اللي بيكتبوا رصيد من غير مخزن**: استيراد
     * المنتجات، استيراد المخزون، السيدر، وفورم الصنف. كانوا أربع
     * أماكن بتكتب `Stock` من غير `warehouse_id` خالص — واحدة تدوس على
     * صف مخزن عشوائي والتانية ترمي SQLSTATE 1364 لأن العمود بقى
     * NOT NULL من غير default.
     *
     * ⚠️ **بترجّع `null` لو مفيش مخازن** بدل ما تخترع واحد. الرصيد
     * على مخزن اتعمل تلقائياً بيبان في شاشة محدش يعرف إيه هي،
     * والمخزن الحقيقي بيفضل فاضي.
     */
    public static function defaultStockId(): ?int
    {
        return static::where('active', true)->orderBy('id')->value('id');
    }
}
