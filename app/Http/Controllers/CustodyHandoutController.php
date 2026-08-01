<?php

namespace App\Http\Controllers;

use App\Models\PickOrder;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
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
            'reps' => User::whereIn('role', ['sales_agent', 'driver', 'promoter'])
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
            'open' => PickOrder::where('status', 'ready')
                ->whereNotNull('issued_at')
                ->with(['rep', 'warehouse', 'items.product'])
                ->latest('issued_at')->take(30)->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'rep_id' => ['required', 'exists:users,id'],
            'carrier_note' => ['nullable', 'string', 'max:190'],
            'qty' => ['nullable', 'array'],
            'qty.*' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'gift' => ['nullable', 'array'],
            'gift.*' => ['nullable', 'integer', 'min:0', 'max:999999'],
        ]);

        $warehouse = Warehouse::findOrFail($data['warehouse_id']);
        $rep = User::findOrFail($data['rep_id']);

        // ⚠️ **الرول بيتفحص هنا مش في الفاليديشن.** `exists:users,id`
        // بتقول «اليوزر ده موجود» مش «ده مندوب» — ومن غير الفحص ده
        // ينفع تتحمّل عهدة على محاسب أو أدمن، وتقفل عهدة محدش
        // هيقفلها.
        if (! in_array($rep->role, ['sales_agent', 'driver', 'promoter'], true)) {
            return back()->withErrors(['rep_id' => __('field.not_a_field_role')])->withInput();
        }

        $result = PickOrder::issueDirect(
            $warehouse,
            $rep,
            $data['qty'] ?? [],
            $data['gift'] ?? [],
            $request->user(),
            $data['carrier_note'] ?? null,
        );

        if ($result['error'] !== null) {
            return back()->withErrors(['qty' => $result['error']])->withInput();
        }

        // ⚠️ **بيروح على الورقة على طول.** اللي بيحمّل العربية واقف
        // جنبها ومش هيفتكر يطبع بعدين — والشحنة اللي مشيت من غير
        // إمضاء مالهاش إثبات.
        return redirect()
            ->route('ops.handout.print', $result['order'])
            ->with('ok', __('field.handout_done', [
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
