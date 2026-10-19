<?php

namespace App\Http\Controllers\Mfg;

use App\Exceptions\Rejected;
use App\Http\Controllers\Controller;
use App\Models\Bom;
use App\Models\BomLine;
use App\Models\Product;
use App\Services\Mfg\Boms;
use App\Support\Csv;
use App\Support\DateRange;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * قوايم المواد (التصنيع ١٩/١٠، spec §3.5) — القايمة والإصدار والسطور والاعتماد.
 *
 * الكنترولر رفيع: الدورة كلها في `App\Services\Mfg\Boms`، والرفض
 * (`Rejected`) بيرجع فلاش خطأ. الكتابة `role:admin,rnd`، والاعتماد
 * `role:admin` (على الراوت + `act.mfg.boms_*` على الزرار).
 *
 * ⚠️ الفلتر على `updated_at` (آخر تعديل)، والتصدير من نفس الكويري.
 */
class BomController extends Controller
{
    public function index(Request $request)
    {
        // قيمة مش معروفة = من غير فلتر (مفيش 422 على شاشة GET)
        $picked = $request->string('status')->value();
        $filters = [
            'status' => in_array($picked, Bom::STATUSES, true) ? $picked : null,
            'product_id' => $request->integer('product_id') ?: null,
        ];

        $q = Bom::query()->with('product')->withCount('lines');

        if ($status = $filters['status']) {
            $q->where('status', $status);
        }

        if ($pid = $filters['product_id']) {
            $q->where('product_id', $pid);
        }

        $range = DateRange::fromRequest($request);
        $range->apply($q, 'updated_at');

        $q->orderByDesc('updated_at')->orderByDesc('id');

        if ($request->boolean('export')) {
            $rows = (clone $q)->get();

            return Csv::download('boms-'.now()->format('Y-m-d-Hi').'.csv', [
                __('mfg.col_product'), __('mfg.col_version'), __('mfg.col_status'),
                __('mfg.col_lines'), __('mfg.col_waste_pct'), __('mfg.col_updated_at'),
            ], $rows->map(fn (Bom $b) => [
                $b->product?->displayName() ?? '', $b->version, $b->statusLabel(),
                $b->lines_count, (float) $b->waste_pct, $b->updated_at?->format('Y-m-d H:i') ?? '',
            ]), null, Csv::meta(__('mfg.boms_title'), $range->fromValue() ?: null, $range->toValue() ?: null));
        }

        return view('mfg.boms', [
            'boms' => $q->paginate(50)->withQueryString(),
            'filters' => $filters,
            'range' => $range,
            'products' => Product::query()->where('kind', Product::KIND_FINISHED)->where('active', true)
                ->orderBy('name')->get(),
        ]);
    }

    public function show(Bom $bom)
    {
        $bom->load(['product', 'creator', 'approver', 'lines.product']);

        // اختيار الخامات للمحرر — نشطة وخامة/تعبئة بس، ومعاها الوحدة للعرض
        $materials = Product::query()->whereIn('kind', Boms::LINE_KINDS)->where('active', true)
            ->orderBy('kind')->orderBy('name')->get()
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'label' => $p->code.' — '.$p->displayName(),
                'unit' => $p->unitLabel(),
                'kind' => $p->kind,
            ])->all();

        return view('mfg.bom', [
            'bom' => $bom,
            'materials' => $materials,
            'versions' => Bom::query()->where('product_id', $bom->product_id)
                ->with(['creator', 'approver'])->withCount('lines')->orderByDesc('version')->get(),
        ]);
    }

    /** إصدار جديد (مسودة) — منسوخ من `copy_from` أو من آخر إصدار للمنتج */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'copy_from' => ['nullable', 'integer', 'exists:boms,id'],
        ]);

        $product = Product::query()->findOrFail($data['product_id']);
        $copy = ($data['copy_from'] ?? null) ? Bom::query()->find($data['copy_from']) : null;

        try {
            $bom = Boms::newVersion($product, $request->user(), $copy);
        } catch (Rejected $e) {
            return back()->withErrors(['bom' => $e->getMessage()])->withInput();
        }

        return redirect()->route('mfg.boms.show', $bom)
            ->with('ok', __('mfg.flash_bom_created', ['version' => $bom->version]));
    }

    /** حفظ سطور المسودة + الهالك + الملاحظات — غير المسودة بيترفض في الخدمة */
    public function lines(Request $request, Bom $bom): RedirectResponse
    {
        $data = $request->validate([
            'waste_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'lines' => ['nullable', 'array', 'max:200'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'lines.*.basis' => ['required', Rule::in(BomLine::BASES)],
        ]);

        try {
            Boms::saveLines($bom, array_values($data['lines'] ?? []), (float) $data['waste_pct'], $data['notes'] ?? null);
        } catch (Rejected $e) {
            return back()->withErrors(['bom' => $e->getMessage()])->withInput();
        }

        return back()->with('ok', __('mfg.flash_bom_saved'));
    }

    public function approve(Request $request, Bom $bom): RedirectResponse
    {
        try {
            Boms::approve($bom, $request->user());
        } catch (Rejected $e) {
            return back()->withErrors(['bom' => $e->getMessage()]);
        }

        return back()->with('ok', __('mfg.flash_bom_approved'));
    }
}
