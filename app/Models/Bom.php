<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * قايمة مواد (BOM) لمنتج تام — إصدار (التصنيع ١٩/١٠، spec §3.5).
 *
 * ⚠️ **الأوامر بتاخد المعتمدة بس** (`approvedFor`)، والمعتمدة ماتتعدلش —
 * التعديل إصدار جديد. السطور خامات وتعبئة بس (`kind ∈ raw|packaging`).
 */
class Bom extends Model
{
    public const STATUSES = ['draft', 'approved', 'archived'];

    protected $fillable = [
        'product_id', 'version', 'status', 'waste_pct', 'notes',
        'created_by', 'approved_by', 'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'waste_pct' => 'decimal:2',
            'approved_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BomLine::class)->orderBy('sort')->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** الـBOM المعتمدة الحالية للمنتج — الوحيدة اللي الأوامر بتستخدمها */
    public static function approvedFor(Product $p): ?self
    {
        return static::query()->where('product_id', $p->id)->where('status', 'approved')
            ->orderByDesc('version')->first();
    }

    /**
     * الكمية المعيارية لسطر بكمية إنتاج معيّنة، بنسبة الهالك:
     * الأساس × (١ + الهالك ÷ ١٠٠)، مقرّبة لـ٣ أرقام.
     */
    public function stdFor(BomLine $l, int $outputQty): float
    {
        return round($l->baseFor($outputQty) * (1 + (float) $this->waste_pct / 100), 3);
    }

    public function statusLabel(): string
    {
        return __('mfg.bom_status_'.$this->status);
    }
}
