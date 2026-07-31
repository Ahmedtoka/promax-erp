<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * خطة توزيع كمية على باتشات — ناتج BatchAllocator::plan().
 * Allocation plan produced by BatchAllocator::plan().
 *
 * ملف مستقل عشان الـ PSR-4 يلاقيه (ممنوع كلاسين في ملف واحد).
 */
class AllocationPlan
{
    /**
     * @param  array<int, array{0: Batch, 1: int}>  $lines
     */
    public function __construct(
        public readonly int $productId,
        public readonly int $requested,
        public readonly array $lines,
        public readonly int $shortage,
    ) {}

    public function isSatisfied(): bool
    {
        return $this->shortage === 0;
    }

    /** الباتشات المستخدمة كـ Collection للعرض */
    public function batches(): Collection
    {
        return collect($this->lines)->map(fn ($line) => [
            'batch' => $line[0],
            'qty' => $line[1],
        ]);
    }

    /** أقرب تاريخ انتهاء في الخطة */
    public function earliestExpiry(): ?string
    {
        $first = $this->lines[0][0] ?? null;

        return $first?->expires_on?->toDateString();
    }

    /** الباتش الأساسي — أول باتش في الترتيب (الأقرب انتهاءً) */
    public function primaryBatch(): ?Batch
    {
        return $this->lines[0][0] ?? null;
    }

    public function productName(): string
    {
        return Product::find($this->productId)?->displayName() ?? "#{$this->productId}";
    }
}
