<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

/**
 * كتالوج GS1 الرسمي — 31 وحدة استهلاك بباركودها وباركود الكرتونة.
 *
 * الاستراتيجية (اللي اتفقنا عليها): اربط واكمّل
 *   - المنتجات الموجودة بتتطابق بالعائلة + النكهة، وبتاخد الباركود
 *     والاسم الرسمي والوزن — من غير ما نلمس أسعارها ولا مخزونها ولا فواتيرها
 *   - اللي مش موجود بيتضاف جديد بسعر صفر (تحدد أسعاره من شاشة المخزون)
 *
 * السيدر idempotent — ينفع يتشغل أكتر من مرة.
 */
class Gs1CatalogueSeeder extends Seeder
{
    private const SOURCE = 'data/gs1_catalogue.json';

    /** كلمات النكهة في الاسم العربي القديم → مفتاح النكهة في الشيت */
    private const FLAVOUR_AR = [
        'كوكيز اند كريم' => 'cookiescream',
        'كوكيز' => 'cookiescream',
        'تمر' => 'dates',
        'موز' => 'banana',
        'التوت الازرق' => 'berries',
        'توت' => 'berries',
        'فول السودانى' => 'peanut',
        'فول السوداني' => 'peanut',
        'سوداني' => 'peanut',
        'بستاشيو دبى' => 'pistachio',
        'بستاشيو' => 'pistachio',
        'فستق' => 'pistachio',
        'فانليا' => 'vanilla',
        'جوز الهند' => 'coconut',
        'قهوة' => 'coffee',
        'شيكولاتة' => 'chocolate',
        'شكولاته' => 'chocolate',
        'شوكولات' => 'chocolate',
        'عثل' => 'honey',
        'عسل' => 'honey',
        'بوينو' => 'hazelnut',
        'اسبريد بروتين' => 'chocopro',
    ];

    public function run(): void
    {
        $path = storage_path('app/'.self::SOURCE);

        if (! File::exists($path)) {
            $this->command->warn('   ⚠️  ملف الكتالوج مش موجود: '.self::SOURCE);

            return;
        }

        /** @var array<int, array<string, mixed>> $rows */
        $rows = json_decode(File::get($path), true) ?: [];

        $matched = 0;
        $created = 0;

        foreach ($rows as $row) {
            $existing = $this->findExisting($row);

            if ($existing) {
                $this->enrich($existing, $row);
                $matched++;
            } else {
                $this->create($row);
                $created++;
            }
        }

        $this->command->info("   • $matched منتج اتطابق واتكمّل، $created منتج جديد اتضاف");
        $this->command->info('   • الجديد سعره صفر — حدّد أسعاره من /erp/stock');
    }

    /**
     * بندوّر على المنتج الموجود بالباركود الأول (لو السيدر اتشغل قبل كده)،
     * وبعدين بالعائلة + النكهة.
     */
    private function findExisting(array $row): ?Product
    {
        if ($found = Product::where('barcode', $row['barcode'])->first()) {
            return $found;
        }

        $candidates = Product::where('family', $row['family'])
            ->whereNull('barcode')
            ->get();

        foreach ($candidates as $product) {
            if ($this->flavourOf($product->name) === $row['flavour']) {
                return $product;
            }
        }

        return null;
    }

    /** بيقرأ النكهة من الاسم العربي القديم — أطول تطابق يكسب */
    private function flavourOf(string $arabicName): ?string
    {
        $best = null;
        $bestLength = 0;

        foreach (self::FLAVOUR_AR as $needle => $key) {
            if (mb_strpos($arabicName, $needle) !== false && mb_strlen($needle) > $bestLength) {
                $best = $key;
                $bestLength = mb_strlen($needle);
            }
        }

        return $best;
    }

    /** بيكمّل بيانات منتج موجود — من غير ما يلمس الأسعار ولا الكود */
    private function enrich(Product $product, array $row): void
    {
        $product->forceFill($this->catalogueFields($row))->saveQuietly();
    }

    private function create(array $row): void
    {
        Product::create(array_merge($this->catalogueFields($row), [
            'code' => $this->nextCode($row),
            'name' => $row['name_ar'],
            'unit' => $this->unitAr($row),   // العمود ده NOT NULL في السكيما
            // منتج جديد من غير أسعار — بيتحدد من الشاشة
            'cost' => 0,
            'price_old' => 0,
            'price_new' => 0,
            'active' => true,
        ]));
    }

    private function unitAr(array $row): string
    {
        $net = $this->netLabel($row);

        return match ($row['family']) {
            'promax_cup' => "كوب {$net}جم",
            'spreads' => "برطمان {$net}جم",
            default => "بار {$net}جم",
        };
    }

    /** الحقول اللي بتيجي من GS1 بس — الأسعار والكود بره */
    private function catalogueFields(array $row): array
    {
        return [
            'barcode' => $row['barcode'],
            'case_barcode' => $row['case_barcode'],
            'units_per_case' => $row['units_per_case'],
            'name_en' => $row['name_en'],
            'net_content' => $row['net_content'],
            'net_uom' => $row['uom_en'] === 'Gram' ? 'g' : 'pc',
            'family' => $row['family'],
            'brand' => $row['brand'],
            'image_url' => $row['image_url'],
            'gpc_category' => $row['gpc_en'],
            'shelf_life_months' => Product::SHELF_LIFE[$row['family']] ?? Product::DEFAULT_SHELF_LIFE,
            'unit_en' => $this->unitEn($row),
        ];
    }

    private function unitEn(array $row): string
    {
        $net = $this->netLabel($row);

        return match ($row['family']) {
            'promax_cup' => "{$net}g Cup",
            'spreads' => "{$net}g Jar",
            default => "{$net}g Bar",
        };
    }

    /** "70.00" → "70" */
    private function netLabel(array $row): string
    {
        return rtrim(rtrim(number_format((float) $row['net_content'], 2, '.', ''), '0'), '.');
    }

    /** كود داخلي للمنتج الجديد — آخر 4 أرقام من الباركود مع بادئة العائلة */
    private function nextCode(array $row): string
    {
        $prefix = match ($row['family']) {
            'promax_bar' => 'PB',
            'promax_cup' => 'PC',
            'pmx_bar' => 'MB',
            'energy_bar' => 'EB',
            default => 'SP',
        };

        $code = $prefix.'-'.substr($row['barcode'], -4);
        $n = 2;

        while (Product::where('code', $code)->exists()) {
            $code = $prefix.'-'.substr($row['barcode'], -4).'-'.$n++;
        }

        return $code;
    }
}
