<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * أمر إنتاج (التصنيع ١٩/١٠، spec §3.5):
 * مخطط ← جاري ← انتهى ← مقفول (باتش تام في الحجر) ← إفراج الجودة.
 *
 * ⚠️ الصرف من مخزن الخامات (`raw_warehouse_id`) عن طريق `RawStock::issue`
 * بس، والباتش التام بيتعمل في مخزن التام (`warehouse_id`) موقوف لحد قرار الجودة.
 */
class ProductionOrder extends Model
{
    use \App\Models\Concerns\HasDocumentNumber;

    public const STATUSES = ['planned', 'in_progress', 'finished', 'closed', 'cancelled'];

    public const OPEN_STATUSES = ['planned', 'in_progress', 'finished'];

    public const QC = ['quarantine', 'released', 'rejected'];

    protected $fillable = [
        'number', 'product_id', 'bom_id', 'line_id', 'warehouse_id', 'raw_warehouse_id',
        'batch_no', 'planned_qty', 'output_qty', 'status', 'planned_on',
        'started_at', 'finished_at', 'closed_at', 'started_by', 'finished_by', 'closed_by', 'created_by',
        'produced_on', 'expires_on', 'expiry_source', 'fg_batch_id',
        'material_cost', 'std_material_cost', 'overhead_cost', 'total_cost', 'unit_cost',
        'hours', 'overhead_method', 'overhead_flag', 'waste_pct', 'yield_pct',
        'qc_status', 'qc_note', 'qc_results', 'qc_by', 'qc_at',
        'shortage_requested_at', 'purchase_request_id', 'notes',
    ];

    protected $attributes = [
        'status' => 'planned',
    ];

    protected function casts(): array
    {
        return [
            'planned_qty' => 'integer',
            'output_qty' => 'integer',
            'planned_on' => 'date',
            'produced_on' => 'date',
            'expires_on' => 'date',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'closed_at' => 'datetime',
            'qc_at' => 'datetime',
            'shortage_requested_at' => 'datetime',
            'material_cost' => 'decimal:2',
            'std_material_cost' => 'decimal:2',
            'overhead_cost' => 'decimal:2',
            'overhead_rate' => 'decimal:4',
            'overhead_kg' => 'decimal:3',
            'total_cost' => 'decimal:2',
            'unit_cost' => 'decimal:4',
            'hours' => 'decimal:2',
            'waste_pct' => 'decimal:2',
            'yield_pct' => 'decimal:2',
            'qc_results' => 'array',
        ];
    }

    /** رقم الأمر الجاي: MO-1001، MO-1002… (promax-feature-dev — توليد الأرقام) */
    public static function nextNumber(): string
    {
        return static::nextDocumentNumber('MO-', 1001);
    }

    /** لسه شغّال (مخطط/جاري/انتهى ومستني القفل) */
    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /**
     * صافي المصروف لكل خامة: صرف + زيادة − مرتجع.
     *
     * ⚠️ المصدر الوحيد للصافي — `Requirement::issuedNet` و`Orders::cancel`
     * و`Mfg\Issues` كلهم بيقروا من هنا. بيقرا من الداتابيز كل مرة (مش من
     * الريليشن المتحمّلة) عشان جوه الترانزاكشن يشوف آخر حركة.
     *
     * @return array<int, float> product_id ⇒ qty
     */
    public function netIssuedAll(): array
    {
        return ProductionIssue::query()->where('production_order_id', $this->id)
            ->groupBy('product_id')
            ->select('product_id', DB::raw(self::NET_QTY.' AS net'))
            ->pluck('net', 'product_id')
            ->map(fn ($v) => round((float) $v, 3))
            ->all();
    }

    /** صافي المصروف من خامة واحدة */
    public function netIssued(int $productId): float
    {
        return $this->netIssuedAll()[$productId] ?? 0.0;
    }

    /**
     * صافي المصروف من خامة لكل باتش خامة (الموجب بس) — المرتجع بيرجع
     * للباتشات دي بالظبط (D4).
     *
     * @return array<int, float> batch_id ⇒ qty
     */
    public function netIssuedByBatch(int $productId): array
    {
        return ProductionIssue::query()->where('production_order_id', $this->id)
            ->where('product_id', $productId)
            ->groupBy('batch_id')
            ->select('batch_id', DB::raw(self::NET_QTY.' AS net'))
            ->pluck('net', 'batch_id')
            ->map(fn ($v) => round((float) $v, 3))
            ->filter(fn (float $v) => $v > 0.0005)
            ->all();
    }

    /** تكلفة الخامات الفعلية = قيمة الصرف والزيادة − قيمة المرتجع */
    public function materialCost(): float
    {
        return round((float) ProductionIssue::query()->where('production_order_id', $this->id)
            ->sum(DB::raw("CASE WHEN kind = 'return' THEN -value ELSE value END")), 2);
    }

    /** المرتجع بيتطرح — الكمية في `production_issues` موجبة دايماً */
    private const NET_QTY = "SUM(CASE WHEN kind = 'return' THEN -qty ELSE qty END)";

    public function statusLabel(): string
    {
        return __('mfg.order_status_'.$this->status);
    }

    public function qcLabel(): string
    {
        return $this->qc_status ? __('mfg.qc_status_'.$this->qc_status) : '—';
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function bom(): BelongsTo
    {
        return $this->belongsTo(Bom::class);
    }

    /** خط الإنتاج = مركز تكلفة نوعه `line` */
    public function line(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class, 'line_id');
    }

    /** مخزن التام */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** مخزن الخامات — مصدر الصرف */
    public function rawWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'raw_warehouse_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ProductionOrderLine::class)->orderBy('id');
    }

    public function issues(): HasMany
    {
        return $this->hasMany(ProductionIssue::class)->orderBy('id');
    }

    public function fgBatch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'fg_batch_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
