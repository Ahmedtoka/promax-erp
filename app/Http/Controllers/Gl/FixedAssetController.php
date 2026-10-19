<?php

namespace App\Http\Controllers\Gl;

use App\Exceptions\Rejected;
use App\Http\Controllers\Controller;
use App\Models\CostCenter;
use App\Models\ExpenseCategory;
use App\Models\FixedAsset;
use App\Models\Gl\GlAccount;
use App\Services\Cash\Depreciation;
use App\Support\Csv;
use App\Support\DateRange;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * سجل الأصول الثابتة (Lane 4، L4-D10..D12): التكلفة، مجمع الإهلاك،
 * القيمة الدفترية، وقسط الشهر. الأصل بيتولد لوحده من سند مصروف «أصل»
 * لما يتدفع (`ExpenseFlow::afterPaid`)، أو يتسجل يدوي هنا كأصل موجود
 * قبل النظام (افتتاحي — **مابيترحّلش من هنا**، Lane 3 بيرحّل
 * `openingEntry()` مع الأرصدة الافتتاحية).
 *
 * الفلتر الزمني على `start_date` (تاريخ بداية الإهلاك = تاريخ الشغل).
 * تشغيل الإهلاك أدمن بس (`act.gl.admin`) — نفس `promax:depreciate`.
 */
class FixedAssetController extends Controller
{
    public function index(Request $request)
    {
        $range = DateRange::fromRequest($request);
        $q = FixedAsset::with(['category', 'expense', 'costCenter'])->withSum('depreciationRows', 'amount')
            ->tap(fn ($q) => $range->apply($q, 'start_date'))
            ->when($request->filled('category'), fn ($q) => $q->where('category_id', (int) $request->query('category')))
            ->when($request->filled('cost_center_id'), fn ($q) => $q->where('cost_center_id', (int) $request->query('cost_center_id')))
            ->when(in_array($request->query('status'), FixedAsset::STATUSES, true), fn ($q) => $q->where('status', $request->query('status')))
            ->orderByDesc('start_date')->orderByDesc('id');

        // الشهر اللي بيتعرض قسطه = الشهر اللي فات (نفس افتراض الكوماند)
        $month = now('Africa/Cairo')->subMonthNoOverflow()->format('Y-m');
        $all = (clone $q)->limit(5000)->get();
        $row = fn (FixedAsset $a) => [
            'asset' => $a,
            'accum' => $a->accumulated(),
            'book' => $a->bookValue(),
            'monthly' => $a->monthlyAmount(),
        ];

        if ($request->boolean('export')) {
            $rows = $all->map($row);

            return Csv::download('fixed-assets-'.now()->format('Y-m-d-Hi').'.csv',
                [__('cash.code'), __('cash.name'), __('cash.exp_category'), __('cash.asset_expense'), __('cash.cost_center'),
                    __('cash.asset_start'), __('cash.asset_life'), __('cash.asset_cost'), __('cash.asset_salvage'),
                    __('cash.asset_monthly'), __('cash.asset_accum'), __('cash.asset_book'), __('cash.asset_status')],
                $rows->map(fn ($r) => [
                    $r['asset']->code, $r['asset']->displayName(), $r['asset']->category?->displayName() ?? '',
                    (string) $r['asset']->expense?->number, $r['asset']->costCenter?->displayName() ?? '',
                    $r['asset']->start_date?->format('Y-m-d'), (string) $r['asset']->life_months,
                    Csv::money($r['asset']->cost), Csv::money($r['asset']->salvage), Csv::money($r['monthly']),
                    Csv::money($r['accum']), Csv::money($r['book']), __('cash.asset_status_'.$r['asset']->status),
                ]),
                ['', __('cash.total'), '', '', '', '', '', Csv::money($rows->sum(fn ($r) => (float) $r['asset']->cost)), '', '',
                    Csv::money($rows->sum('accum')), Csv::money($rows->sum('book')), ''],
                Csv::meta(__('cash.assets'), $range->fromValue() ?: null, $range->toValue() ?: null));
        }

        $page = (clone $q)->paginate(50)->withQueryString();
        $page->setCollection($page->getCollection()->map($row));

        // الأرقام فوق من نفس الكويري المفلترة (KPI مايخلطش نطاقين)
        $sumCost = round((float) $all->sum(fn ($a) => (float) $a->cost), 2);
        $sumAccum = round((float) $all->sum(fn ($a) => $a->accumulated()), 2);
        $done = \App\Models\FixedAssetDepreciation::where('period_key', $month)->pluck('fixed_asset_id')->all();

        return view('gl.assets', [
            'range' => $range,
            'rows' => $page,
            'month' => $month,
            'kpis' => [
                'n' => $all->count(),
                'cost' => $sumCost,
                'accum' => $sumAccum,
                'book' => round($sumCost - $sumAccum, 2),
                // اللي اترحّل خلاص للشهر ده بره الرقم — «لو اتشغّل» = الجديد بس
                'next' => round((float) $all->whereNotIn('id', $done)->sum(fn ($a) => $a->amountFor($month)), 2),
            ],
            'categories' => ExpenseCategory::where('type', 'asset')->orderBy('code')->get(),
            'costCenters' => CostCenter::where('active', true)->orderBy('code')->get(['id', 'code', 'name', 'name_en']),
        ]);
    }

