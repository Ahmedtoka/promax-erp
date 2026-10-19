<?php

namespace App\Http\Controllers\Mfg;

use App\Http\Controllers\Controller;
use App\Models\CostCenter;
use App\Models\Setting;
use App\Models\Warehouse;
use App\Services\Mfg\LineHours;
use App\Services\Mfg\Orders;
use App\Support\Csv;
use App\Support\DateRange;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * خطوط الإنتاج (التصنيع ١٩/١٠) — مراكز تكلفة نوعها `line` تحت المصنع `FAC`.
 *
 * العرض والكتابة `role:admin,production_manager`؛ مخازن التصنيع الافتراضية
 * (`mfg_raw_warehouse_id` / `mfg_fg_warehouse_id`) أدمن بس.
 *
 * ⚠️ الفترة (من/إلى، الديفولت الشهر ده) على `finished_at` بتاع الأوامر —
 * الساعات اللي الخط اشتغلها فيها. التصدير من نفس الحساب.
 */
class LineController extends Controller
{
    public function index(Request $request)
    {
        $range = DateRange::fromRequest($request, 'month');
        $from = $range->from ?? today()->startOfMonth();
        $to = $range->to ?? today();

        $lines = CostCenter::query()->where('kind', 'line')->with('parent')
            ->orderByDesc('active')->orderBy('code')->get()
            ->each(fn (CostCenter $l) => $l->setAttribute('hours_in_range', LineHours::forRange($l, $from, $to)));

        if ($request->boolean('export')) {
            return Csv::download('production-lines-'.now()->format('Y-m-d-Hi').'.csv', [
                __('mfg.col_code'), __('mfg.col_name'), __('mfg.col_name_en'), __('mfg.col_active'), __('mfg.col_hours'),
            ], $lines->map(fn (CostCenter $l) => [
                $l->code, $l->name, $l->name_en ?? '', $l->active ? __('mfg.yes') : __('mfg.no'), $l->hours_in_range,
            ]), null, Csv::meta(__('mfg.lines_title'), $range->fromValue() ?: null, $range->toValue() ?: null));
        }

        return view('mfg.lines', [
            'lines' => $lines,
            'range' => $range,
            'warehouses' => Warehouse::query()->where('active', true)->orderBy('name')->get(),
            'rawId' => (int) Setting::read(Orders::SETTING_RAW),
            'fgId' => (int) Setting::read(Orders::SETTING_FG),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique('cost_centers', 'code')],
            'name' => ['required', 'string', 'max:120'],
            'name_en' => ['nullable', 'string', 'max:120'],
        ]);

        CostCenter::create([
            'code' => trim($data['code']),
            'name' => trim($data['name']),
            'name_en' => ($e = trim((string) ($data['name_en'] ?? ''))) === '' ? null : $e,
            'kind' => 'line',
            'parent_id' => self::factory()->id,
            'active' => true,
        ]);

        return back()->with('ok', __('mfg.flash_line_created'));
    }

    public function update(Request $request, CostCenter $line): RedirectResponse
    {
        abort_unless($line->kind === 'line', 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'name_en' => ['nullable', 'string', 'max:120'],
            'active' => ['nullable', 'boolean'],
        ]);

        $line->forceFill([
            'name' => trim($data['name']),
            'name_en' => ($e = trim((string) ($data['name_en'] ?? ''))) === '' ? null : $e,
            'active' => $request->boolean('active'),
        ])->save();

        return back()->with('ok', __('mfg.flash_line_saved'));
    }

    /** مخزن الخامات ومخزن التام الافتراضيين لأوامر الإنتاج (D10) — أدمن بس */
    public function settings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'mfg_raw_warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')],
            'mfg_fg_warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')],
        ]);

        Setting::writeMany([
            Orders::SETTING_RAW => isset($data['mfg_raw_warehouse_id']) ? (string) $data['mfg_raw_warehouse_id'] : null,
            Orders::SETTING_FG => isset($data['mfg_fg_warehouse_id']) ? (string) $data['mfg_fg_warehouse_id'] : null,
        ]);

        return back()->with('ok', __('mfg.flash_settings_saved'));
    }

    /** مركز المصنع `FAC` تحت الشركة — بيتعمل لو مش موجود (زي `CostCenter::company()`) */
    private static function factory(): CostCenter
    {
        return CostCenter::firstOrCreate(
            ['code' => 'FAC'],
            ['name' => 'المصنع', 'name_en' => 'Factory', 'kind' => 'factory',
                'parent_id' => CostCenter::company()->id, 'active' => true],
        );
    }
}
