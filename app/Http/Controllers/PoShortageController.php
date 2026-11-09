<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\ClientGroup;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\PoShortage;
use Illuminate\Http\Request;

/**
 * الطلب مقابل المخزون (٩/١١/٢٠٢٦) — التعريفات كلها في `PoShortage`.
 */
class PoShortageController extends Controller
{
    public function index(Request $request)
    {
        // الفلتر السريع — الافتراضي «مستني الحسابات» (طلب المالك): ده
        // الطلب اللي لسه ماحجزش مخزون، وهو اللي العجز بيطلع منه
        if (! in_array($request->string('scope')->value(), OpsController::PO_SCOPES, true)) {
            $request->merge(['scope' => 'awaiting']);
        }

        $svc = new PoShortage($request);
        $report = $svc->report();

        $rows = $report['rows'];
        if ($request->boolean('short')) {
            $rows = $rows->where('short_units', '>', 0)->values();
        }

        $productId = $request->integer('product') ?: null;
        $orders = $productId ? $svc->orders($productId) : $svc->orders()->reject(fn ($o) => $o->covered)->values();

        return view('ops.po_shortage', [
            'rows' => $rows,
            't' => $report['totals'],
            'batches' => $svc->batches($rows->pluck('product_id')->all()),
            'product' => $productId ? Product::find($productId) : null,
            'productRow' => $productId ? $report['rows']->firstWhere('product_id', $productId) : null,
            // الأوامر: لصنف معيّن (درِل داون) أو المحتاجة مخزون كلها
            'orders' => $orders,
            'channels' => Channel::orderBy('id')->get(),
            'groups' => ClientGroup::whereHas('clients')->orderBy('name')->get(['id', 'name', 'name_en']),
            'reps' => User::fieldVisibleTo(User::whereIn('role', User::FIELD_WORK_ROLES))->orderBy('name')->get(),
            'warehouses' => Warehouse::where('active', true)->orderBy('id')->get(),
            // كل المخازن (حتى الموقوفة) — أسماء الشارات
            'whModels' => Warehouse::get()->keyBy('id'),
            'filters' => $request->only(['scope', 'status', 'approval', 'q', 'channel', 'group', 'rep', 'from', 'to', 'warehouse', 'short', 'product', 'late']),
        ]);
    }
}
