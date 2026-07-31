<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════
 * promax:catalogue — الكتالوج والمخازن من الصفر
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **حذف منتج بيمسح شغله معاه.** كل الجداول دي `cascadeOnDelete`
 * على `products`:
 *
 *     invoice_items · purchase_order_items · custody_items · batches
 *     batch_locations · pick_order_items · replenishment_items
 *     shelf_refills · stock_count_items · stock_transfer_items · stocks
 *
 * أخطرهم `invoice_items`: الفواتير نفسها **مش** بتتمسح (مفيش FK على
 * `products` منها)، فبتفضل بإجماليات مالهاش سطور — ورقم مبيعات
 * مالوش تفصيل. والقيود في `transactions` كمان بتفضل مكانها.
 *
 * فالأمر ده بيعدّ الأول وبيقول بالأرقام إيه اللي هيروح، ومابيلمسش
 * حاجة قبل ما تأكّد.
 */
class SetupCatalogue extends Command
{
    protected $signature = 'promax:catalogue
        {--force : من غير تأكيد}
        {--keep-products : المخازن بس، سيب المنتجات زي ما هي}';

    protected $description = 'يمسح المنتجات ويستورد الكتالوج المعتمد + يعمل المخازن';

    /**
     * المخازن المطلوبة.
     *
     * ⚠️ **الكود ثابت مايتغيرش.** `MAADI` متخزن على أمين المخزن
     * (`users.warehouse_id`) وعلى الباتشات والأرفف. تغييره بيقطع
     * الربط ده كله في صمت.
     */
    private const WAREHOUSES = [
        ['code' => 'TENTH', 'name' => 'مخزن العاشر', 'name_en' => 'Tenth of Ramadan warehouse', 'type' => 'factory'],
        ['code' => 'MAADI', 'name' => 'مخزن المعادي', 'name_en' => 'Maadi warehouse', 'type' => 'branch'],
    ];

    /** الجداول اللي بتتمسح مع المنتج — مستخرجة من المايجريشنز */
    private const CASCADES = [
        'invoice_items', 'purchase_order_items', 'custody_items', 'batches',
        'batch_locations', 'pick_order_items', 'replenishment_items',
        'shelf_refills', 'stock_count_items', 'stock_transfer_items', 'stocks',
    ];

