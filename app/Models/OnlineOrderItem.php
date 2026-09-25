<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** بند أوردر أونلاين — سطر من أوردر شوبيفاي زي ما جه */
class OnlineOrderItem extends Model
{
    protected $fillable = [
        'online_order_id', 'shopify_line_id', 'shopify_variant_id',
        'sku', 'title', 'product_id', 'qty', 'returned_qty', 'units_per', 'bundle', 'price', 'total',
    ];

    /**
     * مكونات البند — باندل = كذا منتج، وإلا المنتج بقطع الباك.
     * القطع هنا **للباندل الواحد**، والتجهيز بيضربها في `qty`.
     *
     * @return list<array{product_id: int, units: int}>
     */
    public function components(): array
    {
        return ShopifyProductLink::parts($this->bundle, $this->product_id, $this->units_per);
    }

    /**
     * مكونات الباندل بالمنتج — للعرض (السينك والفاتورة).
     *
     * @return list<array{product: ?Product, units: int}>
     */
    public function componentRows(): array
    {
        $parts = $this->components();
        $ids = array_diff(array_column($parts, 'product_id'), array_keys(self::$names));

        if ($ids !== []) {
            foreach (Product::whereIn('id', $ids)->get() as $p) {
                self::$names[$p->id] = $p;
            }
        }

        return array_map(fn ($c) => [
            'product' => self::$names[$c['product_id']] ?? null,
            'units' => $c['units'],
        ], $parts);
    }

    /** @var array<int, Product> ميمو للريكوست — منتجات الباندلات المعروضة */
    private static array $names = [];

    public function isBundle(): bool
    {
        return count(ShopifyProductLink::normalize($this->bundle)) > 1;
    }

    /** عدد القطع الفعلي — الكمية × قطع الباك (أو مجموع مكونات الباندل) */
    public function pieces(): int
    {
        $per = array_sum(array_column($this->components(), 'units'));

        return (int) $this->qty * max($per, 1);
    }

    /**
     * القطع اللي بتتجهز/بترجع لكل منتج — `$qty` باندلات أو باكات.
     *
     * @return array<int, int> [product_id => قطع]
     */
    public function piecesByProduct(?int $qty = null): array
    {
        $qty ??= (int) $this->qty;
        $out = [];

        foreach ($this->components() as $c) {
            $out[$c['product_id']] = ($out[$c['product_id']] ?? 0) + $qty * $c['units'];
        }

        return $out;
    }

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'total' => 'decimal:2',
            'bundle' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(OnlineOrder::class, 'online_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
