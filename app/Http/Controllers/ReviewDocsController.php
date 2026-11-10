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
    public function index(Request $request)
    {
        $user = $request->user();
        $f = $this->filters($request);

        $all = DocQueue::all($user);
        $scoped = DocQueue::filter($all, $f);
        $shown = DocQueue::byStatus($scoped, $f['status']);

        $groupIds = $all->pluck('group_id')->filter()->unique();

        return view('erp.review.docs', [
            'overall' => DocQueue::overall($request->user()),
            'scoped' => DocQueue::stats($scoped),
            'groups' => DocQueue::grouped($shown),
            'count' => $shown->count(),
            'counts' => [
                'pending' => $scoped->where('state', 'pending')->count(),
                'ok' => $scoped->where('state', 'ok')->count(),
                'issue' => $scoped->where('state', 'issue')->count(),
                'changed' => $scoped->where('state', 'changed')->count(),
                'all' => $scoped->count(),
            ],
            'f' => $f,
            'open' => $request->string('doc')->value(),
            'mothers' => MotherCompany::whereIn('id', $all->pluck('mother_id')->filter()->unique())->orderBy('name')->get(['id', 'name', 'name_en']),
            'chains' => ClientGroup::whereIn('id', $groupIds)->orderBy('name')->get(['id', 'name', 'name_en']),
            'clientOpts' => $all->unique('client_id')->sortBy('client')->mapWithKeys(fn ($r) => [$r['client_id'] => $r['client_code'].' · '.$r['client']]),
            'repOpts' => $all->whereNotNull('rep_id')->unique('rep_id')->sortBy('rep')->mapWithKeys(fn ($r) => [$r['rep_id'] => $r['rep']]),
            'canEdit' => Access::allows($user, 'erp.review.docs.lines'),
            'canReopen' => Access::allows($user, 'erp.review.docs.reopen'),
            'canPeople' => Access::allows($user, 'erp.review.docs.people'),
        ]);
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
            'flag' => in_array($request->string('flag')->value(), ['dup', 'noserial'], true) ? $request->string('flag')->value() : '',
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
            'pane' => view('erp.review._doc_pane', $this->paneData($request->user(), $doc))->render(),
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

        return [
            'doc' => $doc, 'type' => $type, 'key' => ($type === 'invoice' ? 'inv-' : 'po-').$doc->id,
            'client' => $client, 'check' => $check, 'state' => DocReview::state($check, (float) $doc->grand_total),
            'date' => DocReview::dateOf($doc), 'repId' => DocReview::repOf($doc),
            'rep' => ($r = DocReview::repOf($doc)) ? User::find($r) : null,
            'locks' => DocReview::locks($doc), 'dups' => DocReview::duplicates($doc),
            'listPrices' => $listPrices, 'products' => $products, 'events' => $events,
            'reps' => User::whereIn('role', User::FIELD_WORK_ROLES)->where('active', true)->orderBy('name')->get(['id', 'name', 'name_en', 'role']),
            'canEdit' => Access::allows($user, 'erp.review.docs.lines'),
            'canReopen' => Access::allows($user, 'erp.review.docs.reopen'),
            'afterReset' => DocReview::dateOf($doc)?->gte(Carbon::parse(DocReview::STOCK_RESET_DAY)) ?? false,
        ];
    }
}