    public function handle(): int
    {
        $this->newLine();
        $this->line('  ┌──────────────────────────────────────────┐');
        $this->line('  │  الكتالوج والمخازن                       │');
        $this->line('  └──────────────────────────────────────────┘');
        $this->newLine();

        $items = $this->catalogue();

        if ($items === null) {
            return self::FAILURE;
        }

        // ═══════════ 1. العدّ قبل أي حاجة ═══════════
        $wipe = ! $this->option('keep-products');
        $productCount = Product::count();
        $damage = [];

        if ($wipe) {
            foreach (self::CASCADES as $table) {
                if (! Schema::hasTable($table)) {
                    continue;
                }

                $n = DB::table($table)->count();

                if ($n > 0) {
                    $damage[$table] = $n;
                }
            }
        }

        $this->line('  المنتجات دلوقتي:  <fg=yellow>'.$productCount.'</>');
        $this->line('  الكتالوج الجديد:  <fg=green>'.count($items).' صنف</>');
        $this->line('  المخازن:          '.implode(' · ', array_column(self::WAREHOUSES, 'name')));
        $this->newLine();

        if ($damage !== []) {
            $this->warn('  ⚠️  مسح المنتجات هيمسح معاه:');
            $this->newLine();

            foreach ($damage as $table => $n) {
                $this->line("     • <fg=yellow>{$table}</>: {$n}");
            }

            if (isset($damage['invoice_items'])) {
                $this->newLine();
                // ⚠️ دي أخطر سطر في الشاشة كلها — لازم يتقال بالنص
                $this->error('  🔴 الفواتير نفسها مش هتتمسح — هتفضل بإجماليات مالهاش سطور،');
                $this->line('     والقيود في كشوف الحساب هتفضل مكانها. يعني رقم مبيعات');
                $this->line('     مالوش تفصيل، ومحدش هيعرف السبب بعد شهر.');
            }

            $this->newLine();
        }

        if (! $this->option('force') && ! $this->confirm('نكمّل؟', false)) {
            $this->line('  اتلغى — مافيش حاجة اتغيّرت.');

            return self::SUCCESS;
        }

        // ═══════════ 2. التنفيذ ═══════════
        // ⚠️ المسح والبناء في ترانزاكشن واحدة: أي خطأ في الاستيراد
        // بعد المسح كان هيسيب السيستم من غير كتالوج خالص.
        DB::transaction(function () use ($items, $wipe) {
            $warehouses = $this->buildWarehouses();

            if ($wipe) {
                // ⚠️ **`forceDelete()` مش موجودة من غير `SoftDeletes`.**
                // `Eloquent\Builder` بيمرّر أي ميثود مش عارفها لـ
                // `Query\Builder` — واللي مافيهاش `forceDelete` أصلاً،
                // فالأمر كان بيموت بـBadMethodCallException قبل ما
                // يعمل حاجة. والمكتوب صح: لو `SoftDeletes` اتضافت على
                // `Product` بعد كده، `delete()` بتبقى no-op صامتة
                // والأكواد بتفضل محجوزة والاستيراد بيرمي Duplicate.
                // فبنسأل وقت التشغيل بدل ما نفترض.
                $soft = in_array(
                    \Illuminate\Database\Eloquent\SoftDeletes::class,
                    class_uses_recursive(Product::class),
                    true,
                );

                $soft ? Product::query()->forceDelete() : Product::query()->delete();
            }

            // ⚠️ **`--keep-products` كان بيدوس على المنتجات برضه.**
            // `buildProducts()` كانت بتتنادى دايماً، فالفلاج اللي
            // معناه «المخازن بس» كان بيصفّر تكلفة وسعر كل صنف موجود
            // **و**يصفّر أرصدته في كل المخازن ويختم `counted_at`
            // باليوم — وشاشة التأكيد كانت بتقول إن مفيش أي ضرر لأن
            // عدّ الضرر نفسه كان جوه `if ($wipe)`. أول فاتورة بعدها
            // بتتسعّر بصفر.
            if ($wipe) {
                $this->buildProducts($items, $warehouses);
            } else {
                $this->zeroRows($warehouses);
            }
        });

        // ═══════════ 3. النتيجة ═══════════
        $this->newLine();
        $this->line('  ── المخازن ──');

        foreach (Warehouse::orderBy('id')->get() as $w) {
            $qty = DB::table('stocks')->where('warehouse_id', $w->id)->sum('qty');
            $this->line("     • {$w->code} — {$w->name} · رصيد: {$qty}");
        }

        $this->newLine();
        $this->line('  ── الكتالوج ──');

        $byFamily = Product::selectRaw('family, COUNT(*) as n')->groupBy('family')->pluck('n', 'family');

        foreach ($byFamily as $family => $n) {
            $this->line("     • ".__('enums.family.'.$family, [], 'ar')." — {$n}");
        }

        $noCase = Product::whereNull('case_barcode')->count();

        $this->newLine();
        $this->info('  ✅ '.Product::count().' صنف بأسعار صفر.');

        if ($noCase > 0) {
            $this->line("     {$noCase} منهم من غير باركود كرتونة — دول البرطمانات،");
            $this->line('     GS1 مسجّلة لهم وحدة استهلاك بس.');
        }

        $this->line('     الأسعار والكميات بتتحط من /erp/stock.');
        $this->newLine();

        return self::SUCCESS;
    }

    /** الكتالوج من ملف البيانات */
    private function catalogue(): ?array
    {
        $path = storage_path('app/data/products_2026.json');

        if (! is_file($path)) {
            $this->error('  ⛔ ملف الكتالوج مش موجود: storage/app/data/products_2026.json');

            return null;
        }

        $items = json_decode((string) file_get_contents($path), true);

        if (! is_array($items) || $items === []) {
            $this->error('  ⛔ ملف الكتالوج فاضي أو مش JSON صحيح.');

            return null;
        }

        // ⚠️ الكود المكرر بيخلّي `updateOrCreate` تدوس على الأول
        // بالتاني — صنف بيختفي في صمت والأمر بيقول «تمّ».
        $codes = array_column($items, 'code');

        if (count($codes) !== count(array_unique($codes))) {
            $this->error('  ⛔ فيه أكواد مكررة في ملف الكتالوج.');

            return null;
        }

        // ⚠️ **الباركود فريد في الداتابيز كمان** (`products.barcode`).
        // التكرار هنا كان بيرمي «Duplicate entry» خام في نص الاستيراد
        // بعد ما المستخدم أكّد وبعد ما المسح اتنفّذ.
        $barcodes = array_filter(array_column($items, 'barcode'));

        if (count($barcodes) !== count(array_unique($barcodes))) {
            $this->error('  ⛔ فيه باركودات مكررة في ملف الكتالوج.');

            return null;
        }

        // ⚠️ الحقول دي بتتقرا من غير `??` تحت — الصف الناقص كان
        // بيطلّع ErrorException بدل رسالة مفهومة.
        foreach (['code', 'barcode', 'name', 'name_en', 'unit', 'unit_en', 'family'] as $key) {
            foreach ($items as $i => $row) {
                if (($row[$key] ?? null) === null || $row[$key] === '') {
                    $this->error("  ⛔ الصف رقم ".($i + 1)." ناقص فيه «{$key}».");

                    return null;
                }
            }
        }

        return $items;
    }

