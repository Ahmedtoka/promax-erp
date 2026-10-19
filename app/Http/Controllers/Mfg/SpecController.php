<?php

namespace App\Http\Controllers\Mfg;

use App\Exceptions\Rejected;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductSpec;
use App\Services\Mfg\Specs;
use App\Support\Csv;
use App\Support\DateRange;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * مواصفات المنتجات (التصنيع ١٩/١٠، spec §3.5) — القايمة والإصدار والمراجعة.
 *
 * الكنترولر رفيع: الدورة كلها في `App\Services\Mfg\Specs`، والرفض
 * (`Rejected`) بيرجع فلاش خطأ. الكتابة `role:admin,rnd`، والاعتماد/الإرجاع
 * `role:admin` (على الراوت + `act.mfg.specs_*` على الزرار).
 *
 * ⚠️ الفلتر على `updated_at` (آخر تعديل للمواصفة)، والتصدير من نفس الكويري.
 */
class SpecController extends Controller
{
    public function index(Request $request)
    {
        // قيمة مش معروفة = من غير فلتر (مفيش 422 على شاشة GET)
        $picked = $request->string('status')->value();
        $filters = [
            'status' => in_array($picked, ProductSpec::STATUSES, true) ? $picked : null,
            'product_id' => $request->integer('product_id') ?: null,
        ];

        $q = ProductSpec::query()->with('product');

        if ($status = $filters['status'] ?? null) {
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

            return Csv::download('product-specs-'.now()->format('Y-m-d-Hi').'.csv', [
                __('mfg.col_product'), __('mfg.col_version'), __('mfg.col_status'),
                __('mfg.col_net_weight'), __('mfg.col_shelf_life'), __('mfg.col_updated_at'),
            ], $rows->map(fn (ProductSpec $s) => [
                $s->product?->displayName() ?? '', $s->version, $s->statusLabel(),
                $s->net_weight_g ?? '', $s->shelf_life_days ?? '', $s->updated_at?->format('Y-m-d H:i') ?? '',
            ]), null, Csv::meta(__('mfg.specs_title'), $range->fromValue() ?: null, $range->toValue() ?: null));
        }

        return view('mfg.specs', [
            'specs' => $q->paginate(50)->withQueryString(),
            'filters' => $filters,
            'range' => $range,
            'products' => self::finishedProducts(),
        ]);
    }

    public function show(ProductSpec $spec)
    {
        $spec->load(['product', 'creator', 'approver']);

        return view('mfg.spec', [
            'spec' => $spec,
            'versions' => ProductSpec::query()->where('product_id', $spec->product_id)
                ->with(['creator', 'approver'])->orderByDesc('version')->get(),
        ]);
    }

