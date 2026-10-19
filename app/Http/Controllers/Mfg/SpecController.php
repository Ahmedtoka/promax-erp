<?php

namespace App\Http\Controllers\Mfg;

use App\Http\Controllers\Controller;
use App\Models\ProductSpec;
use App\Support\Csv;
use App\Support\DateRange;
use Illuminate\Http\Request;

/**
 * مواصفات المنتجات (التصنيع ١٩/١٠، spec §3.5) — القايمة.
 * الإصدار الجديد والتعديل والمراجعة والاعتماد بيتزوّدوا مع مهمتهم.
 *
 * ⚠️ الفلتر على `updated_at` (آخر تعديل للمواصفة)، والتصدير من نفس الكويري.
 */
class SpecController extends Controller
{
    public function index(Request $request)
    {
        // قيمة مش معروفة = من غير فلتر (مفيش 422 على شاشة GET)
        $picked = $request->string('status')->value();
        $filters = ['status' => in_array($picked, ProductSpec::STATUSES, true) ? $picked : null];

        $q = ProductSpec::query()->with('product');

        if ($status = $filters['status'] ?? null) {
            $q->where('status', $status);
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
        ]);
    }
}
