<?php

namespace App\Http\Controllers;

use App\Exceptions\Rejected;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\OpeningStock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * ═══════════════════════════════════════════════════════════════
 * إدارة المخازن — التعريف والأرصدة اليدوية
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **الكنترولر ده منفصل عن `WarehouseController` عن قصد.**
 * التاني شغل يومي: استلام، ترصيف، تحويل، جرد — وأمين المخزن بيدخله.
 * ده تعريف وتعديل أرصدة بالإيد، وده قرار إدارة مش تشغيل.
 *
 * ⚠️ **تعديل الرصيد بالإيد بيمرّ بـ`OpeningStock`.** الفلو الطبيعي
 * (استلام → باتش → ترصيف) بيسيب أثر كامل. التعديل هنا للتأسيس
 * والتصحيح بس — وبيتحوّل لباتش تسوية `ADJ` مترصّف على رف سحب، عشان
 * الرقم يبان في تسليم العهدة وأوامر التجهيز مش في شاشة المخزون بس.
 */
class WarehouseAdminController extends Controller
{
    public function index(Request $request)
    {
        // ⚠️ `withSum` مش `with('stocks')` — الشاشة عايزة الإجماليات
        // بس، وتحميل كل صفوف الأرصدة لكل مخزن معناه آلاف الصفوف
        // عشان نجمعهم في PHP.
        $warehouses = Warehouse::query()
            ->withSum('stocks as qty_total', 'qty')
            ->withSum('stocks as hold_total', 'hold_qty')
            ->withCount(['stocks as sku_count' => fn ($q) => $q->where('qty', '>', 0)])
            ->with('manager')
            ->orderBy('type')
            ->orderBy('code')
            ->get();

        // قيمة بضاعة كل مخزن (كمية × سعر البيع الجديد) — للداشبورد
        // التحليلي (2026-08-06). نفس مصدر stockValue في النظرة العامة.
        $values = DB::table('stocks')
            ->join('products', 'products.id', '=', 'stocks.product_id')
            ->selectRaw('stocks.warehouse_id as wid,
                         SUM(stocks.qty * products.price_new) as val,
                         SUM(stocks.hold_qty * products.price_new) as hold_val')
            ->groupBy('stocks.warehouse_id')
            ->get()->keyBy('wid');

        return view('erp.warehouses', [
            'warehouses' => $warehouses,
            'values' => $values,
            // ⚠️ **بضاعة خرجت وماوصلتش.** من غير العمود ده، اللي بيبص
            // على المخزن بيلاقي الرصيد قلّ ومايعرفش إن الفرق ماشي على
            // الطريق — ويفتكر إنه عجز ويفتح تحقيق في حاجة طبيعية.
            'transit' => \App\Models\StockTransfer::inTransit(),
            // ⚠️ من غير الأدمنز — الموظفين بس في الدروب داونز (2026-08-05)
            'managers' => \App\Models\User::whereIn('role', ['manager', 'warehouse_keeper'])
                ->where('active', true)->orderBy('name')->get(),
            'types' => [Warehouse::TYPE_FACTORY, Warehouse::TYPE_BRANCH],
        ]);
    }

    /**
     * أرصدة مخزن واحد — للتعديل اليدوي.
     */
    public function stock(Request $request, Warehouse $warehouse)
    {
        $q = Product::query()->where('active', true);

        if ($s = $request->string('q')->trim()->value()) {
            $q->where(fn ($w) => $w->where('name', 'like', "%$s%")
                ->orWhere('name_en', 'like', "%$s%")
                ->orWhere('code', 'like', "%$s%")
                ->orWhere('barcode', 'like', "%$s%"));
        }

        if ($fam = $request->string('family')->value()) {
            $q->where('family', $fam);
        }

        $products = $q->with(['stocks' => fn ($r) => $r->where('warehouse_id', $warehouse->id)])
            ->orderBy('family')->orderBy('code')->get();

        return view('erp.warehouse_stock', [
            'w' => $warehouse,
            'products' => $products,
            'families' => \App\Models\ProductFamily::options(),
            'filters' => $request->only(['q', 'family']),
        ]);
    }

    public function store(Request $request)
    {
        Warehouse::create($this->validated($request));

        return back()->with('ok', __('stock.warehouse_added'));
    }

    public function update(Request $request, Warehouse $warehouse)
    {
        $warehouse->update($this->validated($request, $warehouse));

        return back()->with('ok', __('stock.warehouse_updated'));
    }

    /**
     * حفظ الأرصدة المعدّلة.
     *
     * ⚠️ **جوه ترانزاكشن.** الشاشة بتبعت 31 صف مع بعض — لو الصف
     * العاشر وقع، التسعة اللي قبله بيكونوا اتكتبوا والباقي لأ،
     * والمخزن بيبقى نص متعدّل ومحدش عارف فين وقف.
     */
    public function saveStock(Request $request, Warehouse $warehouse)
    {
        // ⚠️ **`max_input_vars` بيقصّ الـPOST في صمت.** الفورم بيبعت
        // خانتين لكل صنف؛ الديفولت في PHP هو 1000 متغير، يعني بعد
        // ~499 صنف الصفوف الزيادة بتتقص من غير أي خطأ — والمستخدم
        // بيشوف رسالة نجاح والباقي مااتحفظش.
        $limit = (int) ini_get('max_input_vars');

        if ($limit > 0 && count($request->input('rows', [])) * 2 + 10 >= $limit) {
            return back()->withErrors(['rows' => __('stock.too_many_rows')]);
        }

        $data = $request->validate([
            'rows' => ['required', 'array'],
            'rows.*.qty' => ['required', 'integer', 'min:0', 'max:9999999'],
            // ⚠️ `lte:rows.*.qty` مابتشتغلش على مستوى الصف في لارافيل —
            // بتقارن بكل القيم. الفحص بيتعمل بالإيد تحت.
            'rows.*.hold' => ['required', 'integer', 'min:0', 'max:9999999'],
        ], [], $this->rowLabels($request));

        $bad = [];

        foreach ($data['rows'] as $productId => $row) {
            if ((int) $row['hold'] > (int) $row['qty']) {
                $bad[] = $productId;
            }
        }

        if ($bad !== []) {
            // ⚠️ الهولد أكبر من الإجمالي بيخلّي «السليم» بالسالب،
            // والمندوب بيشوف كمية متاحة سالبة في الأبلكيشن.
            return back()->withErrors([
                'rows' => __('stock.hold_over_qty', ['count' => count($bad)]),
            ])->withInput();
        }

        // ⚠️ **مفاتيح الصفوف لازم تتفلتر مقابل منتجات حقيقية.**
        // المفتاح هو `product_id` جاي من الفورم، يعني المستخدم متحكم
        // فيه. تاب مفتوحة من قبل ما `promax:catalogue` يتشغّل بتبعت
        // أكواد منتجات اتمسحت — `Stock::firstOrNew(...)->save()` بترمي
        // خطأ الـFK، الترانزاكشن كلها ترجع، والمستخدم يشوف 500 ويفقد
        // شغل الشاشة كلها. ومفتاح مش رقم بيتحوّل لـ`product_id = 0`.
        $known = Product::whereIn('id', array_keys($data['rows']))->pluck('id')->all();
        $rows = array_intersect_key($data['rows'], array_flip($known));

        if ($rows === []) {
            return back()->withErrors(['rows' => __('stock.rows_stale')]);
        }

        $data['rows'] = $rows;
        $touched = 0;

        // ⚠️ **مش بنكتب في `stocks` مباشرة.** ده اللي عمل مشكلة اللايف
        // (2026-08-03): الرقم اليدوي كان بينزل في التجميعة من غير باتش
        // ولا رف، فشاشة المخزون تقول «موجود» وتسليم العهدة يقول
        // «المتاح 0» — لأن التسليم بيخصم من الأرفف. `OpeningStock`
        // بيعمل باتش تسوية ويرصّفه على رف سحب، و`resync` بيكتب
        // التجميعة من الباتشات — فكل الشاشات بتشوف نفس الرقم.
        try {
            DB::transaction(function () use ($data, $warehouse, &$touched) {
                $products = Product::whereIn('id', array_keys($data['rows']))->get()->keyBy('id');

                foreach ($data['rows'] as $productId => $row) {
                    $product = $products->get((int) $productId);

                    if ($product && OpeningStock::apply($warehouse, $product, (int) $row['qty'], (int) $row['hold'])) {
                        $touched++;
                    }
                }
            });
        } catch (Rejected $e) {
            // رف اتملى أو رفض ترصيف — كل الشاشة بترجع زي ما كانت
            return back()->withErrors(['rows' => $e->getMessage()])->withInput();
        }

        return back()->with('ok', __('stock.stock_saved', ['count' => $touched]));
    }

    /**
     * أسماء مفهومة لخانات الصفوف.
     *
     * ⚠️ **من غيرها رسالة الخطأ بتقول «rows.417.qty».** المفتاح هو
     * `product_id`، ومحدش بيعرف الصنف من رقمه — فالمستخدم بيقرا
     * كلمة سر ومايعرفش أنهي سطر يصلّح.
     *
     * @return array<string, string>
     */
    private function rowLabels(Request $request): array
    {
        $ids = array_keys((array) $request->input('rows', []));
        $names = Product::whereIn('id', $ids)->pluck('name', 'id');
        $out = [];

        foreach ($names as $id => $name) {
            $out["rows.{$id}.qty"] = __('stock.qty').' — '.$name;
            $out["rows.{$id}.hold"] = __('stock.hold').' — '.$name;
        }

        return $out;
    }

    private function validated(Request $request, ?Warehouse $warehouse = null): array
    {
        return $request->validate([
            // ⚠️ الكود فريد ومابيتغيرش بعد الإنشاء عملياً: متخزن على
            // أمين المخزن (`users.warehouse_id`) وعلى الباتشات والأرفف.
            'code' => ['required', 'string', 'max:20',
                Rule::unique('warehouses', 'code')->ignore($warehouse?->id)],
            'name' => ['required', 'string', 'max:190'],
            'name_en' => ['nullable', 'string', 'max:190'],
            'type' => ['required', Rule::in([Warehouse::TYPE_FACTORY, Warehouse::TYPE_BRANCH])],
            'address' => ['nullable', 'string', 'max:190'],
            'manager_id' => ['nullable', 'exists:users,id'],
        ])
            // ⚠️ **الاتحاد بيكسب لأن `validate()` عمرها ما بترجّع
            // `active`** — مش في القواعد أصلاً. عند الإضافة الديفولت
            // مفعّل، وعند التعديل التشيك بوكس مع الحقل المخفي بيقولوا
            // الحقيقة (المقفول مابيتبعتش، فالمخفي `0` هو اللي بيوصل).
            + ['active' => $warehouse ? $request->boolean('active') : $request->boolean('active', true)];
    }
}
