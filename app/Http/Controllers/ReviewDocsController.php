<?php

namespace App\Http\Controllers;

use App\Exceptions\Rejected;
use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\DocReviewEvent;
use App\Models\Invoice;
use App\Models\MotherCompany;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\ReviewCheck;
use App\Models\User;
use App\Services\Pricing;
use App\Services\Review\DocQueue;
use App\Services\Review\DocReview;
use App\Services\Review\ReviewerStats;
use App\Support\Access;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * ═══════════════════════════════════════════════════════════════
 * المراجعة مستند مستند (١١/١١/٢٠٢٦ — طلب أحمد)
 * ═══════════════════════════════════════════════════════════════
 *
 * المحاسب ماسك الورق: يلاقي المستند بأي حاجة (رقم/سيريال/فرع/تليفون/
 * مبلغ)، يقارن الورق بالسيستم، يصلّح في نفس المكان (سيريال، بنود،
 * مندوب، تاريخ) ويعلّم ✓ — والإنجاز بيتحرك، وكل حركة متسجلة.
 * المنطق كله في `App\Services\Review\DocReview`.
 */
class ReviewDocsController extends Controller
{
    public const PER_PAGE = [25, 50, 100, 200];

    public function index(Request $request)
    {
        $user = $request->user();
        $f = $this->filters($request);

        $all = DocQueue::all($user);
        $scoped = DocQueue::filter($all, $f);
        $shown = DocQueue::byStatus($scoped, $f['status']);

        // ⚠️ (١٢/١١) نسبة كل سلسلة من **كل** مستنداتها (بفلاتر الشكل)، مش من
        // اللي ظاهر بفلتر الحالة — على «لسه» كانت كل سلسلة بتقول ٠ من ن
        // للأبد، حتى بعد ما تعلّم نصها ✓.
        $groupStats = $scoped->groupBy(fn ($r) => $r['group_id'] ? 'g'.$r['group_id'] : 'c'.$r['client_id'])
            ->map(fn ($g) => DocQueue::stats($g));

        // الترقيم على الصفوف بترتيب العرض (أم ← سلسلة ← فرع) — والسلسلة اللي
        // بتتقسم على صفحتين عنوانها بيتكرر فوق الجزء التاني
        $flat = collect(DocQueue::grouped($shown))->flatMap(fn ($g) => $g['rows']->map(fn ($r) => $r + ['_g' => $g['key'], '_label' => $g['label'], '_solo' => $g['solo'], '_mother' => $g['mother']]));
        $per = in_array($request->integer('per'), self::PER_PAGE, true) ? $request->integer('per') : 50;
        $page = new \Illuminate\Pagination\LengthAwarePaginator($flat->forPage(max(1, $request->integer('page', 1)), $per)->values(), $flat->count(), $per,
            max(1, $request->integer('page', 1)), ['path' => $request->url(), 'query' => $request->except(['page', 'doc'])]);
        // الصفحة المطلوبة بعد آخر صفحة (فلتر ضيّق النتيجة) ← آخر صفحة
        if ($page->isEmpty() && $page->currentPage() > 1 && $flat->isNotEmpty()) {
            return redirect($page->url($page->lastPage()));
        }

        $groups = [];
        foreach ($page->getCollection()->groupBy('_g') as $k => $rows) {
            $first = $rows->first();
            $groups[] = ['key' => $k, 'label' => $first['_label'], 'solo' => $first['_solo'], 'mother' => $first['_mother'],
                'stats' => $groupStats[$k] ?? DocQueue::stats($rows), 'rows' => $rows];
        }

        $groupIds = $all->pluck('group_id')->filter()->unique();

        return view('erp.review.docs', [
            'overall' => DocQueue::overall($request->user()),
            'scoped' => DocQueue::stats($scoped),
            'groups' => $groups,
            'page' => $page,
            'per' => $per,
            'count' => $shown->count(),
            'counts' => [
                'pending' => $scoped->where('state', 'pending')->count(),
                'ok' => $scoped->where('state', 'ok')->count(),
                'issue' => $scoped->where('state', 'issue')->count(),
                'changed' => $scoped->where('state', 'changed')->count(),
                'all' => $scoped->count(),
            ],
            'context' => $this->context($user, $f, $scoped),
            'pace' => ReviewerStats::pace($all->where('state', '!=', 'ok')->count()),
            // ⚠️ (١٢/١١) عدد الشيب = عدد اللي هيظهر لو دوست عليه بالظبط: كل الفلاتر الحالية + تاب الحالة
            'flagCounts' => collect([...DocQueue::FLAGS, 'any'])->mapWithKeys(fn ($k) => [$k => DocQueue::byStatus(DocQueue::filter($all, ['flag' => $k, 'sample' => ''] + $f), $f['status'])->count()]),
            'sampleBy' => $f['by'] !== '' ? User::find($f['by'])?->displayName() : null,
            'f' => $f,
            'open' => $request->string('doc')->value(),
            'mothers' => MotherCompany::whereIn('id', $all->pluck('mother_id')->filter()->unique())->orderBy('name')->get(['id', 'name', 'name_en']),
            'chains' => ClientGroup::whereIn('id', $groupIds)->orderBy('name')->get(['id', 'name', 'name_en']),
            'clientOpts' => $all->unique('client_id')->sortBy('client')->mapWithKeys(fn ($r) => [$r['client_id'] => $r['client_code'].' · '.$r['client']]),
            'monthOpts' => $all->map(fn ($r) => substr($r['date'], 0, 7))->filter()->unique()->sortDesc()->values(),
            'repOpts' => $all->whereNotNull('rep_id')->unique('rep_id')->sortBy('rep')->mapWithKeys(fn ($r) => [$r['rep_id'] => $r['rep']]),
            'canEdit' => Access::allows($user, 'erp.review.docs.lines'),
            'canReopen' => Access::allows($user, 'erp.review.docs.reopen'),
            'canPeople' => Access::allows($user, 'erp.review.docs.people'),
        ]);
    }