    /** @return array<string, int> كود المخزن ← id */
    private function buildWarehouses(): array
    {
        $out = [];

        foreach (self::WAREHOUSES as $row) {
            // ⚠️ **`firstOrNew` مش `updateOrCreate`.** المخزن الموجود
            // ممكن يكون اتسمّى أو اتظبط من الشاشة — الكتابة فوقه
            // بترجّعه للاسم الافتراضي من غير ما حد يقول.
            $w = Warehouse::firstOrNew(['code' => $row['code']]);

            if (! $w->exists) {
                $w->fill($row + ['active' => true])->save();
            }

            $out[$row['code']] = $w->id;
        }

        return $out;
    }

    /**
     * صف رصيد بصفر لكل (صنف موجود، مخزن) — من غير ما تلمس التعريف.
     *
     * ⚠️ ده مسار `--keep-products`: بيضمن إن كل صنف موجود يبان في
     * شاشة كل مخزن، من غير ما يمسح سعره ولا رصيده. `firstOrCreate`
     * مش `updateOrCreate` عشان الصف اللي فيه كمية يفضل بكميته.
     *
     * @param  array<string, int>  $warehouses
     */
    private function zeroRows(array $warehouses): void
    {
        Product::query()->select('id')->chunkById(200, function ($chunk) use ($warehouses) {
            foreach ($chunk as $product) {
                foreach ($warehouses as $warehouseId) {
                    $product->stocks()->firstOrCreate(
                        ['warehouse_id' => $warehouseId],
                        ['qty' => 0, 'hold_qty' => 0, 'good_qty' => 0],
                    );
                }
            }
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, int>  $warehouses
     */
    private function buildProducts(array $items, array $warehouses): void
    {
        foreach ($items as $row) {
            $product = Product::updateOrCreate(
                ['code' => $row['code']],
                [
                    'barcode' => $row['barcode'],
                    'case_barcode' => $row['case_barcode'] ?? null,
                    'units_per_case' => $row['units_per_case'] ?? null,
                    'name' => $row['name'],
                    'name_en' => $row['name_en'],
                    'unit' => $row['unit'],
                    'unit_en' => $row['unit_en'],
                    'net_content' => $row['net_content'] ?? null,
                    'net_uom' => $row['net_uom'] ?? 'g',
                    'family' => $row['family'],
                    'brand' => $row['brand'] ?? null,
                    'gpc_category' => $row['gpc_category'] ?? null,
                    // ⚠️ **الأسعار صفر عن قصد.** الشيت تسجيل GS1 —
                    // فيه باركودات وأسماء رسمية ومافيهوش أسعار. رقم
                    // مخترع هنا بيتحوّل لسعر بيع على أول فاتورة.
                    'cost' => 0,
                    'price_old' => 0,
                    'price_new' => 0,
                    'taxable' => true,
                    'tax_rate' => 0,
                    'shelf_life_months' => Product::SHELF_LIFE[$row['family']] ?? null,
                    'active' => true,
                ],
            );

            // ⚠️ **صف رصيد بصفر في كل مخزن.** من غيره الصنف مابيبانش
            // في شاشة المخزن خالص — واللي بيجرد بيفتكر إنه مش متعرّف.
            foreach ($warehouses as $warehouseId) {
                $product->stocks()->firstOrCreate(
                    ['warehouse_id' => $warehouseId],
                    ['qty' => 0, 'hold_qty' => 0, 'good_qty' => 0],
                );
            }
        }
    }
}
