<?php

namespace App\Http\Controllers;

use App\Models\PickOrder;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Scope;
use Illuminate\Http\Request;

/**
 * ═══════════════════════════════════════════════════════════════
 * تسليم العهدة — من المخزن للمندوب في خطوة واحدة
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **الشاشة دي بتخرّج بضاعة فوراً.** أول ما تدوس تسليم، الكراتين
 * بتنزل من الأرفف ومن الباتشات وبتبقى مسؤولية المندوب — حتى قبل ما
 * يدوس استلام على الأبلكيشن. عشان كده الورقة بتتطبع على طول: البضاعة
 * اللي مشيت من غير ورق ممضي مالهاش إثبات.
 *
 * ⚠️ **بتستخدم `PickOrder::issueDirect` مش خصم مستقل.** الخصم من
 * الباتش لوحده (من غير الرف) هو نفس الغلطة اللي التحويلات وقعت فيها
 * وخلّت البضاعة تتباع مرتين.
 */
class CustodyHandoutController extends Controller
{
    public function index(Request $request)
    {
        $warehouse = $this->warehouse($request);

        return view('ops.handout', [
            'warehouse' => $warehouse,
            'warehouses' => Warehouse::where('active', true)->orderBy('type')->orderBy('code')->get(),
            'reps' => User::fieldVisibleTo(User::whereIn('role', ['sales_agent', 'driver', 'promoter']))
                ->where('active', true)->orderBy('name')->get(),
            // ⚠️ **المتاح من الأرفف مش من `stocks`.** أمر التجهيز
            // بيخصم من الأرفف، فالرقم اللي بيتعرض لازم يكون هو نفسه
            // اللي هيتفحص وقت التسليم — وإلا الشاشة بتقول «متاح 40»
            // والتسليم بيرفض.
            'products' => $warehouse
                ? Product::where('active', true)->orderBy('family')->orderBy('code')->get()
                    ->map(function (Product $p) use ($warehouse) {
                        $p->available = $warehouse->availableFor($p->id);

                        return $p;
                    })
                : collect(),
            // ⚠️ تحت التجهيز — طلبات لسه المخزن مأكدهاش. من هنا بيطبع
            // الورقة تاني وبيروح يأكد من شاشة «تجهيز الطلبات».
            'preparing' => PickOrder::where('purpose', PickOrder::PURPOSE_VAN_LOAD)
                ->whereIn('status', ['requested', 'picking'])
                ->with(['rep', 'warehouse', 'items.product'])
                ->latest()->take(30)->get(),
            // جاهزة ومستنية المندوب يستلم من الأبلكيشن
            // ⚠️ مش بنشترط issued_at — أوامر الفلو الجديد بتتأكد من
            // شاشة التجهيز من غير ما حد يملاه
            'open' => PickOrder::where('purpose', PickOrder::PURPOSE_VAN_LOAD)
                ->where('status', 'ready')
                ->with(['rep', 'warehouse', 'items.product'])
                ->latest('ready_at')->take(30)->get(),
            // ⚠️ **الهيستوري** — كل تحميلات العربيات اللي اتسلّمت:
            // مين وإمتى وبكام، وإعادة طباعة الورقة في أي وقت.
            // القيمة بتتحسب بقايمة المندوب (السواق قديمة والسيلز جديدة)
            // — نفس منطق عرض العهدة في الأبلكيشن.
            'done' => PickOrder::where('purpose', PickOrder::PURPOSE_VAN_LOAD)
                ->where('status', 'handed')
                ->with(['rep', 'warehouse', 'items.product'])
                ->latest('handed_at')->take(40)->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'rep_id' => ['required', 'exists:users,id'],
            'carrier_note' => ['nullable', 'string', 'max:190'],
            // موعد وصول المندوب المخزن — يوم وساعة
            'pickup_at' => ['nullable', 'date'],
            'qty' => ['nullable', 'array'],
            'qty.*' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'gift' => ['nullable', 'array'],
            'gift.*' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'unit' => ['nullable', 'array'],
            'unit.*' => ['nullable', 'in:piece,box,case'],
            // ═══ وحدة الهدايا منفصلة (قرار المالك ٨/٨/٢٠٢٦) ═══
            // ⚠️ **البيع بالكرتونة والهدية بالقطعة** — ده الواقع:
            // «١٠ كراتين بيع + ٣ قطع هدية». وحدة واحدة للاتنين كانت
            // بتخلّي أمين المخزن يحوّل الهدية بإيده لكراتين ويكتب
            // كسر، أو يسيبها بوحدة البيع فتتضرب × ١٢ في السيرفر
            // وتخرج ٣٦ قطعة هدية بدل ٣.
            'gift_unit' => ['nullable', 'array'],
            'gift_unit.*' => ['nullable', 'in:piece,box,case'],
        ]);

        // ⚠️ **وحدة الإدخال بتتضرب هنا مش في الجافاسكريبت.** المستخدم
        // كتب «5 كرتونة اسبريد» — بنحوّلها 60 قطعة قبل ما توصل
        // لـ requestLoad، والعهدة كلها بالقطعة زي ما هي. وحدة مش
        // معرّفة للصنف = رفض الأمر كله، مش افتراض إنها قطعة.
        // ⚠️ **كل خانة بوحدتها هي** — `unit` للبيع و`gift_unit`
        // للهدية. اللوب واحد على الاتنين عشان منطق الرفض يفضل
        // مكتوب مرة واحدة.
        foreach ([['qty', 'unit'], ['gift', 'gift_unit']] as [$qtyKey, $unitKey]) {
            foreach ($request->input($unitKey, []) as $productId => $unit) {
                if (! $unit || $unit === 'piece' || empty($data[$qtyKey][$productId])) {
                    continue;
                }

                $factor = Product::find($productId)?->unitFactor($unit);

                if ($factor === null) {
                    return back()->withErrors([
                        'qty' => __('stock.unit_not_for_product', ['name' => Product::find($productId)?->displayName() ?? $productId]),
                    ])->withInput();
                }

                $data[$qtyKey][$productId] = (int) $data[$qtyKey][$productId] * $factor;
            }
        }

        $warehouse = Warehouse::findOrFail($data['warehouse_id']);
        $rep = User::findOrFail($data['rep_id']);

        // ⚠️ **الرول بيتفحص هنا مش في الفاليديشن.** `exists:users,id`
        // بتقول «اليوزر ده موجود» مش «ده مندوب» — ومن غير الفحص ده
        // ينفع تتحمّل عهدة على محاسب أو أدمن، وتقفل عهدة محدش
        // هيقفلها.
        if (! in_array($rep->role, ['sales_agent', 'driver', 'promoter'], true)) {
            return back()->withErrors(['rep_id' => __('field.not_a_field_role')])->withInput();
        }

        // ⚠️ **والفريق كمان، مش الرول بس** (تدقيق ٨/٨/٢٠٢٦): الفحص
        // فوق بيمنع تحميل عهدة على محاسب، بس كان بيسيب مدير يحمّل
        // عربية مندوب مدير تاني — بضاعة بتخرج من مخزن وتقع في تصفية
        // فريق مالوش علاقة.
        Scope::assertRep($request->user(), $rep);

        // ⚠️ **الفلو الجديد (قرار المالك 2026-08-03): طلب مش خروج.**
        // البضاعة **مابتخرجش هنا** — بيتعمل طلب تجهيز، الورقة بتتطبع،
        // المخزن بيجهّز فيزيكال من شاشة «تجهيز الطلبات»، وتأكيد
        // التجهيز هناك هو اللي بيخرج البضاعة ويبعت إشعار للمندوب.
        $result = PickOrder::requestLoad(
            $warehouse,
            $rep,
            $data['qty'] ?? [],
            $data['gift'] ?? [],
            $request->user(),
            $data['carrier_note'] ?? null,
            $data['pickup_at'] ?? null,
        );

        if ($result['error'] !== null) {
            return back()->withErrors(['qty' => $result['error']])->withInput();
        }

        // الورقة بتتطبع فوراً — دي ورقة التجهيز اللي المخزن هيمشي بيها
        return redirect()
            ->route('ops.handout.print', $result['order'])
            ->with('ok', __('field.load_requested', [
                'number' => $result['order']->number,
                'rep' => $rep->displayName(),
            ]));
    }

    /** ورقة تسليم العهدة — للإمضاء */
    public function print(Request $request, PickOrder $pick)
    {
        $pick->load(['items.product', 'items.batch', 'rep', 'warehouse', 'picker']);

        return view('ops.handout_print', ['o' => $pick]);
    }

    private function warehouse(Request $request): ?Warehouse
    {
        $user = $request->user();

        if ($id = $request->integer('warehouse')) {
            $w = Warehouse::find($id);

            // ⚠️ أمين المخزن مايسلّمش من مخزن غير بتاعه — المبدّل
            // بيعرض مخازن كتير والحارس هنا هو اللي بيمنع فعلاً.
            if ($w && (! $user?->isWarehouseKeeper() || (int) $user->warehouse_id === $w->id)) {
                return $w;
            }
        }

        if ($user?->isWarehouseKeeper() && $user->warehouse_id) {
            return Warehouse::find($user->warehouse_id);
        }

        return Warehouse::defaultBranch() ?? Warehouse::where('active', true)->first();
    }
}