    /**
     * (١٢/١١) شريط السلسلة/العميل فوق الطابور لما الفلتر على واحد بس: رصيده
     * دلوقتي، وفلوس مستنداته المتراجعة من الإجمالي، ولينك يرجّع للحسابات.
     *
     * @return array<string, mixed>|null
     */
    private function context(User $user, array $f, \Illuminate\Support\Collection $scoped): ?array
    {
        if ($f['client'] !== '') {
            $c = Client::visibleTo(Client::whereKey($f['client']), $user)->first(['id', 'code', 'name', 'name_en', 'group_id', 'balance']);
            if ($c === null) {
                return null;
            }
            $ids = [$c->id];
            $label = trim($c->code.' · '.$c->displayName());
            $kind = 'client';
            $money = ['q' => $c->code];
        } elseif ($f['group'] !== '' && $f['group'] !== 'solo') {
            $g = ClientGroup::find($f['group']);
            if ($g === null) {
                return null;
            }
            $ids = Client::visibleTo(Client::where('group_id', $g->id), $user)->pluck('id')->all();
            $label = $g->displayName();
            $kind = 'group';
            $money = ['q' => $g->name];
        } else {
            return null;
        }

        $m = DocQueue::moneySum(DocQueue::moneyByClient($ids), $ids);

        return [
            'kind' => $kind, 'label' => $label,
            'balance' => round((float) Client::whereIn('id', $ids)->sum('balance'), 2),
            'docs' => $m['docs'], 'ok' => $m['ok'], 'issue' => $m['issue'],
            'grand' => $m['grand'], 'ok_grand' => $m['ok_grand'],
            'pct' => DocQueue::pct($m['ok_grand'], $m['grand']),
            'money_url' => Access::allows($user, 'erp.review.money') ? route('erp.review.money', $money + ['show' => 'all']) : null,
        ];
    }

    /** لوحة المستند الواحد — HTML بيتحط جنب الطابور */
    public function pane(Request $request, string $type, int $id)
    {
        $doc = $this->doc($request, $type, $id);

        return view('erp.review._doc_pane', $this->paneData($request->user(), $doc));
    }