    /** إصدار جديد (مسودة) — من الصفر أو منسوخ من إصدار تاني لنفس المنتج */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'copy_from' => ['nullable', 'integer', 'exists:product_specs,id'],
        ]);

        $product = Product::query()->findOrFail($data['product_id']);
        $copy = ($data['copy_from'] ?? null) ? ProductSpec::query()->find($data['copy_from']) : null;

        try {
            $spec = Specs::newVersion($product, $request->user(), $copy);
        } catch (Rejected $e) {
            return back()->withErrors(['spec' => $e->getMessage()])->withInput();
        }

        return redirect()->route('mfg.specs.show', $spec)
            ->with('ok', __('mfg.flash_spec_created', ['version' => $spec->version]));
    }

    /** تعديل المسودة (الحقول + المرفق) — غير المسودة بيترفض في الخدمة */
    public function update(Request $request, ProductSpec $spec): RedirectResponse
    {
        $data = $request->validate([
            'net_weight_g' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'shelf_life_days' => ['nullable', 'integer', 'min:1', 'max:36500'],
            'nutrition' => ['nullable', 'array'],
            'nutrition.*' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'qc_limits' => ['nullable', 'array', 'max:100'],
            'qc_limits.*.param' => ['nullable', 'string', 'max:100'],
            'qc_limits.*.min' => ['nullable', 'numeric'],
            'qc_limits.*.max' => ['nullable', 'numeric'],
            'qc_limits.*.unit' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,docx', 'max:10240'],
        ]);

        // اللي اتبعت بس بيتكتب — رفع مرفق لوحده مايمسحش باقي الحقول
        $fields = [];

        foreach (['net_weight_g', 'shelf_life_days', 'notes'] as $k) {
            if ($request->exists($k)) {
                $fields[$k] = $data[$k] ?? null;
            }
        }

        if ($request->exists('nutrition')) {
            $fields['nutrition'] = self::nutrition($data['nutrition'] ?? []);
        }

        // الفورم بيبعت `qc_present` عشان شيل كل الصفوف يتسجل كـ«مفيش حدود»
        if ($request->exists('qc_limits') || $request->exists('qc_present')) {
            $limits = self::qcLimits($data['qc_limits'] ?? []);

            if ($limits === false) {
                return back()->withErrors(['qc_limits' => __('mfg.spec_qc_min_max')])->withInput();
            }

            $fields['qc_limits'] = $limits;
        }

        $old = $spec->attachment_path;
        $stored = null;

        if ($file = $request->file('attachment')) {
            $ext = strtolower((string) $file->guessExtension() ?: $file->getClientOriginalExtension());
            $stored = $file->storeAs(Specs::DIR, Str::uuid().($ext !== '' ? '.'.$ext : ''), 'local');
            $fields['attachment_path'] = $stored;
            $fields['attachment_name'] = Str::limit($file->getClientOriginalName(), 250, '');
        }

        try {
            Specs::update($spec, $fields);
        } catch (Rejected $e) {
            if ($stored) {
                Storage::disk('local')->delete($stored);
            }

            return back()->withErrors(['spec' => $e->getMessage()])->withInput();
        }

        // المرفق القديم ملك المسودة دي لوحدها (النسخ بيعمل ملف جديد)
        if ($stored && $old && $old !== $stored) {
            Storage::disk('local')->delete($old);
        }

        return back()->with('ok', __('mfg.flash_spec_saved'));
    }

    public function submit(Request $request, ProductSpec $spec): RedirectResponse
    {
        return $this->transition(fn () => Specs::submit($spec, $request->user()), 'mfg.flash_spec_submitted');
    }

    public function approve(Request $request, ProductSpec $spec): RedirectResponse
    {
        return $this->transition(fn () => Specs::approve($spec, $request->user()), 'mfg.flash_spec_approved');
    }

    public function sendBack(Request $request, ProductSpec $spec): RedirectResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        return $this->transition(
            fn () => Specs::sendBack($spec, $request->user(), $data['note'] ?? null),
            'mfg.flash_spec_sent_back'
        );
    }

    public function file(ProductSpec $spec)
    {
        $disk = Storage::disk('local');

        abort_unless($spec->attachment_path && $disk->exists($spec->attachment_path), 404);

        return $disk->download($spec->attachment_path, $spec->attachment_name ?: basename($spec->attachment_path));
    }

    private function transition(\Closure $do, string $flash): RedirectResponse
    {
        try {
            $do();
        } catch (Rejected $e) {
            return back()->withErrors(['spec' => $e->getMessage()]);
        }

        return back()->with('ok', __($flash));
    }

    /**
     * القيم الغذائية — المفاتيح المعروفة بس، والفاضي بيتشال. كله فاضي = null.
     *
     * @param  array<string, mixed>  $in
     * @return array<string, float>|null
     */
    private static function nutrition(array $in): ?array
    {
        $out = [];

        foreach (ProductSpec::NUTRITION_KEYS as $k) {
            $v = $in[$k] ?? null;
            if ($v !== null && $v !== '') {
                $out[$k] = (float) $v;
            }
        }

        return $out === [] ? null : $out;
    }

    /**
     * حدود الجودة — الصف من غير اسم بيتشال. الحد الأدنى أكبر من الأقصى = false.
     *
     * @param  array<int|string, mixed>  $rows
     * @return list<array{param:string,min:?float,max:?float,unit:?string}>|null|false
     */
    private static function qcLimits(array $rows): array|null|false
    {
        $out = [];

        foreach ($rows as $r) {
            $param = trim((string) ($r['param'] ?? ''));
            if ($param === '') {
                continue;
            }

            $min = isset($r['min']) && $r['min'] !== '' ? (float) $r['min'] : null;
            $max = isset($r['max']) && $r['max'] !== '' ? (float) $r['max'] : null;

            if ($min !== null && $max !== null && $min > $max) {
                return false;
            }

            $unit = trim((string) ($r['unit'] ?? ''));
            $out[] = ['param' => $param, 'min' => $min, 'max' => $max, 'unit' => $unit === '' ? null : $unit];
        }

        return $out === [] ? null : $out;
    }

    /** المنتجات التامة النشطة — اللي ليها مواصفة أصلاً */
    private static function finishedProducts()
    {
        return Product::query()->where('kind', Product::KIND_FINISHED)->where('active', true)
            ->orderBy('name')->get();
    }
}
