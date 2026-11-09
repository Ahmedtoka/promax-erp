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
        $svc = new PoShortage($request);
        $report = $svc->report();

        $rows = $report['rows'];
        if ($request->boolean('short')) {
            $rows = $rows->where('short_units', '>', 0)->values();
        }

        $productId = $request->integer('product') ?: null;

        return view('ops.po_shortage', [
            'rows' => $rows,
            't' => $report['totals'],
            'product' => $productId ? Product::find($productId) : null,
            'productRow' => $productId ? $report['rows']->firstWhere('product_id', $productId) : null,
            // الأوامر: لصنف معيّن (درِل داون) أو المحتاجة مخزون كلها
            'orders' => $productId
                ? $svc->orders($productId)
                : $svc->orders()->reject(fn ($o) => $o->covered)->values(),
            'channels' => Channel::orderBy('id')->get(),
            'groups' => ClientGroup::whereHas('clients')->orderBy('name')->get(['id', 'name', 'name_en']),
            'reps' => User::fieldVisibleTo(User::whereIn('role', User::FIELD_WORK_ROLES))->orderBy('name')->get(),
            'warehouses' => Warehouse::where('active', true)->orderBy('id')->get(),
            'filters' => $request->only(['status', 'approval', 'q', 'channel', 'group', 'rep', 'from', 'to', 'warehouse', 'short', 'product', 'late']),
        ]);
    }
}