    public function mark(Request $request, string $type, int $id): JsonResponse
    {
        $doc = $this->doc($request, $type, $id);
        $data = $request->validate([
            'status' => ['required', 'in:ok,issue'],
            'note' => ['nullable', 'string', 'max:500'],
            'open_ms' => ['nullable', 'integer', 'min:0'],
        ]);

        return $this->run($request, $doc, fn () => DocReview::mark($request->user(), $doc, $data['status'], $data['note'] ?? null,
            isset($data['open_ms']) ? (int) $data['open_ms'] : null), $data['status'] === 'ok' ? __('review.doc_marked_ok') : __('review.doc_marked_issue'));
    }

    public function reopen(Request $request, string $type, int $id): JsonResponse
    {
        $doc = $this->doc($request, $type, $id);
        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;

        return $this->run($request, $doc, fn () => DocReview::reopen($request->user(), $doc, $note), __('review.doc_reopened'));
    }

    public function serial(Request $request, string $type, int $id): JsonResponse
    {
        $doc = $this->doc($request, $type, $id);
        $serial = $request->validate(['paper_ref' => ['nullable', 'string', 'max:30']])['paper_ref'] ?? null;
        $dups = [];

        $res = $this->run($request, $doc, function () use ($request, $doc, $serial, &$dups) {
            $dups = DocReview::setSerial($request->user(), $doc, $serial);
        }, __('review.serial_saved'));

        if ($dups !== []) {
            $data = $res->getData(true);
            $data['warn'] = __('review.serial_dup', ['docs' => implode('، ', array_column($dups, 'number'))]);
            // المستندات التانية اللي بقت مكررة معاه — الشاشة بتعلّمها ⚠ من غير ريلود
            $data['dup_keys'] = array_map(fn ($d) => ($d['type'] === 'invoice' ? 'inv-' : 'po-').$d['id'], $dups);
            $res->setData($data);
        }

        return $res;
    }

    public function rep(Request $request, string $type, int $id): JsonResponse
    {
        $doc = $this->doc($request, $type, $id);
        $rep = (int) $request->validate(['rep_id' => ['required', 'integer']])['rep_id'];

        return $this->run($request, $doc, fn () => DocReview::setRep($request->user(), $doc, $rep), __('review.rep_saved'));
    }

    public function date(Request $request, string $type, int $id): JsonResponse
    {
        $doc = $this->doc($request, $type, $id);
        $date = $request->validate(['date' => ['required', 'date']])['date'];

        return $this->run($request, $doc, fn () => DocReview::setDate($request->user(), $doc, $date), __('review.date_saved'));
    }

    public function preview(Request $request, string $type, int $id): JsonResponse
    {
        $doc = $this->doc($request, $type, $id);
        $qty = $this->qty($request);

        $p = DocReview::previewLines($doc, $qty);

        return response()->json($p + ['before' => ['net' => (float) $doc->total, 'tax' => (float) $doc->tax_total, 'grand' => (float) $doc->grand_total]]);
    }

    public function lines(Request $request, string $type, int $id): JsonResponse
    {
        $doc = $this->doc($request, $type, $id);
        $qty = $this->qty($request);
        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;
        $old = (float) $doc->grand_total;

        return $this->run($request, $doc, fn () => DocReview::saveLines($request->user(), $doc, $qty, $note),
            fn () => __('review.lines_saved', ['old' => number_format($old, 2), 'new' => number_format((float) $doc->fresh()->grand_total, 2)]));
    }

    // ═══════════════════════ المراجِعين ═══════════════════════

