<?php

namespace App\Models;

use App\Models\Concerns\HasBilingualName;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasBilingualName, HasFactory;
    
    public const FAMILIES = [
        'promax_bar' => 'بروماكس بار',
        'promax_cup' => 'بروكب',
        'spreads' => 'سبريدز',
        'pmx_bar' => 'PMX بار',
        'energy_bar' => 'إنرچي بار',
    ];

    /** مدة الصلاحية الافتراضية بالشهور لو المنتج مش متحدد له */
    public const DEFAULT_SHELF_LIFE = 12;

    /**
     * مدة الصلاحية بالشهور حسب العائلة.
     *
     * ⚠️ **كانت مكتوبة في `Gs1CatalogueSeeder` لوحده.** أي كود تاني
     * بيعمل منتج كان بيسيب الخانة فاضية، والصنف بياخد 12 شهر
     * افتراضي — والكوب الحقيقي 9. الفرق ده بيخلّي السيستم يقول إن
     * بضاعة سليمة وهي منتهية بـ3 شهور.
     */
    public const SHELF_LIFE = [
        'promax_bar' => 12,
        'pmx_bar' => 12,
        'energy_bar' => 12,
        'promax_cup' => 9,
        'spreads' => 18,
    ];

    protected $fillable = [
        'code', 'barcode', 'case_barcode', 'units_per_case', 'box_units',
        'name', 'name_en', 'unit', 'unit_en',
        'cost', 'price_old', 'price_new', 'price_changed_at',
        'net_content', 'net_uom', 'family', 'brand',
        'image_url', 'image_path', 'gpc_category',
        'description', 'description_en', 'shelf_life_months',
        'active', 'taxable', 'tax_rate', 'eta_code',
    ];

    protected function casts(): array
    {
        return [
            'taxable' => 'boolean',
            'tax_rate' => 'decimal:4',
            'cost' => 'decimal:2',
            'price_old' => 'decimal:2',
            'price_new' => 'decimal:2',
            'price_changed_at' => 'date',
            'net_content' => 'decimal:2',
            'active' => 'boolean',
        ];
    }

    /**
     * ═══════════════════════════════════════════════════════════
     * الصنف القابل للتداول  ·  ١٧ أغسطس ٢٠٢٦
     * ═══════════════════════════════════════════════════════════
     *
     * طلب المالك: «المنتجات بدون سعر — نعملها درافت، ماتظهرش في أي
     * حتة في الكون، ولا في شاشات تسعير ولا بيع، غير لما تتعمل أكتيف».
     *
     * ⚠️ **ليه سكوب مش `->where('active', true)` كل مرة.** الشرط ده
     * كان **متكرر بالإيد في ١٥ مكان** — واتنسي في ١٢ مكان تانيين
     * (أودِت ١٧/٨). أي فلتر بيتكتب بالنسخ بيتنسى، والنسيان هنا معناه
     * صنف درافت بيتباع فعلاً. التعريف بقى في مكان واحد.
     *
     * ⚠️ **مش جلوبال سكوب عن قصد.** الجلوبال كان هيخبّي الصنف من
     * سطور الفواتير القديمة كمان (`with('items.product')`)، والفاتورة
     * المطبوعة تطلع بسطر فاضي وإجماليها مش مطابق لسطورها. المنع على
     * **الاختيار والبيع**، مش على قراءة التاريخ.
     */
    public function scopeSellable($query)
    {
        return $query->where('active', true);
    }

    /** ينفع يتباع/يتختار دلوقتي؟ — نفس شرط `scopeSellable` */
    public function isSellable(): bool
    {
        return (bool) $this->active;
    }

    /**
     * أرصدة الصنف — **صف لكل مخزن**.
     *
     * ⚠️ **`stock()` المفردة اتشالت.** كانت `HasOne` بترجّع صف واحد
     * معناه «الشركة كلها عندها كام» من غير أي فكرة عن المكان. مع
     * مخزنين بقى الرقم ده بيكدب: المخزن بيطلب بضاعة موجودة عنده،
     * أو بيقول إنه فاضي وهو مليان والرقم بتاع المخزن التاني.
     *
     * أي كود بيقرا الإجمالي لازم يستخدم `qtyTotal()` وأخواتها —
     * وأي كود بيقرا مخزن معيّن يستخدم `stockIn()`.
     */
    /**
     * أسعار الصنف في كل القوايم.
     *
     * ⚠️ اسمها `prices` مش `priceList` — الصنف بيبقى في كل القوايم،
     * مش في واحدة.
     */
    public function prices(): HasMany
    {
        return $this->hasMany(PriceListItem::class);
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class);
    }

    /** رصيد الصنف في مخزن واحد */
    public function stockIn(int|Warehouse|null $warehouse): ?Stock
    {
        $id = $warehouse instanceof Warehouse ? $warehouse->id : $warehouse;

        if ($id === null) {
            return null;
        }

        // ⚠️ `firstWhere` على المجموعة المحمّلة مش كويري جديدة —
        // الشاشة بتلف على 31 صنف × مخزنين، وكويري لكل خانة معناها
        // 62 كويري في الصفحة.
        return $this->relationLoaded('stocks')
            ? $this->stocks->firstWhere('warehouse_id', $id)
            : $this->stocks()->where('warehouse_id', $id)->first();
    }

    /**
     * الإجمالي عبر كل المخازن.
     *
     * ⚠️ بتجمع من العلاقة المحمّلة. لو مش محمّلة بتعمل كويري —
     * فأي قايمة منتجات لازم `->with('stocks')`.
     */
    public function qtyTotal(): int
    {
        return (int) $this->stocks->sum('qty');
    }

    public function holdTotal(): int
    {
        return (int) $this->stocks->sum('hold_qty');
    }

    public function goodTotal(): int
    {
        return (int) $this->stocks->sum('good_qty');
    }

    /** كمية الصنف في مخزن معيّن — صفر لو مالوش صف هناك */
    public function qtyIn(int|Warehouse|null $warehouse): int
    {
        return (int) ($this->stockIn($warehouse)->qty ?? 0);
    }

    /**
     * الصورة اللي بتتعرض — المرفوعة بتغلب.
     *
     * ⚠️ **الترتيب مقصود.** `image_url` جاي من فيد GS1 على سيرفر
     * خارجي، وصنف واحد بس من 31 عنده رابط — والباقي فاضي. المرفوع
     * هو اللي المستخدم شافه واختاره، والرابط الخارجي ممكن يقع في
     * أي وقت من غير ما حد ياخد باله.
     */
    public function imageSrc(): ?string
    {
        if ($this->image_path) {
            // ⚠️ `asset('storage/...')` بيحتاج `php artisan storage:link`.
            // من غيره الصورة بتطلع 404 والمستخدم بيفتكر إن الرفع فشل.
            return asset('storage/'.$this->image_path);
        }

        return $this->image_url ?: null;
    }

    /** الصورة مرفوعة من عندنا ولا جاية من GS1؟ */
    public function imageIsOurs(): bool
    {
        return (bool) $this->image_path;
    }

    /** الوصف باللغة الحالية */
    public function descriptionLabel(): ?string
    {
        $text = $this->localized('description');

        return trim((string) $text) !== '' ? $text : null;
    }

    public function batches(): HasMany
    {
        return $this->hasMany(Batch::class);
    }

    public function familyLabel(): string
    {
        // المسمى من جدول العائلات (بكاش) — و lang/الثوابت fallback
        return \App\Models\ProductFamily::label($this->family);
    }

    /** الوحدة باللغة الحالية */
    public function unitLabel(): string
    {
        return $this->localized('unit');
    }

    /** الوزن بالشكل الكامل: "70 g" */
    public function netLabel(): ?string
    {
        if (blank($this->net_content)) {
            return null;
        }

        return rtrim(rtrim(number_format((float) $this->net_content, 2, '.', ''), '0'), '.')
            .' '.($this->net_uom ?: 'g');
    }

    /**
     * سعر القائمة — old أو new. مفيش خصم هنا.
     * ⚠️ لسعر عميل استخدم Pricing::unitPrice($client, $product).
     */
    public function priceFor(string $list): float
    {
        return \App\Services\Pricing::byList($this, $list);
    }

    /** سعر البيع المعتمد افتراضياً — الجديد */
    public function sellingPrice(string $list = \App\Services\Pricing::LIST_NEW): float
    {
        return \App\Services\Pricing::listPrice($this, $list);
    }

    /** هامش الربح على السعر الجديد */
    public function marginPct(string $list = \App\Services\Pricing::LIST_NEW): float
    {
        return \App\Services\Pricing::marginPct($this, $list);
    }

    /** السعر اتغير؟ يعني القديم مش زي الجديد */
    public function priceChanged(): bool
    {
        return (float) $this->price_old !== (float) $this->price_new;
    }

    /** الفرق بين الجديد والقديم بالنسبة المئوية */
    public function priceDeltaPct(): float
    {
        $old = (float) $this->price_old;

        return $old > 0 ? round(((float) $this->price_new - $old) / $old, 4) : 0.0;
    }

    public function shelfLife(): int
    {
        // الدوكترين (2026-08-06): **العائلة هي مصدر مدة الصلاحية —
        // ولما تكون محددة بتغلب أي حاجة.** خانة المنتج القديمة
        // (shelf_life_months، من السيدر) بتشتغل بس لو العائلة لسه
        // ماتحددلهاش مدة — كانت الأولوية معكوسة فالمالك ظبط العائلة
        // على 12 وفضلت الشاشات تقول 18 من خانات المنتجات (2026-08-06).
        return (int) (\App\Models\ProductFamily::monthsFor($this->family)
            ?: $this->shelf_life_months
            ?: (self::SHELF_LIFE[$this->family] ?? self::DEFAULT_SHELF_LIFE));
    }

    /** تاريخ الانتهاء المتوقع من تاريخ إنتاج */
    public function expiryFrom(\DateTimeInterface|string $producedOn): \Carbon\Carbon
    {
        return \Carbon\Carbon::parse($producedOn)->addMonths($this->shelfLife());
    }

    // ==================== المخزون والباتشات ====================

    /** المتاح للبيع فعلياً = مجموع الباتشات السليمة */
    public function availableQty(): int
    {
        return (int) $this->batches()->sellable()->sum('qty_remaining');
    }

    /** أقرب باتش انتهاءً — ده اللي هيخرج الأول (FEFO) */
    public function nextBatch(): ?Batch
    {
        return $this->batches()->sellable()->first();
    }

    /** أسوأ حالة صلاحية في المخزن — للتنبيه على شاشة المخزون */
    public function worstExpiryState(): string
    {
        $batch = $this->batches()
            ->where('qty_remaining', '>', 0)
            ->orderBy('expires_on')
            ->first();

        return $batch?->expiryState() ?? 'ok';
    }

    /** البحث بالباركود — وحدة أو كرتونة */
    public static function findByBarcode(string $barcode): ?self
    {
        $barcode = trim($barcode);

        return static::where('barcode', $barcode)
            ->orWhere('case_barcode', $barcode)
            ->first();
    }

    /** الباركود ده بتاع كرتونة؟ يبقى الكمية × عدد الوحدات */
    public function unitsForBarcode(string $barcode): int
    {
        return trim($barcode) === $this->case_barcode
            ? (int) ($this->units_per_case ?: 1)
            : 1;
    }

    // ═══════════════ وحدات الإدخال — قرار المالك 2026-08-04 ═══════════════
    //
    // ⚠️ **المخزون بالقطعة دايماً.** العلبة والكرتونة مجرد مضاعِف عند
    // الإدخال (استلام / تسليم عهدة) — بيتحسب **في السيرفر** مش في
    // الجافاسكريبت، عشان تعديل الـHTML مايدخّلش كميات غلط.
    //
    //   `box_units`       = قطع العلبة (NULL للأصناف اللي مالهاش علبة)
    //   `units_per_case`  = قطع الكرتونة (نفس العمود اللي بيضرب باركود الكرتونة)

    /** وحدات الإدخال المتاحة للصنف ده ومضاعِف كل واحدة بالقطع */
    public function unitFactors(): array
    {
        $f = ['piece' => 1];

        if ((int) $this->box_units > 1) {
            $f['box'] = (int) $this->box_units;
        }

        if ((int) $this->units_per_case > 1) {
            $f['case'] = (int) $this->units_per_case;
        }

        return $f;
    }

    /**
     * مضاعِف وحدة معينة — أو null لو الوحدة دي **مش معرّفة** للصنف.
     *
     * ⚠️ null مش 1: لو حد بعت `case` لصنف مالوش كرتونة، الرفض أحسن
     * من افتراض إنها قطعة — الفرق بين 5 و 360 قطعة في المخزن.
     */
    public function unitFactor(?string $unit): ?int
    {
        return $this->unitFactors()[$unit ?: 'piece'] ?? null;
    }

    /**
     * تجميعة رصيد بالوحدات: 245 قطعة → «3 كرتونة + 1 علبة + 5 قطعة».
     *
     * عرض بس — الرقم المخزّن قطع دايماً. بتاخد الأكبر الأول
     * (كرتونة ← علبة ← قطعة) وبتتخطى أي وحدة مش معرّفة للصنف.
     * صفر أو صنف من غير تدريج → null (الشاشة تعرض الرقم لوحده).
     */
    public function packBreakdown(int $pieces): ?string
    {
        if ($pieces <= 0) {
            return null;
        }

        $tiers = [
            [__('stock.unit_case'), (int) $this->units_per_case],
            [__('stock.unit_box'), (int) $this->box_units],
        ];

        $parts = [];
        $rest = $pieces;

        foreach ($tiers as [$label, $size]) {
            if ($size > 1 && $rest >= $size) {
                $parts[] = number_format(intdiv($rest, $size)).' '.$label;
                $rest %= $size;
            }
        }

        if ($parts === []) {
            return null;   // مفيش تدريج — الرقم الخام كفاية
        }

        if ($rest > 0) {
            $parts[] = number_format($rest).' '.__('stock.unit_piece');
        }

        return implode(' + ', $parts);
    }

    /** «الكرتونة = 6 علب × 12 قطعة = 72» — سطر التدريج لكارت الصنف */
    public function packLabel(): ?string
    {
        $box = (int) $this->box_units;
        $case = (int) $this->units_per_case;

        if ($case > 1 && $box > 1 && $case % $box === 0) {
            return __('stock.pack_box_case', [
                'boxes' => $case / $box, 'box' => $box, 'case' => $case,
            ]);
        }

        if ($case > 1) {
            return __('stock.pack_case_only', ['case' => $case]);
        }

        if ($box > 1) {
            return __('stock.pack_box_only', ['box' => $box]);
        }

        return null;
    }
}
