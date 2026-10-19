<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * مواصفة منتج تام — إصدار (التصنيع ١٩/١٠، spec §3.5).
 *
 * ⚠️ **المعتمدة ماتتعدلش** — أي تعديل = إصدار جديد (`Mfg\Specs::newVersion`)،
 * والإنتاج بيفضل على المعتمدة لحد ما الجديدة تتعتمد (الأدمن بس).
 */
class ProductSpec extends Model
{
    public const STATUSES = ['draft', 'under_review', 'approved', 'archived'];

    /** مفاتيح القيم الغذائية — لكل ١٠٠ جم */
    public const NUTRITION_KEYS = ['energy_kcal', 'protein_g', 'carbs_g', 'sugar_g', 'fat_g', 'sat_fat_g', 'fiber_g', 'sodium_mg'];

    protected $fillable = [
        'product_id', 'version', 'status', 'attachment_path', 'attachment_name',
        'net_weight_g', 'shelf_life_days', 'nutrition', 'qc_limits', 'notes',
        'created_by', 'submitted_at', 'approved_by', 'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'net_weight_g' => 'decimal:3',
            'shelf_life_days' => 'integer',
            'nutrition' => 'array',
            'qc_limits' => 'array',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** المواصفة المعتمدة الحالية للمنتج — null لو مفيش */
    public static function approvedFor(Product $p): ?self
    {
        return static::query()->where('product_id', $p->id)->where('status', 'approved')
            ->orderByDesc('version')->first();
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function statusLabel(): string
    {
        return __('mfg.spec_status_'.$this->status);
    }
}
