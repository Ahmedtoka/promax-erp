<?php

namespace App\Http\Controllers;

use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * ═══════════════════════════════════════════════════════════════
 * قوايم الأسعار — التعريف والتسعير الجماعي
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **الشاشة دي بتحدد فلوس الشركة.** السعر اللي بيتكتب هنا هو
 * اللي بيتحاسب بيه العميل في كل فاتورة، فالتعديل عليها للأدمن
 * ومدير القنوات بس.
 */
class PriceListController extends Controller
{
    /**
     * كل القوايم — مين عليها، كام صنف، مبنية على إيه، وآخر استخدام.
     *
     * ⚠️ **الافتراضي «الشغّالة بس»** (طلب المالك ١١/١١): بعد تصليح الأسعار
     * فيه قوايم موقوفة عشان عليها مستندات قديمة — ظهورها جنب الشغّالة
     * بيلخبط. `status=all` أو `inactive` بيرجّعها.
     */
    public function index(Request $request)
    {
        $status = in_array($request->query('status'), ['active', 'inactive', 'all'], true)
            ? $request->query('status') : 'active';
        $q = trim((string) $request->query('q', ''));
        $basis = (string) $request->query('basis', '');
        $sort = in_array($request->query('sort'), ['code', 'name', 'clients', 'chains', 'priced', 'last_invoice', 'ratio'], true)
            ? $request->query('sort') : 'clients';
        $dir = $request->query('dir') === 'asc' ? 'asc' : 'desc';

        $all = PriceList::orderByDesc('is_default')->orderBy('id')->get();
        $stats = \App\Services\PriceListOverview::forLists($all);

        // الكروت فوق بتتحسب على كل القوايم — الفلتر بيأثر على الجدول بس
        $counts = [
            'active' => $all->where('active', true)->count(),
            'inactive' => $all->where('active', false)->count(),
            'clients' => collect($stats)->sum('clients_active'),
            'chains' => collect($stats)->sum(fn ($s) => $s['chains']->count()),
            'unlisted' => \App\Models\Client::where('status', 'active')->whereNull('price_list_id')->count(),
        ];

        $lists = $all->filter(function ($l) use ($status, $q, $basis, $stats) {
            if ($status === 'active' && ! $l->active) {
                return false;
            }
            if ($status === 'inactive' && $l->active) {
                return false;
            }
            if ($basis !== '' && $stats[$l->id]['basis']['kind'] !== $basis) {
                return false;
            }
            if ($q !== '') {
                $hay = mb_strtolower($l->code.' '.$l->name.' '.$l->name_en.' '.$l->notes);
                // البحث باسم عميل أو سلسلة كمان: «مين واخد قايمة كارفور؟»
                $hay .= ' '.mb_strtolower($stats[$l->id]['clients']->map(fn ($c) => $c->code.' '.$c->name.' '.$c->name_en)->implode(' '));
                $hay .= ' '.mb_strtolower($stats[$l->id]['chains']->map(fn ($c) => $c->code.' '.$c->name.' '.$c->name_en)->implode(' '));

                return str_contains($hay, mb_strtolower($q));
            }

            return true;
        });

        $key = fn ($l) => match ($sort) {
            'code' => mb_strtolower($l->code),
            'name' => mb_strtolower($l->displayName()),
            'clients' => $stats[$l->id]['clients_active'],
            'chains' => $stats[$l->id]['chains']->count(),
            'priced' => $stats[$l->id]['priced'],
            'last_invoice' => $stats[$l->id]['last_invoice']?->id ?? 0,
            'ratio' => $stats[$l->id]['basis']['ratio'] ?? 0,
        };
        // الشغّالة دايماً قبل الموقوفة لما الاتنين ظاهرين، وبعدين العمود المختار
        $lists = $lists->sort(function ($a, $b) use ($key, $dir) {
            if ($a->active !== $b->active) {
                return $a->active ? -1 : 1;
            }
            $c = $key($a) <=> $key($b);

            return $dir === 'asc' ? $c : -$c;
        })->values();

        $page = max(1, (int) $request->query('page', 1));
        $per = 25;
        $paged = new \Illuminate\Pagination\LengthAwarePaginator(
            $lists->forPage($page, $per)->values(), $lists->count(), $per, $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return view('erp.price_lists', [
            'lists' => $paged,
            'allLists' => $all,
            'stats' => $stats,
            'counts' => $counts,
            'totalProducts' => Product::sellable()->count(),
            'filters' => ['q' => $q, 'status' => $status, 'basis' => $basis],
            'sort' => $sort,
            'dir' => $dir,
        ]);
    }

    /** شاشة تسعير قايمة واحدة */
    public function show(Request $request, PriceList $priceList)
    {
        $q = Product::sellable()
            ->with(['prices' => fn ($r) => $r->where('price_list_id', $priceList->id)]);

        if ($s = $request->string('q')->trim()->value()) {
            $q->where(fn ($w) => $w->where('name', 'like', "%$s%")
                ->orWhere('name_en', 'like', "%$s%")
                ->orWhere('code', 'like', "%$s%"));
        }

        if ($fam = $request->string('family')->value()) {
            $q->where('family', $fam);
        }

        // ⚠️ **«الناقص بس» هو الفلتر اللي بيخلّص الشغل.** القايمة
        // الجديدة كلها ناقصة، وبعد أول جولة تسعير الباقي بيقل —
        // والفلتر ده بيوصّلك للي فاضل من غير ما تدوّر.
        if ($request->boolean('missing')) {
            $q->whereKey($priceList->missing()->pluck('id'));
        }

        // مين على القايمة + أساسها — نفس حساب شاشة القوايم بالظبط
        $stats = \App\Services\PriceListOverview::forLists(collect([$priceList]))[$priceList->id];

        return view('erp.price_list', [
            'list' => $priceList,
            'stats' => $stats,
            'products' => $q->orderBy('family')->orderBy('code')->get(),
            'families' => \App\Models\ProductFamily::options(),
            'filters' => $request->only(['q', 'family', 'missing']),
            'missing' => $priceList->missingCount(),
            'total' => Product::sellable()->count(),
            // ⚠️ القايمة الافتراضية بتتعرض جنب كل صنف كمرجع — اللي
            // بيسعّر محتاج يشوف السعر الحالي عشان يقرر الجديد.
            'reference' => PriceList::default(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', 'alpha_dash', Rule::unique('price_lists', 'code')],
            'name' => ['required', 'string', 'max:190'],
            'name_en' => ['nullable', 'string', 'max:190'],
            'notes' => ['nullable', 'string'],
            // ⚠️ النسخ من قايمة موجودة بيوفّر إعادة كتابة 31 سعر
            // عشان تغيّر خمسة. القايمة المنسوخة بتفضل موقوفة برضه.
            'copy_from' => ['nullable', 'exists:price_lists,id'],
        ]);

        $list = DB::transaction(function () use ($data, $request) {
            $list = PriceList::create([
                'code' => $data['code'],
                'name' => $data['name'],
                'name_en' => $data['name_en'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()->id,
                'active' => false,
                'is_default' => false,
            ]);

            if (! empty($data['copy_from'])) {
                foreach (PriceListItem::where('price_list_id', $data['copy_from'])->get() as $it) {
                    $list->items()->create([
                        'product_id' => $it->product_id,
                        'price' => $it->price,
                    ]);
                }
            }

            return $list;
        });

        return redirect()->route('erp.prices.show', $list)
            ->with('ok', __('price.list_created', ['name' => $list->displayName()]));
    }

    public function update(Request $request, PriceList $priceList)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'name_en' => ['nullable', 'string', 'max:190'],
            'notes' => ['nullable', 'string'],
        ]);

        $priceList->update($data);

        return back()->with('ok', __('price.list_updated'));
    }