    public function people(Request $request)
    {
        $from = $request->date('from') ?? today()->subDays(13);
        $to = $request->date('to') ?? today();
        $uid = $request->integer('user') ?: null;

        $days = ReviewerStats::days($from, $to, $uid);

        $timeline = null;
        if ($uid !== null && ($day = $request->string('day')->value())) {
            $d = Carbon::parse($day);
            $timeline = DocReviewEvent::where('user_id', $uid)->whereBetween('created_at', [$d->copy()->startOfDay(), $d->copy()->endOfDay()])
                ->orderBy('id')->get();
            $nums = [
                'invoice' => Invoice::whereIn('id', $timeline->where('doc_type', 'invoice')->pluck('doc_id'))->pluck('number', 'id'),
                'po' => PurchaseOrder::whereIn('id', $timeline->where('doc_type', 'po')->pluck('doc_id'))->pluck('number', 'id'),
            ];
            $timeline = $timeline->map(fn ($e) => $e->setAttribute('number', $nums[$e->doc_type][$e->doc_id] ?? '#'.$e->doc_id));
        }

        return view('erp.review.docs_people', [
            'days' => $days,
            'timeline' => $timeline,
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'user' => $uid, 'day' => $request->string('day')->value(),
            'users' => User::whereIn('id', DocReviewEvent::query()->distinct()->pluck('user_id'))->orderBy('name')->get(['id', 'name', 'name_en']),
            'fastSeconds' => DocReviewEvent::FAST_SECONDS, 'fastStreak' => DocReviewEvent::FAST_STREAK,
        ]);
    }

    // ═══════════════════════ التصدير ═══════════════════════

    /** الطابور بالفلاتر الحالية (كل الصفحات) — مين راجع إيه وإمتى والتنبيهات */
    public function export(Request $request)
    {
        $f = $this->filters($request);
        $rows = DocQueue::byStatus(DocQueue::filter(DocQueue::all($request->user()), $f), $f['status']);
        $head = [__('review.f_type'), '#', __('review.serial'), __('review.doc_date'), __('review.f_client'), __('review.doc_client'),
            __('review.f_chain'), __('review.f_mother'), __('review.doc_rep'), __('review.tot_net'), __('review.tot_vat'), __('review.tot_incl'),
            __('review.f_status'), __('review.x_flags'), __('review.x_by'), __('review.x_at'), __('review.log_note')];

        return $this->csv('doc-review-'.now()->format('Ymd-Hi').'.csv', $head, $rows->map(fn ($r) => [
            __('review.type_'.$r['type']), $r['number'], $r['paper_ref'], $r['date'], $r['client_code'], $r['client'],
            $r['group'], $r['mother'], $r['rep'], $r['net'], $r['tax'], $r['grand'],
            __('review.st_'.$r['state']), implode(' / ', array_map(fn ($x) => __('review.flag_'.$x), $r['flags'])), $r['by'], $r['at'], $r['note'],
        ]));
    }

    /** سجل المراجعة الكامل للفترة — كل ✓ وكل تعديل بقبل ← بعد */
    public function peopleExport(Request $request)
    {
        $from = ($request->date('from') ?? today()->subDays(13))->startOfDay();
        $to = ($request->date('to') ?? today())->endOfDay();
        $events = DocReviewEvent::with('user:id,name,name_en')->whereBetween('created_at', [$from, $to])
            ->when($request->integer('user'), fn ($q, $u) => $q->where('user_id', $u))->orderBy('id')->get();
        $nums = [
            'invoice' => Invoice::whereIn('id', $events->where('doc_type', 'invoice')->pluck('doc_id'))->pluck('number', 'id'),
            'po' => PurchaseOrder::whereIn('id', $events->where('doc_type', 'po')->pluck('doc_id'))->pluck('number', 'id'),
        ];
        $head = ['#', __('review.x_at'), __('review.x_by'), __('review.f_type'), __('review.x_number'), __('review.x_action'),
            __('review.x_before'), __('review.x_after'), __('review.x_net_delta'), __('review.x_grand_delta'), __('review.x_seconds'), __('review.x_open'), __('review.log_note')];
        $brief = fn (?array $x) => $x === null ? '' : collect($x)->except('lines')->map(fn ($v, $k) => $k.': '.(is_scalar($v) || $v === null ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE)))->implode(' | ');

