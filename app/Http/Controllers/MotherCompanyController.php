<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\MotherCompany;
use App\Support\Csv;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * الشركات الأم (٣١/١٠/٢٠٢٦) — Mother Company ← السلسلة ← العميل.
 *
 * ⚠️ الأرقام بـ`Client::visibleTo` — مدير القناة بيشوف مجموع عملائه بس،
 * زي شاشة السلاسل بالظبط.
 */
class MotherCompanyController extends Controller
{
    public function index(Request $request)
    {
        $companies = $this->companies($request)->withCount('groups')->orderBy('name')->get();

        $stats = Client::visibleTo(
            Client::query()->join('client_groups', 'client_groups.id', '=', 'clients.group_id'),
            $request->user()
        )
            ->whereNotNull('client_groups.mother_company_id')
            ->selectRaw('client_groups.mother_company_id AS mother_id,
                COUNT(clients.id) AS branches,
                SUM(clients.purchases) AS purchases,
                SUM(clients.collections) AS collections,
                SUM(clients.`returns`) AS `returns`,
                SUM(clients.balance) AS balance')
            ->groupBy('client_groups.mother_company_id')
            ->get()->keyBy('mother_id');

        return view('erp.mother_companies', [
            'companies' => $companies,
            'stats' => $stats,
            'unlinked' => ClientGroup::whereNull('mother_company_id')->where('active', true)->count(),
            'filters' => $request->only('q'),
        ]);
    }

    public function show(MotherCompany $mother, Request $request)
    {
        $groups = $mother->groups()->with('channel')->orderBy('name')->get();

        return view('erp.mother_company', [
            'm' => $mother,
            'groups' => $groups,
            'clients' => $this->clientsOf($groups, $request),
            // السلسلة التابعة لشركة تانية بتظهر باسم شركتها — الضم بينقلها
            'available' => ClientGroup::with('motherCompany')
                ->where('active', true)
                ->where(fn ($q) => $q->whereNull('mother_company_id')->orWhere('mother_company_id', '!=', $mother->id))
                ->orderBy('name')->get(),
            'channels' => Channel::orderBy('id')->get(),
        ]);
    }

    /** الشركة ← السلسلة ← العميل: صف لكل عميل زي الشيت، بنفس فلتر الشاشة أو لشركة واحدة */
    public function export(Request $request)
    {
        $companies = $this->companies($request)
            ->when($request->integer('mother'), fn ($q, $id) => $q->whereKey($id))
            ->with(['groups' => fn ($q) => $q->orderBy('name')])
            ->orderBy('name')->get();

        $clients = $this->clientsOf($companies->flatMap->groups, $request);

        $rows = [];
        $sum = ['purchases' => 0.0, 'collections' => 0.0, 'returns' => 0.0, 'balance' => 0.0];

        foreach ($companies as $m) {
            foreach ($m->groups as $g) {
                $members = $clients->get($g->id, collect());

                if ($members->isEmpty()) {
                    $rows[] = [$m->displayName(), $g->displayName(), '', '', '', '', '', ''];
                }

                foreach ($members as $c) {
                    $rows[] = [
                        $m->displayName(), $g->displayName(), $c->code, $c->displayName(),
                        Csv::money($c->purchases), Csv::money($c->collections), Csv::money($c->returns), Csv::money($c->balance),
                    ];

                    foreach ($sum as $k => $v) {
                        $sum[$k] = $v + (float) $c->{$k};
                    }
                }
            }
        }

        return Csv::download('mother-companies-'.now()->format('Y-m-d-Hi').'.csv', [
            __('client.mother_company'), __('client.chain'), __('common.code'), __('client.client'),
            __('client.purchases'), __('client.collected'), __('client.returns'), __('client.balance'),
        ], $rows, [
            __('common.total'), '', '', '',
            Csv::money($sum['purchases']), Csv::money($sum['collections']), Csv::money($sum['returns']), Csv::money($sum['balance']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['code'] = MotherCompany::nextCode($data['name_en'] ?: $data['name']);
        $data['active'] = true;

        $mother = MotherCompany::create($data);

        return redirect()->route('erp.mother_companies.show', $mother)->with('ok', __('client.mother_created'));
    }

    public function update(Request $request, MotherCompany $mother)
    {
        $mother->update($this->validated($request, $mother) + ['active' => $request->boolean('active')]);

        return back()->with('ok', __('client.mother_saved'));
    }

    public function destroy(MotherCompany $mother)
    {
        // زي السلسلة: اللي تحتها سلاسل بتتوقف مابتتمسحش
        if ($mother->groups()->exists()) {
            $mother->update(['active' => false]);

            return back()->with('ok', __('client.mother_suspended'));
        }

        $mother->delete();

        return redirect()->route('erp.mother_companies')->with('ok', __('client.mother_deleted'));
    }

    /** ضم سلاسل للشركة أو فكّها */
    public function attach(Request $request, MotherCompany $mother)
    {
        $data = $request->validate([
            'group_ids' => ['required', 'array'],
            'group_ids.*' => ['exists:client_groups,id'],
            'action' => ['required', 'in:attach,detach'],
        ]);

        $attach = $data['action'] === 'attach';

        // صف صف عشان سجل النشاط يشوف التغيير — `update` الجماعي مابيعديش على الموديل.
        // الفك مقصور على سلاسل الشركة دي، فطلب غلط مايفكّش سلسلة شركة تانية.
        $groups = ClientGroup::whereIn('id', $data['group_ids'])
            ->when(! $attach, fn ($q) => $q->where('mother_company_id', $mother->id))
            ->get();

        foreach ($groups as $g) {
            $g->update(['mother_company_id' => $attach ? $mother->id : null]);
        }

        return back()->with('ok', __($attach ? 'client.mother_chains_attached' : 'client.mother_chains_detached', ['count' => $groups->count()]));
    }

    private function companies(Request $request)
    {
        $q = MotherCompany::query();

        if ($s = $request->string('q')->trim()->value()) {
            $q->where(fn ($w) => $w
                ->where('name', 'like', "%$s%")
                ->orWhere('name_en', 'like', "%$s%")
                ->orWhere('code', 'like', "%$s%")
                ->orWhereHas('groups', fn ($g) => $g
                    ->where('name', 'like', "%$s%")
                    ->orWhere('name_en', 'like', "%$s%")));
        }

        return $q;
    }

    /** عملاء السلاسل اللي الفاعل شايفهم، مجمّعين بالسلسلة */
    private function clientsOf($groups, Request $request)
    {
        return Client::visibleTo(Client::whereIn('group_id', $groups->pluck('id')), $request->user())
            ->orderByDesc('purchases')
            ->get()
            ->groupBy('group_id');
    }

    private function validated(Request $request, ?MotherCompany $mother = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('mother_companies', 'name')->ignore($mother?->id)],
            'name_en' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string'],
        ]);
    }
}
