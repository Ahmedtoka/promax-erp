<?php

namespace App\Http\Controllers;

use App\Exceptions\Rejected;
use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\MotherCompany;
use App\Models\Product;
use App\Models\ReviewCheck;
use App\Models\ReviewStockMove;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Pricing;
use App\Services\Review\DocQueue;
use App\Services\Review\MonthFigures;
use App\Services\Review\StockReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * مراجعة إعادة التشغيل (٥/١١/٢٠٢٦ — طلب المالك)
 * ═══════════════════════════════════════════════════════════════
 *
 * مكان واحد الفريق بيراجع فيه السيستم على الورق، مرحلة ورا مرحلة:
 *
 *   ١. بيانات العميل — ٦ بنود، كل بند ✓ مطابق أو ✗ فيه مشكلة، والتعديل
 *      بيفتح في تاب جديدة وبيرجع للشاشة يعلّم.
 *   ٢. الحسابات — أسعار الفواتير وأوامر التوريد، وكل شهر: رقم السيستم
 *      قدام رقم الورق والفرق، ورصيد أول المدة (للسلسلة رقم واحد).
 *   ٣. المخزن والمناديب — تصفير، وإدخال الأرصدة من الورق بالباتش، وتسليم
 *      العهد.
 *
 * والداشبورد بيقول كل مرحلة وصلت لفين ومين عمل إيه النهارده.
 */
class ReviewController extends Controller
{
    // ═══════════════════════ الداشبورد ═══════════════════════