        return $this->csv('doc-review-trail-'.$from->format('Ymd').'-'.$to->format('Ymd').'.csv', $head, $events->map(fn ($e) => [
            $e->id, $e->created_at?->format('Y-m-d H:i:s'), $e->user?->displayName(), __('review.type_'.$e->doc_type),
            $nums[$e->doc_type][$e->doc_id] ?? '#'.$e->doc_id, __('review.ev_'.$e->action, ['number' => '']),
            $brief($e->before), $brief($e->after), (float) $e->net_delta, (float) $e->grand_delta,
            $e->seconds_since_prev, $e->open_ms !== null ? round($e->open_ms / 1000, 1) : null, $e->note,
        ]));
    }

    /** CSV بـBOM عشان إكسيل يفتح العربي صح */
    private function csv(string $name, array $head, iterable $rows)
    {
        return response()->streamDownload(function () use ($head, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($out, $head);
            foreach ($rows as $r) {
                fputcsv($out, $r);
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ═══════════════════════ مساعدات ═══════════════════════

    private function filters(Request $request): array
    {
        $status = $request->string('status')->value() ?: 'pending';

        return [
            'q' => $request->string('q')->trim()->value(),
            'type' => in_array($request->string('type')->value(), ['invoice', 'po'], true) ? $request->string('type')->value() : '',
            'mother' => $request->integer('mother') ?: '',
            'group' => $request->string('group')->value() === 'solo' ? 'solo' : ($request->integer('group') ?: ''),
            'client' => $request->integer('client') ?: '',
            'rep' => $request->integer('rep') ?: '',
            'from' => $request->date('from')?->toDateString() ?? '',
            'to' => $request->date('to')?->toDateString() ?? '',
            'flag' => in_array($request->string('flag')->value(), [...DocQueue::FLAGS, 'any'], true) ? $request->string('flag')->value() : '',
            // عينة المراجِع التاني (من صفحة المراجِعين)
            'by' => $request->integer('by') ?: '',
            'day' => $request->date('day')?->toDateString() ?? '',
            'sample' => min(50, max(0, $request->integer('sample'))) ?: '',
            'status' => in_array($status, [...DocQueue::STATUSES, 'all'], true) ? $status : 'pending',
        ];
    }

    private function doc(Request $request, string $type, int $id): Invoice|PurchaseOrder
    {
        abort_unless(in_array($type, DocReviewEvent::TYPES, true), 404);
        $doc = DocReview::find($type, $id);
        abort_unless(Client::visibleTo(Client::whereKey($doc->client_id), $request->user())->exists(), 404);

        return $doc;
    }

    /** @return array<int,int> */
    private function qty(Request $request): array
    {
        $data = $request->validate([
            'qty' => ['required', 'array'],
            'qty.*' => ['nullable', 'integer', 'min:0', 'max:999999'],
        ]);

        return array_map(fn ($v) => (int) $v, $data['qty']);
    }

    /** نفّذ وارجع JSON موحّد: الرسالة + اللوحة الجديدة + صف الطابور + الإنجاز */
    private function run(Request $request, Invoice|PurchaseOrder $doc, callable $fn, string|callable $msg): JsonResponse
    {
        try {
            $fn();
        } catch (Rejected $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'message' => collect($e->errors())->flatten()->first()], 422);
        }

        $doc->refresh();
        $key = (DocReview::typeOf($doc) === 'invoice' ? 'inv-' : 'po-').$doc->id;
        $row = DocQueue::all($request->user(), $key)->first();

        return response()->json([
            'ok' => true,
            'message' => is_callable($msg) ? $msg() : $msg,
            'row' => $row,
            'overall' => DocQueue::overall($request->user()),
            // اللوحة بتتبني تاني من السيرفر بعد كل حفظ — الفحص بيتحسب على الداتا الجديدة
            'pane' => view('erp.review._doc_pane', $pd = $this->paneData($request->user(), $doc))->render(),
            'checks' => collect($pd['checks'])->map(fn ($c) => ['ok' => $c[0], 'why' => $c[1]]),
        ]);
    }

    private function paneData(User $user, Invoice|PurchaseOrder $doc): array
    {
        $type = DocReview::typeOf($doc);
        $doc->load(['items.product', 'client.group.motherCompany']);
        $client = $doc->client;
        $check = DocReview::check($doc);

        // سعر القايمة الصح النهارده لكل صنف — جنب سعر السطر عشان الفرق يبان
        $priceOf = fn (Product $p) => $type === 'invoice'
            ? (float) Pricing::quote($client, $p, null, 1)['unit_price']
            : (float) $client->priceFor($p);

        $listPrices = [];
        foreach ($doc->items as $l) {
            if ($l->product !== null && ! isset($listPrices[$l->product_id])) {
                $listPrices[$l->product_id] = $priceOf($l->product);
            }
        }

        $products = Product::where('kind', Product::KIND_FINISHED)->where('active', true)->orderBy('name')->get()
            ->map(fn ($p) => ['id' => $p->id, 'code' => $p->code, 'name' => $p->displayName(), 'price' => $priceOf($p)])
            ->filter(fn ($p) => $p['price'] > 0)->values();

        $events = DocReviewEvent::with('user:id,name,name_en')->where(['doc_type' => $type, 'doc_id' => $doc->id])->latest('id')->limit(30)->get();

        // (١٢/١١) فحص أوتوماتيك قبل ✓ — [سليم؟ (null = معلومة)، السبب]
        $date = DocReview::dateOf($doc);
        $repId = DocReview::repOf($doc);
        $dups = DocReview::duplicates($doc);
        $state = DocReview::state($check, (float) $doc->grand_total);
        $offLines = $doc->items->filter(fn ($l) => isset($listPrices[$l->product_id]) && abs($listPrices[$l->product_id] - (float) $l->price) >= 0.00005)->count();
        $delivered = $type === 'invoice' || $doc->status === 'delivered';
        $checks = [
            'serial' => [filled($doc->paper_ref) && $dups === [], filled($doc->paper_ref) ? ($dups === [] ? $doc->paper_ref : __('review.f_flag_dup')) : __('review.serial_missing')],
            'date' => [$date !== null && ! ($delivered && $date->isAfter(today()->endOfDay())) && $date->format('Y-m') >= ReviewCheck::FIRST_MONTH, $date?->format('Y-m-d') ?? '—'],
            'rep' => [! $client->rep_id || ! $repId || (int) $client->rep_id === (int) $repId, $client->rep_id && (int) $client->rep_id !== (int) $repId ? __('review.chk_rep_off', ['rep' => $client->rep?->displayName() ?? '#'.$client->rep_id]) : ''],
            'prices' => [$offLines === 0, $offLines ? __('review.chk_prices_off', ['n' => $offLines]) : ''],
            'total' => [$state !== 'changed', $state === 'changed' ? __('review.st_changed') : ''],
        ];
        if (isset(DocQueue::repricedKeys($type, $doc->id)[($type === 'invoice' ? 'inv-' : 'po-').$doc->id])) {
            $checks['repriced'] = [null, __('review.flag_repriced_hint')];
        }

        return [
            'doc' => $doc, 'type' => $type, 'key' => ($type === 'invoice' ? 'inv-' : 'po-').$doc->id,
            'client' => $client, 'check' => $check, 'state' => DocReview::state($check, (float) $doc->grand_total),
            'date' => DocReview::dateOf($doc), 'repId' => DocReview::repOf($doc),
            'rep' => ($r = DocReview::repOf($doc)) ? User::find($r) : null,
            'locks' => DocReview::locks($doc), 'dups' => DocReview::duplicates($doc),
            'listPrices' => $listPrices, 'products' => $products, 'events' => $events, 'checks' => $checks,
            'reps' => User::whereIn('role', User::FIELD_WORK_ROLES)->where('active', true)->orderBy('name')->get(['id', 'name', 'name_en', 'role']),
            'canEdit' => Access::allows($user, 'erp.review.docs.lines'),
            'canReopen' => Access::allows($user, 'erp.review.docs.reopen'),
            'afterReset' => DocReview::dateOf($doc)?->gte(Carbon::parse(DocReview::STOCK_RESET_DAY)) ?? false,
        ];
    }
}