    /**
     * حفظ الأسعار.
     *
     * ⚠️ **الصف اللي مااتغيرش مابيتلمسش.** الشاشة بتبعت كل الصفوف
     * الظاهرة، وكتابة الكل بيغيّر `updated_at` لأصناف محدش لمسها —
     * فتاريخ آخر تعديل سعر يبقى مالوش معنى.
     */
    public function savePrices(Request $request, PriceList $priceList)
    {
        $data = $request->validate([
            'prices' => ['required', 'array'],
            'prices.*' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
        ]);

        // ⚠️ **المفاتيح لازم تتفلتر مقابل منتجات حقيقية.** المفتاح
        // `product_id` جاي من الفورم؛ تاب مفتوحة من قبل ما الكتالوج
        // يتغيّر بتبعت أكواد اتمسحت، والـFK بيرمي 500 والترانزاكشن
        // كلها ترجع.
        $known = Product::whereIn('id', array_keys($data['prices']))
            ->where('kind', Product::KIND_FINISHED)   // ١٩/١٠: الخامة مالهاش سعر بيع
            ->pluck('id')->all();
        $rows = array_intersect_key($data['prices'], array_flip($known));

        if ($rows === []) {
            return back()->withErrors(['prices' => __('price.rows_stale')]);
        }

        // ═══ Lane 13: مفيش كتابة هنا — مسودة ومعاينة ═══
        // الكتابة نفسها اتنقلت لـ`PriceChange::writeListPrices` (نفس الحلقة
        // بالحرف + الكتابة العكسية لعمودي الصنف)، وبتتنفذ عند «تأكيد» أو
        // «احفظ للبيع الجديد بس» من صفحة المعاينة.
        return $this->draftOrNothing($request, $priceList, $rows, __('price.prices_saved', [
            'count' => 0,
            'missing' => $priceList->missingCount(),
        ]));
    }

