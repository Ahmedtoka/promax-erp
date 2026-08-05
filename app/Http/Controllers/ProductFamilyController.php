<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\Models\Product;
use App\Models\ProductFamily;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ═══════════════════════════════════════════════════════════════
 * العائلات والصلاحية (2026-08-06) — قرار المالك
 * ═══════════════════════════════════════════════════════════════
 *
 * العائلة هي مصدر مدة الصلاحية: بتحدد شهور الانتهاء لكل منتجاتها،
 * وحفظ الشاشة **بيعيد حساب `expires_on` لكل باتش من تاريخ إنتاجه
 * + مدة عائلته** — فتقرير الصلاحية والبلوكات (FEFO) بيتظبطوا كلهم
 * من مكان واحد. تسكين المنتجات (دخول/خروج من عائلة) من نفس الشاشة.
 */
class ProductFamilyController extends Controller
{
    public function index()
    {
        return view('erp.families', [
            'families' => ProductFamily::withCount('products')->orderBy('id')->get(),
            'products' => Product::orderBy('code')->get(),
        ]);
    }

    /** حفظ العائلات (أسماء + شهور) + إضافة جديدة + إعادة حساب الانتهاء */
    public function save(Request $request)
    {
        $data = $request->validate([
            'rows' => ['nullable', 'array'],
            'rows.*.name' => ['required', 'string', 'max:120'],
            'rows.*.name_en' => ['nullable', 'string', 'max:120'],
            // بالشهور — 0/فاضي يعني لسه ماتحددتش
            'rows.*.months' => ['nullable', 'integer', 'min:0', 'max:120'],
            'new_name' => ['nullable', 'string', 'max:120'],
            'new_name_en' => ['nullable', 'string', 'max:120'],
            'new_months' => ['nullable', 'integer', 'min:0', 'max:120'],
        ]);

        foreach ($data['rows'] ?? [] as $id => $row) {
            ProductFamily::whereKey((int) $id)->update([
                'name' => $row['name'],
                'name_en' => ($row['name_en'] ?? null) ?: null,
                'shelf_life_months' => (int) ($row['months'] ?? 0) ?: null,
            ]);
        }

        // عائلة جديدة — المفتاح slug ثابت من الاسم الإنجليزي (زي المحافظات)
        if ($name = trim((string) ($data['new_name'] ?? ''))) {
            $key = Str::slug(($data['new_name_en'] ?? null) ?: $name, '_');

            if ($key === '' || ProductFamily::where('key', $key)->exists()) {
                return back()->withErrors(['new_name_en' => __('stock.family_key_taken')])->withInput();
            }

            ProductFamily::create([
                'key' => $key,
                'name' => $name,
                'name_en' => ($data['new_name_en'] ?? null) ?: null,
                'shelf_life_months' => (int) ($data['new_months'] ?? 0) ?: null,
            ]);
        }

        ProductFamily::flush();
        $fixed = $this->recomputeExpiry();

        return back()->with('ok', __('stock.families_saved', ['count' => $fixed]));
    }

    /** تسكين المنتجات: دخول/خروج من العائلات — وإعادة الحساب برضه */
    public function assign(Request $request)
    {
        $data = $request->validate([
            'fam' => ['required', 'array'],
            'fam.*' => ['nullable', 'string', 'max:40'],
        ]);

        $keys = ProductFamily::pluck('key')->all();
        $changed = 0;

        foreach ($data['fam'] as $productId => $famKey) {
            $famKey = $famKey ?: null;

            if ($famKey !== null && ! in_array($famKey, $keys, true)) {
                continue; // مفتاح مش موجود — بيتداس
            }

            $changed += Product::whereKey((int) $productId)
                ->where(fn ($q) => $famKey === null
                    ? $q->whereNotNull('family')
                    : $q->where(fn ($w) => $w->where('family', '!=', $famKey)->orWhereNull('family')))
                ->update(['family' => $famKey]);
        }

        ProductFamily::flush();
        $fixed = $this->recomputeExpiry();

        return back()->with('ok', __('stock.families_assigned', ['count' => $changed, 'batches' => $fixed]));
    }

    /**
     * إعادة حساب الانتهاء — **الدوكترين**: أي باتش ليه تاريخ إنتاج،
     * انتهاؤه = الإنتاج + مدة صلاحية منتجه (من العائلة). اللي من غير
     * تاريخ إنتاج (رصيد أول مدة) مابيتلمسش — مافيش أساس نحسب منه.
     */
    private function recomputeExpiry(): int
    {
        $fixed = 0;

        Batch::whereNotNull('produced_on')
            ->with('product:id,family,shelf_life_months')
            ->chunkById(500, function ($chunk) use (&$fixed) {
                foreach ($chunk as $batch) {
                    if ($batch->product === null) {
                        continue;
                    }

                    $expected = $batch->product->expiryFrom($batch->produced_on)->toDateString();

                    if ($batch->expires_on?->toDateString() !== $expected) {
                        $batch->update(['expires_on' => $expected]);
                        $fixed++;
                    }
                }
            });

        return $fixed;
    }
}