    /**
     * أصل موجود قبل النظام (L4-D12) — صف بس، من غير قيد. الأصل اللي
     * اتشرى من خلال النظام بيتعمل من سند المصروف مش من هنا.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'name_en' => ['nullable', 'string', 'max:150'],
            'category_id' => ['required', 'integer', Rule::exists('expense_categories', 'id')->where('type', 'asset')],
            'cost' => ['required', 'numeric', 'min:0.01', 'max:999999999'],
            'salvage' => ['nullable', 'numeric', 'min:0', 'lt:cost'],
            'life_months' => ['required', 'integer', 'min:1', 'max:600'],
            'start_date' => ['required', 'date'],
            'cost_center_id' => ['nullable', 'integer', Rule::exists('cost_centers', 'id')->where('active', 1)],
            'opening_accum' => ['nullable', 'numeric', 'min:0'],
            'opening_as_of' => ['nullable', 'date', 'after_or_equal:start_date', 'before_or_equal:today'],
        ], [], [
            'category_id' => __('cash.exp_category'),
        ]);

        $salvage = round((float) ($data['salvage'] ?? 0), 2);
        $accum = round((float) ($data['opening_accum'] ?? 0), 2);
        if ($accum > round((float) $data['cost'] - $salvage, 2)) {
            return back()->withErrors(['opening_accum' => __('cash.asset_accum_too_big')])->withInput();
        }
        // ⚠️ أصل بدأ قبل الشهر اللي فات من غير «لحد تاريخ»: الشهور القديمة
        // مش هتتشغّل أبداً، وآخر شهر في العمر كان هياخد الباقي كله مرة واحدة
        $lastMonth = now('Africa/Cairo')->subMonthNoOverflow()->startOfMonth()->toDateString();
        $hasAsOf = ! empty($data['opening_as_of']);
        if (($accum > 0 && ! $hasAsOf) || (! $hasAsOf && $data['start_date'] < $lastMonth)) {
            return back()->withErrors(['opening_as_of' => __('cash.asset_opening_required')])->withInput();
        }

        $cat = ExpenseCategory::findOrFail($data['category_id']);
        $accountId = $cat->account_id ?? GlAccount::where('system_key', 'fixed_assets')->value('id');
        if ($accountId === null) {
            return back()->withErrors(['category_id' => __('cash.exp_category_no_account')])->withInput();
        }
        DB::transaction(function () use ($data, $cat, $salvage, $accum, $accountId, $request) {
            FixedAsset::create([
                'code' => FixedAsset::nextNumber(),
                'name' => $data['name'],
                'name_en' => $data['name_en'] ?? null,
                'category_id' => $cat->id,
                'cost' => round((float) $data['cost'], 2),
                'salvage' => $salvage,
                'life_months' => (int) $data['life_months'],
                'start_date' => $data['start_date'],
                'cost_center_id' => $data['cost_center_id'] ?? null,
                'asset_account_id' => $accountId,
                'opening_accum' => $accum,
                'opening_as_of' => $data['opening_as_of'] ?? null,
                'created_by' => $request->user()->id,
            ]);
        });

        return redirect()->route('gl.assets')->with('ok', __('cash.asset_saved'));
    }

    /** تشغيل الإهلاك لشهر من الشاشة — نفس `promax:depreciate` (أدمن بس) */
    public function depreciate(Request $request, Depreciation $svc)
    {
        $data = $request->validate(['month' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);

        try {
            $r = $svc->run($data['month'], $request->user());
        } catch (Rejected $e) {
            return back()->withErrors(['month' => $e->getMessage()]);
        }

        return back()->with('ok', __('cash.depr_done', [
            'month' => $data['month'], 'n' => $r['created'], 'amount' => number_format($r['amount'], 2),
        ]));
    }
}