    /**
     * مسودة تعديل للصفوف اللي فعلاً مختلفة — أو رسالة «مفيش تغيير».
     *
     * @param  array<int|string, mixed>  $rows  product_id => سعر
     */
    private function draftOrNothing(Request $request, PriceList $priceList, array $rows, string $nothingMsg, int $skipped = 0)
    {
        try {
            $run = \App\Services\Reprice\PriceChange::draft($request->user(), 'list', [
                'price_list_id' => $priceList->id,
                'prices' => $rows,
            ]);
        } catch (\App\Exceptions\Rejected $e) {
            if ($e->getMessage() === __('reprice.nothing_changed')) {
                return back()->with('ok', $nothingMsg);
            }

            return back()->withErrors(['prices' => $e->getMessage()]);
        }

        // التسعير الجماعي: الأصناف اللي اتسابت من غير سعر أساس لازم تبان
        // في المعاينة كمان، مش في رسالة «مفيش تغيير» بس
        $ok = __('reprice.draft_created');
        if ($skipped > 0) {
            $ok .= ' · '.__('reprice.draft_skipped', ['n' => $skipped]);
        }

        return redirect()->route('erp.prices.change', $run)->with('ok', $ok);
    }

    /**
     * تسعير جماعي — نسبة أو مبلغ على المعلّم عليهم.
     *
     * ⚠️ **بيتحسب من القايمة المرجعية مش من نفسه.** «زوّد 10%» على
     * قايمة فاضية بيدّي صفر. المرجع بيتحدد صراحةً، والحساب بيرفض
     * الصنف اللي مالوش سعر في المرجع بدل ما يحطّه صفر.
     */
    public function bulk(Request $request, PriceList $priceList)
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'mode' => ['required', Rule::in(['set', 'pct', 'amount', 'copy'])],
            'value' => ['required_unless:mode,copy', 'nullable', 'numeric'],
            'from_list' => ['required_if:mode,copy', 'nullable', 'exists:price_lists,id'],
            'round' => ['nullable', 'numeric', 'min:0'],
        ]);

        $ids = Product::sellable()->whereIn('id', $data['ids'])->pluck('id');

        if ($ids->isEmpty()) {
            return back()->withErrors(['ids' => __('price.rows_stale')]);
        }

        $source = $data['mode'] === 'copy'
            ? PriceList::find($data['from_list'])
            : $priceList;

        $skipped = 0;
        $rows = [];

        // ⚠️ نفس حساب السعر بالحرف — بس بيتجمّع في `$rows` بدل ما يتكتب.
        // الكتابة (`updateOrCreate` + العمود العكسي) بقت في
        // `PriceChange::writeListPrices` بعد المعاينة (Lane 13).
        foreach ($ids as $pid) {
            $base = $source?->priceFor($pid) ?? 0.0;

            $price = match ($data['mode']) {
                'set' => (float) $data['value'],
                'copy' => $base,
                // ⚠️ الصنف اللي مالوش سعر أساس بيتساب — «زوّد 10%»
                // على صفر بتدّي صفر، وده سعر بيع مجاني.
                'pct' => $base > 0 ? $base * (1 + ((float) $data['value'] / 100)) : 0.0,
                'amount' => $base > 0 ? $base + (float) $data['value'] : 0.0,
                default => 0.0,
            };

            if ($price <= 0) {
                $skipped++;

                continue;
            }

            // تقريب اختياري — لأقرب 0.25 أو 0.50 مثلاً
            if (! empty($data['round']) && (float) $data['round'] > 0) {
                $step = (float) $data['round'];
                $price = round($price / $step) * $step;
            }

            $rows[(int) $pid] = round($price, 4);
        }

        return $this->draftOrNothing($request, $priceList, $rows, __('price.bulk_done', [
            'count' => 0,
            'skipped' => $skipped,
        ]), $skipped);
    }

    public function activate(PriceList $priceList)
    {
        if ($error = $priceList->activate()) {
            return back()->withErrors(['active' => $error]);
        }

        return back()->with('ok', __('price.activated', ['name' => $priceList->displayName()]));
    }

    public function deactivate(PriceList $priceList)
    {
        if ($error = $priceList->deactivate()) {
            return back()->withErrors(['active' => $error]);
        }

        return back()->with('ok', __('price.deactivated'));
    }

    public function makeDefault(PriceList $priceList)
    {
        if ($error = $priceList->setDefault()) {
            return back()->withErrors(['is_default' => $error]);
        }

        return back()->with('ok', __('price.default_set', ['name' => $priceList->displayName()]));
    }
}
