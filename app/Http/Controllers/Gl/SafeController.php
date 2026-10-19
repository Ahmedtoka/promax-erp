<?php

namespace App\Http\Controllers\Gl;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\Safe;
use App\Models\Setting;
use App\Models\User;
use App\Support\Csv;
use App\Support\DateRange;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * سجل الخزن والبنوك (Lane 4، L4-D1/L4-D2). الرصيد **من الدفتر** — كل
 * مستند نقدي بيترحّل عن طريق `Ledger`، فالرقم هنا = صافي حساب الخزنة.
 * كل خزنة ليها حساب في الشجرة (`Safe::ensureGlAccount`).
 */
class SafeController extends Controller
{
    public function index(Request $request)
    {
        $range = DateRange::fromRequest($request, 'month');
        $q = Safe::with(['branch', 'costCenter', 'custodian', 'glAccount'])
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', (int) $request->query('branch_id')))
            ->when(in_array($request->query('kind'), Safe::KINDS, true), fn ($q) => $q->where('kind', $request->query('kind')))
            ->orderBy('kind')->orderBy('code');

        // رصيد أول المدة = لحد اليوم اللي قبل `from` · الرصيد = لحد `to` (أو النهارده)
        $openingAt = $range->from?->copy()->subDay();
        $closingAt = ($range->to ?? today())->copy();
        $rows = $q->get()->map(function (Safe $s) use ($range, $openingAt, $closingAt) {
            $mv = $s->movementsBetween($range->from, $range->to);

            return [
                'safe' => $s,
                'opening' => $openingAt ? $s->balance($openingAt) : 0.0,
                'in' => $mv['in'],
                'out' => $mv['out'],
                'balance' => $s->balance($closingAt),
            ];
        });

        if ($request->boolean('export')) {
            return Csv::download('safes-'.now()->format('Y-m-d-Hi').'.csv',
                [__('cash.code'), __('cash.name'), __('cash.kind'), __('cash.branch'), __('cash.cost_center'), __('cash.custodian'),
                    __('cash.bank_name'), __('cash.account_no'), __('cash.opening'), __('cash.in'), __('cash.out'), __('cash.balance'), __('cash.active')],
                $rows->map(fn ($r) => [
                    $r['safe']->code, $r['safe']->displayName(), __('cash.kind_'.$r['safe']->kind),
                    $r['safe']->branch?->displayName() ?? '', $r['safe']->costCenter?->displayName() ?? '',
                    $r['safe']->custodian?->displayName() ?? '', (string) $r['safe']->bank_name, (string) $r['safe']->account_no,
                    Csv::money($r['opening']), Csv::money($r['in']), Csv::money($r['out']), Csv::money($r['balance']),
                    $r['safe']->active ? __('cash.yes') : __('cash.no'),
                ]),
                ['', __('cash.total'), '', '', '', '', '', '', Csv::money($rows->sum('opening')), Csv::money($rows->sum('in')),
                    Csv::money($rows->sum('out')), Csv::money($rows->sum('balance')), ''],
                Csv::meta(__('cash.safes'), $range->fromValue() ?: null, $range->toValue() ?: null));
        }

        $byKind = fn (string $k) => $rows->filter(fn ($r) => $r['safe']->kind === $k);

        return view('gl.safes', [
            'range' => $range,
            'rows' => $rows,
            'kpis' => [
                'cash' => ['balance' => $byKind('cash')->sum('balance'), 'in' => $byKind('cash')->sum('in'), 'out' => $byKind('cash')->sum('out'), 'n' => $byKind('cash')->count()],
                'bank' => ['balance' => $byKind('bank')->sum('balance'), 'in' => $byKind('bank')->sum('in'), 'out' => $byKind('bank')->sum('out'), 'n' => $byKind('bank')->count()],
            ],
            'ledgerOff' => Setting::read('gl_enabled') !== '1',
            'kinds' => Safe::KINDS,
            'branches' => Branch::orderBy('name')->get(['id', 'name', 'name_en']),
            'costCenters' => CostCenter::where('active', true)->orderBy('code')->get(['id', 'code', 'name', 'name_en']),
            'users' => User::where('active', true)->orderBy('name')->get(['id', 'name', 'name_en', 'code']),
            'accounts' => \App\Models\Gl\GlAccount::where('type', 'asset')->where('is_postable', true)->where('active', true)
                ->orderBy('code')->get(['id', 'code', 'name', 'name_en']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->rules($request, null);

        DB::transaction(function () use ($data) {
            if (empty($data['cost_center_id']) && ! empty($data['branch_id'])) {
                $data['cost_center_id'] = CostCenter::where('branch_id', $data['branch_id'])->where('kind', 'branch')->value('id');
            }
            $safe = Safe::create($this->payload($data) + ['code' => $data['code'], 'currency' => 'EGP']);
            $safe->ensureGlAccount();
        });

        return redirect()->route('gl.safes')->with('ok', __('cash.safe_saved'));
    }

    public function update(Request $request, Safe $safe)
    {
        $data = $this->rules($request, $safe);

        DB::transaction(function () use ($safe, $data) {
            // ⚠️ الكود مش بيتعدّل — هو جزء من كود حساب الخزنة في الشجرة
            // ومن غير حساب مختار بيفضل على حسابه الحالي — مايتفكّش ويتفتح غيره
            $payload = $this->payload($data);
            if ($payload['gl_account_id'] === null) {
                unset($payload['gl_account_id']);
            }
            $safe->update($payload + ['currency' => 'EGP']);
            $safe->ensureGlAccount();
        });

        return redirect()->route('gl.safes')->with('ok', __('cash.safe_saved'));
    }

    private function rules(Request $request, ?Safe $safe): array
    {
        return $request->validate([
            'code' => $safe ? ['nullable'] : ['required', 'alpha_dash', 'max:20', Rule::unique('safes', 'code')],
            'name' => ['required', 'string', 'max:120'],
            'name_en' => ['nullable', 'string', 'max:120'],
            'kind' => ['required', Rule::in(Safe::KINDS)],
            'branch_id' => ['nullable', Rule::exists('branches', 'id')],
            'cost_center_id' => ['nullable', Rule::exists('cost_centers', 'id')],
            'custodian_user_id' => ['nullable', Rule::exists('users', 'id')->where('active', 1)],
            'gl_account_id' => ['nullable', Rule::exists('gl_accounts', 'id')->where('is_postable', 1)->where('active', 1)->where('type', 'asset')],
            'bank_name' => ['required_if:kind,bank', 'nullable', 'string', 'max:120'],
            'account_no' => ['nullable', 'string', 'max:60'],
            'active' => ['nullable', 'boolean'],
        ]);
    }

    private function payload(array $data): array
    {
        $bank = $data['kind'] === 'bank';

        return [
            'name' => $data['name'],
            'name_en' => $data['name_en'] ?? null,
            'kind' => $data['kind'],
            'branch_id' => $data['branch_id'] ?? null,
            'cost_center_id' => $data['cost_center_id'] ?? null,
            'custodian_user_id' => $data['custodian_user_id'] ?? null,
            'gl_account_id' => $data['gl_account_id'] ?? null,
            // بيانات البنك للبنك بس — خزنة كاش مايتحفظلهاش رقم حساب قديم
            'bank_name' => $bank ? ($data['bank_name'] ?? null) : null,
            'account_no' => $bank ? ($data['account_no'] ?? null) : null,
            // الفورم بيبعت hidden=0 قبل الـcheckbox — غيابها (API) = نشطة
            'active' => (bool) ($data['active'] ?? true),
        ];
    }
}
