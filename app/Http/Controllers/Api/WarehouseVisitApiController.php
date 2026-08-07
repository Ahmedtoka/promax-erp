<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use App\Services\WarehouseVisits;
use Illuminate\Http\Request;

/**
 * دخول وخروج المخزن من الأبلكيشن (2026-08-08).
 *
 * ⚠️ **الاتنين خارج حارس `in.warehouse`.** دي الحاجة اللي بتفتح
 * الحارس أصلاً — حطها جوّاه كان بيعمل دايرة مقفولة.
 */
class WarehouseVisitApiController extends Controller
{
    /** المندوب بيسجّل دخوله مخزن معيّن */
    public function checkIn(Request $request)
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            // ⚠️ **اللوكيشن `nullable` مش `required`.** لو الـGPS مقفول
            // أو الإذن مرفوض، مايصحّش نمنع المندوب من الشغل — كان
            // هيقف الاستلام كله على إعداد في التليفون. اللي مالوش
            // لوكيشن بيبان في الشاشة كده وبيتسأل.
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $warehouse = Warehouse::findOrFail($data['warehouse_id']);

        if (! $warehouse->active) {
            return response()->json(['message' => __('warehouse.inactive')], 422);
        }

        [$err, $visit] = WarehouseVisits::checkIn(
            $request->user(),
            $warehouse,
            ...static::egyptPoint($data),
        );

        if ($err !== null) {
            return response()->json(['message' => $err], 422);
        }

        return response()->json(['visit' => $visit->payload()]);
    }

    /** خروج من المخزن */
    public function checkOut(Request $request)
    {
        $data = $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $visit = WarehouseVisits::open($request->user());

        if ($visit === null) {
            return response()->json(['message' => __('warehouse.not_inside')], 422);
        }

        $closed = WarehouseVisits::close($visit, ...static::egyptPoint($data));

        return response()->json(['visit' => $closed->payload()]);
    }

    /**
     * ⚠️ **نفس فلتر `FieldApiController::egyptPoint`.** الإميوليتر
     * بيدي كاليفورنيا، والنقطة دي بتتخزن في `warehouse_visits` وبعدين
     * بتتحسب منها «المسافة من المخزن» فتطلع 9000 كيلومتر.
     *
     * @return array{0: ?float, 1: ?float}
     */
    private static function egyptPoint(array $data): array
    {
        $lat = isset($data['lat']) ? (float) $data['lat'] : null;
        $lng = isset($data['lng']) ? (float) $data['lng'] : null;

        return $lat !== null && $lng !== null && \App\Support\MapLink::inEgypt($lat, $lng)
            ? [round($lat, 7), round($lng, 7)]
            : [null, null];
    }
}