    public function dashboard(Request $request)
    {
        $user = $request->user();

        $clientIds = $this->clientsQuery($user)->pluck('id')->all();
        $data = $this->progress('client', $clientIds, ReviewCheck::DATA_ITEMS);

        [$groups, $solo] = $this->moneyEntities($user);
        $moneyItems = ReviewCheck::moneyItems();
        $mg = $this->progress('group', $groups->pluck('id')->all(), $moneyItems);
        $mc = $this->progress('client', $solo->pluck('id')->all(), $moneyItems);
        $money = [
            'total' => $mg['total'] + $mc['total'], 'done' => $mg['done'] + $mc['done'],
            'issue' => $mg['issue'] + $mc['issue'], 'started' => $mg['started'] + $mc['started'],
        ];

        $warehouses = Warehouse::where('active', true)->pluck('id')->all();
        $reps = $this->reps()->pluck('id')->all();
        $wh = $this->progress('warehouse', $warehouses, ReviewCheck::WAREHOUSE_ITEMS);
        $rp = $this->progress('rep', $reps, ReviewCheck::REP_ITEMS);

        // مين عمل إيه — النهارده وإجمالي
        $people = ReviewCheck::query()
            ->selectRaw('user_id, COUNT(*) AS total,
                SUM(CASE WHEN DATE(updated_at) = CURDATE() THEN 1 ELSE 0 END) AS today,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS issues, MAX(updated_at) AS last_at', [ReviewCheck::ISSUE])
            ->whereNotNull('user_id')->groupBy('user_id')->orderByDesc('today')->orderByDesc('total')
            ->get();
        $names = User::whereIn('id', $people->pluck('user_id'))->get()->keyBy('id');

        $recent = ReviewCheck::with('user:id,name,name_en')->latest('updated_at')->limit(40)->get();

        return view('erp.review.dashboard', [
            'data' => $data, 'money' => $money, 'wh' => $wh, 'rp' => $rp,
            'people' => $people, 'names' => $names,
            'recent' => $recent, 'labels' => $this->labels($recent),
            'months' => ReviewCheck::months(),
            // (١١/١١) المرحلة ٤ — للي معاه التاب بس
            ...$this->docsCard($user),
        ]);
    }

    // ═══════════════════════ المرحلة ١: بيانات العميل ═══════════════════════

    public function data(Request $request)
    {
        $user = $request->user();
        $q = $this->clientsQuery($user)->with(['group.motherCompany', 'contract.priceListRow', 'group.contract.priceListRow', 'priceListRow', 'zone']);

        if ($s = $request->string('q')->trim()->value()) {
            Client::search($q, $s);
        }
        if ($g = $request->integer('group')) {
            $q->where('group_id', $g);
        }
        if ($m = $request->integer('mother')) {
            $q->whereHas('group', fn ($w) => $w->where('mother_company_id', $m));
        }

        $show = $request->string('show')->value() ?: 'pending';
        $ids = (clone $q)->pluck('id')->all();
        $all = ReviewCheck::mapFor('client', $ids);
        $state = fn (int $id) => $this->rowState($all[$id] ?? [], ReviewCheck::DATA_ITEMS);

        $counts = ['pending' => 0, 'issue' => 0, 'done' => 0, 'all' => count($ids)];
        foreach ($ids as $id) {
            $st = $state($id);
            $counts[$st === 'done' ? 'done' : ($st === 'issue' ? 'issue' : 'pending')]++;
        }

        $keep = array_values(array_filter($ids, fn ($id) => match ($show) {
            'done' => $state($id) === 'done',
            'issue' => $state($id) === 'issue',
            'all' => true,
            default => ! in_array($state($id), ['done', 'issue'], true),
        }));

        $per = $this->perPage($request, 25);
        $clients = $q->whereIn('id', $keep)->orderBy('group_id')->orderBy('name')->paginate($per)->withQueryString();

        return view('erp.review.data', [
            'clients' => $clients,
            'checks' => $all,
            'lists' => $clients->getCollection()->mapWithKeys(fn ($c) => [$c->id => Pricing::listRowFor($c)?->displayName()]),
            'missing' => $clients->getCollection()->mapWithKeys(fn ($c) => [$c->id => ReviewCheck::missingItems($c)]),
            'counts' => $counts,
            'show' => $show,
            'per' => $per,
            'filters' => $request->only(['q', 'group', 'mother']),
            'groups' => ClientGroup::where('active', true)->orderBy('name')->get(['id', 'name', 'name_en']),
            'mothers' => MotherCompany::orderBy('name')->get(['id', 'name', 'name_en']),
        ]);
    }

    // ═══════════════════════ المرحلة ٢: الحسابات ═══════════════════════

    public function money(Request $request)
    {
        $user = $request->user();
        [$groups, $solo] = $this->moneyEntities($user, $request->string('q')->trim()->value());

        $branches = Client::visibleTo(Client::whereIn('group_id', $groups->pluck('id')), $user)
            ->get(['id', 'group_id'])->groupBy('group_id');
        $allIds = $branches->flatten()->pluck('id')->merge($solo->pluck('id'))->all();
        $figures = MonthFigures::forClients($allIds);

        $pendingPos = DB::table('purchase_orders')->whereIn('client_id', $allIds)
            ->whereNotIn('status', ['delivered', 'cancelled'])
            ->groupBy('client_id')->selectRaw('client_id, COUNT(*) AS n')->pluck('n', 'client_id');

        // (١٢/١١) الربط بالمراجعة مستند مستند: رصيد كل سطر دلوقتي، وكام من
        // فلوس مستنداته اتراجعت ✓، وكل شهر مستنداته خلصت ولا لأ
        $docs = DocQueue::moneyByClient($allIds);
        $balances = Client::whereIn('id', $allIds)->pluck('balance', 'id');

        $rows = [];

        foreach ($groups as $g) {
            $ids = ($branches[$g->id] ?? collect())->pluck('id');
            $rows[] = [
                'type' => 'group', 'model' => $g, 'branches' => $ids->count(),
                'figures' => MonthFigures::sum($figures, $ids),
                'pending_pos' => (int) $ids->sum(fn ($id) => $pendingPos[$id] ?? 0),
                'balance' => round((float) $ids->sum(fn ($id) => (float) ($balances[$id] ?? 0)), 2),
                'docs' => DocQueue::moneySum($docs, $ids),
            ];
        }
        foreach ($solo as $c) {
            $rows[] = [
                'type' => 'client', 'model' => $c, 'branches' => 1,
                'figures' => MonthFigures::sum($figures, [$c->id]),
                'pending_pos' => (int) ($pendingPos[$c->id] ?? 0),
                'balance' => round((float) ($balances[$c->id] ?? 0), 2),
                'docs' => DocQueue::moneySum($docs, [$c->id]),
            ];
        }

        $checks = [
            'group' => ReviewCheck::mapFor('group', $groups->pluck('id')),
            'client' => ReviewCheck::mapFor('client', $solo->pluck('id')),
        ];

        $show = $request->string('show')->value() ?: 'pending';
        $items = ReviewCheck::moneyItems();
        $counts = ['pending' => 0, 'issue' => 0, 'done' => 0, 'all' => count($rows)];

        foreach ($rows as &$r) {
            $r['state'] = $this->rowState($checks[$r['type']][$r['model']->id] ?? [], $items);
            $counts[$r['state'] === 'done' ? 'done' : ($r['state'] === 'issue' ? 'issue' : 'pending')]++;
        }
        unset($r);

        $rows = array_values(array_filter($rows, fn ($r) => match ($show) {
            'done' => $r['state'] === 'done',
            'issue' => $r['state'] === 'issue',
            'all' => true,
            default => ! in_array($r['state'], ['done', 'issue'], true),
        }));

        $totals = [
            'balance' => round(array_sum(array_column($rows, 'balance')), 2),
            'grand' => round(array_sum(array_map(fn ($r) => $r['docs']['grand'], $rows)), 2),
            'ok_grand' => round(array_sum(array_map(fn ($r) => $r['docs']['ok_grand'], $rows)), 2),
            'docs' => array_sum(array_map(fn ($r) => $r['docs']['docs'], $rows)),
            'ok' => array_sum(array_map(fn ($r) => $r['docs']['ok'], $rows)),
            // (١٢/١١) المرتجعات: مبيعات − مرتجعات = صافي، والمتراجع منه
            'sales' => round(array_sum(array_map(fn ($r) => $r['docs']['sales'], $rows)), 2),
            'ret' => round(array_sum(array_map(fn ($r) => $r['docs']['ret'], $rows)), 2),
            'net' => round(array_sum(array_map(fn ($r) => $r['docs']['net'], $rows)), 2),
            'ok_net' => round(array_sum(array_map(fn ($r) => $r['docs']['ok_net'], $rows)), 2),
        ];

        $per = $this->perPage($request, 25);
        $page = $this->paginateArray($request, $rows, $per);

        return view('erp.review.money', [
            'rows' => $page->items(), 'page' => $page, 'per' => $per, 'totals' => $totals,
            'checks' => $checks, 'months' => ReviewCheck::months(),
            'counts' => $counts, 'show' => $show, 'filters' => $request->only('q'),
            'canDocs' => \App\Support\Access::allows($user, 'erp.review.docs'),
        ]);
    }

    /** رصيد أول المدة للسلسلة كرقم واحد — ومعاه علامة البند */
    public function chainOpening(Request $request, ClientGroup $group)
    {
        $this->assertEntity($request->user(), 'group', $group->id);

        $data = $request->validate([
            'amount' => ['nullable', 'numeric', 'between:-999999999,999999999'],
            'as_of' => ['nullable', 'date'],
        ]);

        $amount = $data['amount'] ?? null;
        $group->update([
            'opening_balance' => $amount,
            'opening_as_of' => $amount === null ? null : ($data['as_of'] ?? ReviewCheck::FIRST_MONTH.'-01'),
        ]);

        if ($amount === null) {
            ReviewCheck::where(['entity_type' => 'group', 'entity_id' => $group->id, 'item' => ReviewCheck::OPENING_ITEM])->delete();
        } else {
            ReviewCheck::updateOrCreate(
                ['entity_type' => 'group', 'entity_id' => $group->id, 'item' => ReviewCheck::OPENING_ITEM],
                ['phase' => 'money', 'status' => ReviewCheck::OK, 'system_balance' => $amount, 'user_id' => $request->user()->id],
            );
        }

        return back()->with('ok', __('review.opening_saved', ['chain' => $group->displayName()]));
    }

    // ═══════════════════════ المرحلة ٣: المخزن والمناديب ═══════════════════════

    public function stock(Request $request)
    {
        $warehouses = Warehouse::where('active', true)->orderBy('id')->get();

        $stock = DB::table('batches')->whereIn('warehouse_id', $warehouses->pluck('id'))
            ->groupBy('warehouse_id')
            ->selectRaw('warehouse_id, SUM(qty_remaining) AS q, SUM(CASE WHEN qty_remaining > 0 THEN 1 ELSE 0 END) AS batches')
            ->get()->keyBy('warehouse_id');

        $entered = ReviewStockMove::where('kind', 'opening')->groupBy('warehouse_id')
            ->selectRaw('warehouse_id, SUM(qty) AS q, COUNT(*) AS n')->get()->keyBy('warehouse_id');

        $reps = $this->reps()->get();

        return view('erp.review.stock', [
            'warehouses' => $warehouses, 'stock' => $stock, 'entered' => $entered,
            'reps' => $reps, 'repQty' => StockReset::repRemaining($reps->pluck('id')->all()),
            'checks' => [
                'warehouse' => ReviewCheck::mapFor('warehouse', $warehouses->pluck('id')),
                'rep' => ReviewCheck::mapFor('rep', $reps->pluck('id')),
            ],
            'moves' => ReviewStockMove::with(['product', 'warehouse', 'user'])->latest('id')->limit(60)->get(),
            'products' => Product::where('kind', Product::KIND_FINISHED)->where('active', true)->orderBy('name')->get(['id', 'code', 'name', 'name_en', 'cost']),
        ]);
    }

    public function zeroWarehouse(Request $request, Warehouse $warehouse)
    {
        $request->validate(['confirm' => ['required', 'in:'.$warehouse->code]]);

        $n = StockReset::zeroWarehouse($warehouse, $request->user());

        ReviewCheck::updateOrCreate(
            ['entity_type' => 'warehouse', 'entity_id' => $warehouse->id, 'item' => 'zeroed'],
            ['phase' => 'stock', 'status' => ReviewCheck::OK, 'user_id' => $request->user()->id],
        );

        return back()->with('ok', __('review.zeroed', ['wh' => $warehouse->displayName(), 'n' => $n]));
    }

    public function addBatch(Request $request, Warehouse $warehouse)
    {
        $data = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'batch_no' => ['required', 'string', 'max:60'],
            'expires_on' => ['required', 'date'],
            'qty' => ['required', 'integer', 'min:1'],
            'cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            StockReset::addBatch($warehouse, Product::findOrFail($data['product_id']), trim($data['batch_no']),
                \Illuminate\Support\Carbon::parse($data['expires_on'])->toDateString(), (int) $data['qty'],
                isset($data['cost']) ? (float) $data['cost'] : null, $request->user());
        } catch (Rejected $e) {
            return back()->withErrors(['batch_no' => $e->getMessage()])->withInput();
        }

        return back()->with('ok', __('review.batch_added'))->withInput($request->only('product_id'));
    }

    // ═══════════════════════ العلامة ═══════════════════════

    /** ✓ / ✗ / مسح على بند — JSON للشاشة وback للفورم العادي */
    public function mark(Request $request)
    {
        $data = $request->validate([
            'entity_type' => ['required', 'in:'.implode(',', ReviewCheck::ENTITY_TYPES)],
            'entity_id' => ['required', 'integer'],
            'items' => ['required', 'array', 'min:1'],
            'items.*' => ['string', 'max:24'],
            'status' => ['required', 'in:ok,issue,clear'],
            'note' => ['nullable', 'string', 'max:500'],
            'paper_net' => ['nullable', 'numeric'],
            'paper_balance' => ['nullable', 'numeric'],
            'system_net' => ['nullable', 'numeric'],
            'system_balance' => ['nullable', 'numeric'],
        ]);

        $this->assertEntity($request->user(), $data['entity_type'], (int) $data['entity_id']);

        // البند الناقص مايتعلّمش ✓ — لازم يتكمّل الأول (٦/١١)
        $missing = $data['entity_type'] === 'client' && $data['status'] === 'ok'
            ? ReviewCheck::missingItems(Client::findOrFail($data['entity_id']))
            : [];

        // (١٢/١١) «طابق الشهر» ممنوع لحد ما مستندات الشهر تتراجع كلها — لو المفتاح شغال
        if ($data['status'] === 'ok' && config('review.month_needs_all_docs')
            && in_array($data['entity_type'], ['client', 'group'], true)) {
            $ids = $data['entity_type'] === 'group'
                ? $this->clientsQuery($request->user())->where('group_id', $data['entity_id'])->pluck('id')->all()
                : [(int) $data['entity_id']];
            $docs = DocQueue::moneySum(DocQueue::moneyByClient($ids), $ids);
            foreach ($data['items'] as $item) {
                $m = str_starts_with($item, 'm:') ? ($docs['months'][substr($item, 2)] ?? null) : null;
                if ($m !== null && $m['ok'] < $m['docs']) {
                    $msg = __('review.month_docs_block', ['ok' => $m['ok'], 'n' => $m['docs']]);

                    return $request->expectsJson() ? response()->json(['ok' => false, 'message' => $msg], 422) : back()->withErrors(['items' => $msg]);
                }
            }
        }

        foreach ($data['items'] as $item) {
            $phase = ReviewCheck::phaseOf($data['entity_type'], $item);
            abort_if($phase === null, 422);

            if (in_array($item, $missing, true)) {
                continue;
            }

            $key = ['entity_type' => $data['entity_type'], 'entity_id' => (int) $data['entity_id'], 'item' => $item];

            if ($data['status'] === 'clear') {
                ReviewCheck::where($key)->first()?->delete();

                continue;
            }

            ReviewCheck::updateOrCreate($key, array_filter([
                'phase' => $phase,
                'status' => $data['status'],
                'note' => $data['note'] ?? null,
                'paper_net' => $data['paper_net'] ?? null,
                'paper_balance' => $data['paper_balance'] ?? null,
                'system_net' => $data['system_net'] ?? null,
                'system_balance' => $data['system_balance'] ?? null,
                'user_id' => $request->user()->id,
            ], fn ($v) => $v !== null) + ['note' => $data['note'] ?? null]);
        }

        if ($request->expectsJson()) {
            $checks = ReviewCheck::mapFor($data['entity_type'], [(int) $data['entity_id']])[(int) $data['entity_id']] ?? [];

            return response()->json([
                'ok' => true,
                'missing' => array_values(array_intersect($data['items'], $missing)),
                'checks' => collect($checks)->map(fn ($c) => [
                    'status' => $c->status, 'note' => $c->note, 'by' => $c->user?->displayName(),
                    'at' => $c->updated_at?->format('Y-m-d H:i'),
                ]),
            ]);
        }

        return back()->with('ok', __('review.saved'));
    }

    // ═══════════════════════ مساعدات ═══════════════════════

    public const PER_PAGE = [25, 50, 100, 200];

    /**
     * (١٢/١١) كارت المرحلة ٤ في الداشبورد: الإنجاز + السرعة والمتوقع + قايمة
     * المستندات المفتوح عليها ✗ (أقدم الأول) — للي معاه التاب بس.
     */
    private function docsCard(?User $user): array
    {
        if (! \App\Support\Access::allows($user, 'erp.review.docs')) {
            return ['docs' => null, 'docPace' => null, 'docIssues' => collect(), 'docProof' => null];
        }
        $all = DocQueue::all($user);

        return [
            'docs' => DocQueue::stats($all),
            'docPace' => \App\Services\Review\ReviewerStats::pace($all->where('state', '!=', 'ok')->count()),
            'docIssues' => $all->where('state', 'issue')->sortBy('at')->take(15)->values(),
            'docProof' => DocQueue::proofStats($all),
        ];
    }

    /** مقاس الصفحة من `?per=` — من القايمة بس */
    private function perPage(Request $request, int $default): int
    {
        $n = $request->integer('per');

        return in_array($n, self::PER_PAGE, true) ? $n : $default;
    }

    /** ترقيم سيرفر لمصفوفة جاهزة (صفوف الحسابات بتتبني في PHP) */
    private function paginateArray(Request $request, array $rows, int $per): \Illuminate\Pagination\LengthAwarePaginator
    {
        $last = max(1, (int) ceil(count($rows) / $per));
        $p = min($last, max(1, $request->integer('page', 1)));

        return new \Illuminate\Pagination\LengthAwarePaginator(array_slice($rows, ($p - 1) * $per, $per), count($rows), $per, $p,
            ['path' => $request->url(), 'query' => $request->except('page')]);
    }

    private function clientsQuery(?User $user)
    {
        return Client::visibleTo(Client::query(), $user)
            ->where('category', '!=', 'internal')
            ->where('status', '!=', 'rejected');
    }

    /** المرحلة ٢: السلاسل اللي فيها فروع + العملاء من غير سلسلة */
    private function moneyEntities(?User $user, ?string $search = null): array
    {
        $groupIds = $this->clientsQuery($user)->whereNotNull('group_id')->distinct()->pluck('group_id');

        $groups = ClientGroup::with('motherCompany')->whereIn('id', $groupIds)
            ->when($search, fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%$s%")->orWhere('name_en', 'like', "%$s%")))
            ->orderBy('name')->get();

        $solo = $this->clientsQuery($user)->whereNull('group_id')
            ->when($search, fn ($q, $s) => Client::search($q, $s))
            ->orderBy('name')->get();

        return [$groups, $solo];
    }

    private function reps()
    {
        return User::where('role', 'sales_agent')->where('active', true)->orderBy('name');
    }

    /** @return array{total: int, done: int, issue: int, started: int} */
    private function progress(string $type, array $ids, array $items): array
    {
        $map = ReviewCheck::mapFor($type, $ids);
        $out = ['total' => count($ids), 'done' => 0, 'issue' => 0, 'started' => 0];

        foreach ($ids as $id) {
            $st = $this->rowState($map[$id] ?? [], $items);
            if ($st === 'done') {
                $out['done']++;
            } elseif ($st === 'issue') {
                $out['issue']++;
            } elseif ($st === 'started') {
                $out['started']++;
            }
        }

        return $out;
    }

    /** done = كل البنود ✓ · issue = فيه ✗ · started = بدأ · empty = محدش لمسه */
    private function rowState(array $checks, array $items): string
    {
        if (collect($checks)->contains(fn ($c) => $c->status === ReviewCheck::ISSUE)) {
            return 'issue';
        }

        $ok = collect($items)->filter(fn ($i) => ($checks[$i] ?? null)?->status === ReviewCheck::OK)->count();

        return $ok === count($items) ? 'done' : ($ok > 0 ? 'started' : 'empty');
    }

    private function assertEntity(?User $user, string $type, int $id): void
    {
        $ok = match ($type) {
            'client' => Client::visibleTo(Client::whereKey($id), $user)->exists(),
            // السلسلة لازم يكون فيها فرع من عملاء اليوزر — مدير القناة مايعلّمش على سلسلة غيره
            'group' => $this->clientsQuery($user)->where('group_id', $id)->exists(),
            'warehouse' => Warehouse::whereKey($id)->exists(),
            'rep' => User::whereKey($id)->where('role', 'sales_agent')->exists(),
        };

        abort_unless($ok, 404);
    }

    /** اسم كل كيان في آخر العلامات — للداشبورد */
    private function labels($checks): array
    {
        $out = [];
        $byType = $checks->groupBy('entity_type');

        $models = [
            'client' => Client::whereIn('id', ($byType['client'] ?? collect())->pluck('entity_id'))->get()->keyBy('id'),
            'group' => ClientGroup::whereIn('id', ($byType['group'] ?? collect())->pluck('entity_id'))->get()->keyBy('id'),
            'warehouse' => Warehouse::whereIn('id', ($byType['warehouse'] ?? collect())->pluck('entity_id'))->get()->keyBy('id'),
            'rep' => User::whereIn('id', ($byType['rep'] ?? collect())->pluck('entity_id'))->get()->keyBy('id'),
        ];
        // (١١/١١) علامات المستندات — الاسم = رقم المستند
        $numbers = [
            'invoice' => \App\Models\Invoice::whereIn('id', ($byType['invoice'] ?? collect())->pluck('entity_id'))->pluck('number', 'id'),
            'po' => \App\Models\PurchaseOrder::whereIn('id', ($byType['po'] ?? collect())->pluck('entity_id'))->pluck('number', 'id'),
        ];

        foreach ($checks as $c) {
            $out[$c->id] = isset($numbers[$c->entity_type])
                ? ($numbers[$c->entity_type][$c->entity_id] ?? '#'.$c->entity_id)
                : ($models[$c->entity_type][$c->entity_id] ?? null)?->displayName() ?? '#'.$c->entity_id;
        }

        return $out;
    }
}
