<?php

namespace App\Http\Controllers;

use App\Exceptions\Rejected;
use App\Models\Channel;
use App\Models\Client;
use App\Models\CustodyItem;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\MerchVisit;
use App\Models\Product;
use App\Models\ReplenishmentRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Visit;
use App\Support\Scope;
use App\Support\VisitOutcomes;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

/**
 * إدارة القنوات + شغل البروموتر (زيارات الرفوف وطلبات الريفيل)
 *
 * ⚠️ **القناة بُعد تجميع وتقرير مش مصدر تسعير.** قرار 2026-07-31.
 * الشاشة بتجاوب على: القناة دي فيها كام عميل، وكام بضاعة، وعاملة كام
 * مبيعات. النسبة بتتحدد لكل عميل من عقده أو خصمه الخاص أو سلسلته.
 */
class ChannelController extends Controller
{
    // ================= القنوات =================

    public function index(Request $request)
    {
        return view('erp.channels', [
            'channels' => Channel::orderBy('id')->get(),
            'stats' => $this->channelStats(),
            'spread' => $this->discountSpread(),
            'subCounts' => Client::visibleTo(Client::query(), $request->user())
                ->selectRaw('sub_channel, COUNT(*) as n')
                ->whereNotNull('sub_channel')->groupBy('sub_channel')
                ->pluck('n', 'sub_channel')->all(),
            // ⚠️ عملاء من غير قناة = عملاء مش داخلين في أي رقم في
            // الشاشة دي. لازم يبانوا، وإلا الإجماليات تحت بتقل عن
            // إجمالي السيستم ومحدش يعرف ليه.
            'orphans' => Client::visibleTo(Client::whereNull('channel_id'), $request->user())->where('status', 'active')->count(),
            'managers' => User::whereIn('role', User::ASSIGNABLE_MANAGER_ROLES)
                ->where('active', true)->with('channels')->get(),
        ]);
    }

    /**
     * أرقام كل قناة.
     *
     * ⚠️ **كل رقم استعلام مجمّع واحد لكل القنوات**، مش استعلام لكل
     * قناة جوه لوب. الشاشة القديمة كانت بتعمل 5 استعلامات × عدد
     * القنوات؛ دي بتعمل 6 إجمالاً مهما زادت القنوات.
     *
     * @return array<int, array<string, float|int>>
     */
    private function channelStats(): array
    {
        // ═══ العملاء والفلوس ═══
        // ملاحظة: `returns` كلمة محجوزة في MySQL — لازم backticks
        $money = Client::visibleTo(Client::query(), auth()->user())
            ->whereNotNull('channel_id')
            ->selectRaw("channel_id,
                COUNT(*) as n_clients,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as n_active,
                SUM(`purchases`) as purchases,
                SUM(`collections`) as collections,
                SUM(`returns`) as n_returns,
                SUM(`balance`) as balance,
                SUM(CASE WHEN `balance` > 0 THEN 1 ELSE 0 END) as n_owing")
            ->groupBy('channel_id')
            ->get()->keyBy('channel_id');

        // ═══ بضاعة أمانة عند العملاء ═══
        // ⚠️ دي **مش مديونية** — البضاعة لسه ملك بروماكس لحد ما تتباع
        // من الرف. عرضها في نفس عمود الرصيد كان بيخلّي الرقمين يتجمعوا
        // في دماغ اللي بيقرا وهما نوعين مختلفين تماماً.
        $consignment = Transaction::query()
            ->join('clients', 'clients.id', '=', 'transactions.client_id')
            ->where('transactions.kind', 'consignment')
            ->whereNotNull('clients.channel_id')
            ->whereIn('transactions.client_id', Client::visibleTo(Client::query(), auth()->user())->select('id'))
            ->selectRaw('clients.channel_id, SUM(transactions.debit - transactions.credit) as amt')
            ->groupBy('clients.channel_id')
            ->pluck('amt', 'channel_id');

        // ═══ الكميات المباعة ═══
        $units = InvoiceItem::query()
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->join('clients', 'clients.id', '=', 'invoices.client_id')
            ->whereNotNull('clients.channel_id')
            ->whereIn('invoices.client_id', Client::visibleTo(Client::query(), auth()->user())->select('id'))
            ->selectRaw('clients.channel_id, SUM(invoice_items.qty) as units')
            ->groupBy('clients.channel_id')
            ->pluck('units', 'channel_id');

        // ═══ بضاعة في عربيات مناديب القناة (عهدة مفتوحة) ═══
        // ⚠️ بسعر البيع الجديد مش بالتكلفة. الشاشة دي تجارية،
        // والمقارنة مع المبيعات لازم تكون بنفس المقياس.
        // ⚠️ **`returned` كان ناقص من المعادلة** (تدقيق ٨/٨/٢٠٢٦).
        // `CustodyItem::remaining()` = `assigned − sold − returned`،
        // والكويري دي كانت بتحسب `assigned − sold` بس — فبضاعة
        // المندوب رجّعها للمخزن كانت لسه محسوبة كأنها في عربيته.
        // الشاشة كانت بتقول رقم أكبر من الحقيقة كل يوم فيه مرتجع.
        //
        // ⚠️ **و`price_new` كانت مقروءة على طول** بدل ما تعدي على
        // `Pricing` — العميل اللي على قايمة تانية بيتحسب بسعر مش
        // بتاعه. القايمة هنا مالهاش عميل (دي بضاعة في عربية)، فبنقرا
        // **سعر القايمة الافتراضية** صراحةً من `price_list_items`
        // عن طريق `Pricing::byList` — وده الرقم اللي الشاشة بتقارن
        // بيه المبيعات.
        //
        // ⚠️ الحساب اتنقل لـPHP مش SQL عن قصد: الأسعار بقت صفوف في
        // `price_list_items` مش عمود على `products`، ونسخ منطق
        // اختيار القايمة في SQL كان هيبقى مصدر تاني للسعر.
        $vanRows = CustodyItem::query()
            ->join('custodies', 'custodies.id', '=', 'custody_items.custody_id')
            ->join('users', 'users.id', '=', 'custodies.user_id')
            ->where('custodies.status', 'open')
            ->whereNotNull('users.channel_id')
            // ⚠️ **و`transferred_out` انضم للمعادلة (١٤/٨).** التحويل من
            // عربية لعربية بيطلّع بضاعة من غير بيع ومن غير إرجاع للمخزن
            // — الكويري دي لازم تفضل مطابقة لـ`CustodyItem::remaining()`
            // بالحرف، وإلا الشاشة بتعد نفس الكرتونة في العربيتين.
            ->selectRaw('users.channel_id, custody_items.product_id,
                SUM(custody_items.assigned - custody_items.sold - custody_items.returned
                    - custody_items.transferred_out) as units')
            ->groupBy('users.channel_id', 'custody_items.product_id')
            ->get();

        $productsById = Product::whereIn('id', $vanRows->pluck('product_id')->unique())
            ->get()->keyBy('id');

        $inVans = $vanRows
            ->groupBy('channel_id')
            ->map(function ($rows, $channelId) use ($productsById) {
                $amt = 0.0;
                $units = 0;

                foreach ($rows as $r) {
                    $left = max(0, (int) $r->units);
                    $units += $left;

                    $p = $productsById->get($r->product_id);

                    if ($p !== null) {
                        $amt += $left * \App\Services\Pricing::byList($p, \App\Services\Pricing::LIST_NEW);
                    }
                }

                return (object) [
                    'channel_id' => $channelId,
                    'amt' => round($amt, 2),
                    'units' => $units,
                ];
            });

        // ═══ مبيعات النهارده ═══
        $today = Invoice::query()
            ->join('clients', 'clients.id', '=', 'invoices.client_id')
            ->whereNotNull('clients.channel_id')
            ->whereDate('invoices.created_at', today())
            ->whereIn('invoices.client_id', Client::visibleTo(Client::query(), auth()->user())->select('id'))
            ->selectRaw('clients.channel_id, SUM(invoices.total) as amt')
            ->groupBy('clients.channel_id')
            ->pluck('amt', 'channel_id');

        // ═══ الفريق ═══
        $team = User::query()
            ->whereNotNull('channel_id')->where('active', true)
            ->selectRaw('channel_id, COUNT(*) as n')
            ->groupBy('channel_id')->pluck('n', 'channel_id');

        $out = [];

        foreach (Channel::pluck('id') as $id) {
            $m = $money[$id] ?? null;
            $v = $inVans[$id] ?? null;

            $out[$id] = [
                'clients' => (int) ($m->n_clients ?? 0),
                'active_clients' => (int) ($m->n_active ?? 0),
                'owing' => (int) ($m->n_owing ?? 0),
                'purchases' => (float) ($m->purchases ?? 0),
                'collections' => (float) ($m->collections ?? 0),
                'returns' => (float) ($m->n_returns ?? 0),
                'balance' => (float) ($m->balance ?? 0),
                'consignment' => (float) ($consignment[$id] ?? 0),
                'units' => (int) ($units[$id] ?? 0),
                'in_vans' => (float) ($v->amt ?? 0),
                'in_vans_units' => (int) ($v->units ?? 0),
                'today' => (float) ($today[$id] ?? 0),
                'team' => (int) ($team[$id] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * توزيع الخصم الفعلي جوه القناة — **للقراءة بس**.
     *
     * ⚠️ الشاشة لازم تقول «العملاء دول على كام» من غير ما تدّي انطباع
     * إن الرقم بيتظبط من هنا. القناة بتعرض المدى عشان المدير يشوف
     * التشتت: قناة كلها 50% غير قناة من 10% لـ 60% — التانية معناها
     * إن مفيش سياسة تسعير موحّدة وفيه عميل بياخد شروط محدش راجعها.
     *
     * ⚠️ `chunkById` مش `get()` — الحساب بيعدّي على كل عميل نشط،
     * وتحميلهم كلهم في الذاكرة بيفجّرها لما العدد يكبر.
     *
     * @return array<int, array{min: float, max: float, avg: float, n: int}>
     */
    private function discountSpread(): array
    {
        $acc = [];

        Client::visibleTo(Client::with(['contract', 'group.contract', 'group']), auth()->user())
            ->whereNotNull('channel_id')
            ->where('status', 'active')
            ->chunkById(200, function ($chunk) use (&$acc) {
                foreach ($chunk as $client) {
                    $rate = $client->effectiveDiscount();
                    $id = $client->channel_id;

                    if (! isset($acc[$id])) {
                        $acc[$id] = ['min' => $rate, 'max' => $rate, 'sum' => 0.0, 'n' => 0];
                    }

                    $acc[$id]['min'] = min($acc[$id]['min'], $rate);
                    $acc[$id]['max'] = max($acc[$id]['max'], $rate);
                    $acc[$id]['sum'] += $rate;
                    $acc[$id]['n']++;
                }
            });

        $out = [];

        foreach ($acc as $id => $row) {
            $out[$id] = [
                'min' => $row['min'],
                'max' => $row['max'],
                'avg' => $row['n'] ? $row['sum'] / $row['n'] : 0.0,
                'n' => $row['n'],
            ];
        }

        return $out;
    }

    public function update(Request $request, Channel $channel)
    {
        // ⚠️ **مفيش نسبة خصم.** القناة بُعد تجميع — النسبة بتتحدد لكل
        // عميل. لما كانت هنا، عميل جديد في «كي أكاونت» كان بياخد 50%
        // أوتوماتيك من غير ما حد يتفاوض عليها، وأول فاتورة بتطلع بخصم
        // محدش قرره.
        //
        // ⚠️ **الاسمين مع بعض.** `displayName()` بترجّع العربي كـfallback،
        // فالواجهة الإنجليزية بتعرض «كي أكاونت» في نص جملة إنجليزي
        // والغلط بيعدّي من غير ما حد ياخد باله.
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'name_en' => ['nullable', 'string', 'max:100'],
            'active' => ['nullable', 'boolean'],
        ]);

        $channel->update([
            'name' => $data['name'],
            'name_en' => $data['name_en'] ?? null,
            'active' => $request->boolean('active', true),
        ]);

        return back()->with('ok', __('flash.channel_updated'));
    }

    /** ربط مدير بقنوات */
    public function assignManager(Request $request, User $user)
    {
        $data = $request->validate([
            'channels' => ['nullable', 'array'],
            'channels.*' => ['exists:channels,id'],
        ]);

        $user->channels()->sync($data['channels'] ?? []);

        return back()->with('ok', __('flash.manager_channels_updated', ['name' => $user->displayName()]));
    }

    // ================= زيارات الرفوف — المصدرين مع بعض =================

    /** أقصى عدد صفوف بيتقرا من كل مصدر قبل الدمج */
    private const SHELF_CAP = 400;

    /**
     * ═══════════════════════════════════════════════════════════
     * «زيارات الرفوف» — المصدرين في ليستة واحدة (١٥ أغسطس ٢٠٢٦)
     * ═══════════════════════════════════════════════════════════
     *
     * ⚠️⚠️ **صور الرف بتتخزن في مكانين، والشاشة كانت بتعرض واحد:**
     *
     * 1. **البروموتر** — `merch_visits.photo_before/photo_after`،
     *    صورة واحدة لكل مرحلة، جزء من فلو الريفيل.
     * 2. **أي مندوب جوه زيارته العادية** — `visit_photos` (جدول
     *    منفصل بعمود `stage`، **متعدد الصور** لكل مرحلة عن قصد،
     *    مايجريشن `000300`). **ده ماكانش ليه أي شاشة** غير كارت
     *    في «يوم المندوب» — يعني صور موجودة في الداتابيز والمالك
     *    بيقول «مفيش حاجة أشوفها فيها». ده بالظبط البلاغ.
     *
     * ⚠️ **الدمج في الكنترولر مش في SQL.** الجدولين مالهمش نفس
     * الشكل (واحد صورة واحدة لكل مرحلة، والتاني جدول أبناء)، و
     * `UNION` كان هيحتاج تسطيح الصور في سترنج — قراءة صعبة وباج
     * مستني. الحل: كولكشن مطبّعة من الاتنين، مرتّبة بالوقت،
     * ومقسومة صفحات بـ`LengthAwarePaginator`.
     *
     * ⚠️ **سقف لكل مصدر (`SHELF_CAP`)** — الدمج في الميموري معناه
     * إن الكويري مش بتقسّم في الداتابيز. السقف بيمنع الشاشة تحاول
     * تحمّل سنة زيارات، والتنبيه بيقول للمستخدم يضيّق التاريخ.
     */
    public function merchVisits(Request $request)
    {
        $viewer = $request->user();

        // ⚠️ **سكوب الفريق** (تدقيق ٨/٨/٢٠٢٦): القايمة كانت على مستوى
        // الشركة — صور رفوف وزيارات بروموترات مديرين تانيين.
        $team = User::fieldVisibleTo(User::query(), $viewer)->select('id');

        // ═══ الفلاتر ═══
        // ⚠️ `date` القديمة لسه شغّالة — لينك متكاش أو بوكمارك للمالك
        // مايرجعش صفحة فاضية. اليوم الواحد = `from` و`to` نفس اليوم.
        // ⚠️ **`is_string` قبل أي `(string)`** — `?q[]=x` بتوصل أراي،
        // والكاست عليها بيطلع تحذير و«Array» كنص بحث.
        $txt = fn (string $k) => is_string($request->input($k)) ? trim($request->input($k)) : '';

        $legacy = $txt('date');
        $from = $this->shelfDay($txt('from') ?: ($legacy ?: null));
        $to = $this->shelfDay($txt('to') ?: ($legacy ?: null));

        if ($from !== null && $to !== null && $to->lt($from)) {
            [$from, $to] = [$to, $from];
        }

        $repId = (int) $request->integer('user');
        $search = $txt('q');
        $source = $txt('source');
        $shots = $txt('shots');

        $clientIds = $search === '' ? null
            : Client::search(Client::visibleTo(Client::query(), $viewer), $search)
                ->pluck('id')->all();

        // ═══════════ المصدر ١: زيارات البروموتر ═══════════
        $rows = collect();
        $capped = false;

        if ($source !== 'rep') {
            $mq = MerchVisit::with(['user', 'client.channel', 'client.zone', 'refills.product'])
                ->whereIn('user_id', $team);

            $this->shelfCommonFilters($mq, $repId, $clientIds, $from, $to);

            $merch = $mq->orderByDesc('id')->limit(self::SHELF_CAP)->get();
            $capped = $capped || $merch->count() >= self::SHELF_CAP;

            foreach ($merch as $m) {
                $rows->push([
                    'source' => 'promoter',
                    'key' => 'm'.$m->id,
                    'user' => $m->user,
                    'client' => $m->client,
                    'at' => $m->checked_in_at ?? $m->created_at,
                    'minutes' => $m->minutes(),
                    'before' => array_values(array_filter([$m->photoBeforeUrl()])),
                    'after' => array_values(array_filter([$m->photoAfterUrl()])),
                    'moved' => $m->movedTotal(),
                    'short' => $m->outOfStockCount(),
                    'refills' => $m->refills,
                    'visit_id' => null,
                ]);
            }
        }

        // ═══════════ المصدر ٢: زيارة مندوب فيها صور رف ═══════════
        if ($source !== 'promoter') {
            $vq = Visit::with(['user', 'client.channel', 'client.zone'])
                ->whereIn('user_id', $team)
                ->whereIn('id', VisitOutcomes::idSources()['photos']);

            $this->shelfCommonFilters($vq, $repId, $clientIds, $from, $to);

            $repVisits = $vq->orderByDesc('id')->limit(self::SHELF_CAP)->get();
            $capped = $capped || $repVisits->count() >= self::SHELF_CAP;
            $photos = VisitOutcomes::photos($repVisits->pluck('id')->all());

            foreach ($repVisits as $v) {
                $ph = $photos->get($v->id) ?? collect();

                $rows->push([
                    'source' => 'rep',
                    'key' => 'v'.$v->id,
                    'user' => $v->user,
                    'client' => $v->client,
                    'at' => $v->checked_in_at ?? $v->created_at,
                    'minutes' => $v->minutes(),
                    'before' => $ph->where('stage', 'before')->map(fn ($p) => $p->url())->values()->all(),
                    'after' => $ph->where('stage', 'after')->map(fn ($p) => $p->url())->values()->all(),
                    // البروموتر بس اللي بينقل بضاعة للرف — المندوب
                    // بيصوّر الترتيب، فالأعمدة دي بتفضل فاضية عن قصد
                    'moved' => null,
                    'short' => null,
                    'refills' => collect(),
                    'visit_id' => $v->id,
                ]);
            }
        }

        // ═══ «قبل وبعد كاملة» ولا «صورة واحدة بس» ═══
        // ⚠️ الصف اللي مالوش صور خالص (زيارة بروموتر لسه مفتوحة)
        // بيتشال من الفلترين الاتنين — مش «كاملة» ولا «ناقصة».
        if ($shots === 'full') {
            $rows = $rows->filter(fn ($r) => $r['before'] !== [] && $r['after'] !== []);
        } elseif ($shots === 'partial') {
            $rows = $rows->filter(fn ($r) => ($r['before'] === []) !== ($r['after'] === []));
        }

        // ⚠️ الترتيب بوقت الزيارة الفعلي مش بالـid — الجدولين مالهمش
        // نفس عدّاد، فترتيب بالـid كان هيخلط اليومين.
        $rows = $rows->sortByDesc(fn ($r) => $r['at']?->getTimestamp() ?? 0)->values();

        $page = max(1, (int) $request->integer('page'));
        $perPage = 25;

        $visits = new LengthAwarePaginator(
            $rows->slice(($page - 1) * $perPage, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return view('erp.merch_visits', [
            'visits' => $visits,
            // ⚠️ **كل رولز الشغل الميداني مش البروموترات بس** — الشاشة
            // بقت بتعرض صور المناديب كمان، فقايمة فلتر فيها البروموترات
            // بس كانت هتخفي نص المحتوى عن الفلترة.
            'reps' => User::fieldVisibleTo(
                User::whereIn('role', User::FIELD_WORK_ROLES), $viewer)
                ->where('active', true)->orderBy('name')->get(),
            'capped' => $capped,
            'cap' => self::SHELF_CAP,
            'filters' => [
                'user' => $repId,
                'from' => $from?->toDateString() ?? '',
                'to' => $to?->toDateString() ?? '',
                'q' => $search,
                'source' => $source,
                'shots' => $shots,
            ],
        ]);
    }

    /** فلاتر مشتركة بين الجدولين — نفس الأعمدة بنفس الأسماء */
    private function shelfCommonFilters($q, int $repId, ?array $clientIds, $from, $to): void
    {
        if ($repId > 0) {
            $q->where('user_id', $repId);
        }
        if ($clientIds !== null) {
            $q->whereIn('client_id', $clientIds);
        }
        if ($from !== null) {
            $q->whereDate('created_at', '>=', $from->toDateString());
        }
        if ($to !== null) {
            $q->whereDate('created_at', '<=', $to->toDateString());
        }
    }

    /** تاريخ من الريكوست — null لو فاضي، والافتراضي لو بايظ */
    private function shelfDay($raw): ?Carbon
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        return rescue(
            fn () => Carbon::parse($raw)->startOfDay(),
            fn () => null,
            report: false,
        );
    }

    // ================= طلبات الريفيل =================

    public function replenishments(Request $request)
    {
        // ⚠️ سكوب الفريق — نفس سبب `merchVisits` فوق. الفلترة على
        // العميل (مش على البروموتر) عشان الطلب بيتحوّل لـPO على
        // حساب العميل، والمدير المسؤول عن العميل هو صاحب القرار.
        // ⚠️ `approver` و`pickOrder` في الإيجر لودينج (فلو ١٥/٨) —
        // العمود بيعرض مين وافق وأمر التجهيز لكل صف، ومن غيرها
        // دي كويريتين لكل طلب في الصفحة.
        $q = ReplenishmentRequest::with([
            'client', 'promoter', 'assignee', 'approver', 'pickOrder', 'purchaseOrder', 'items.product',
        ])
            ->whereIn('client_id', Client::visibleTo(Client::query(), $request->user())->select('id'));

        if ($status = $request->string('status')->value()) {
            $q->where('status', $status);
        }

        return view('erp.replenishments', [
            'requests' => $q->latest()->paginate(25)->withQueryString(),
            // ⚠️ **كل رولز الشغل الميداني** (طلب المالك ١١/٨ مساءً):
            // «نفس المندوب اللي طلبه ولا مندوب تاني» — سيلز وسواق
            // وبروموتر ومدير. `fieldVisibleTo` بتسكّب فريق المدير بس،
            // و`assignTo` جواه `Scope::assertRep` بيمنع أي تجاوز.
            'drivers' => User::fieldVisibleTo(
                User::whereIn('role', User::FIELD_WORK_ROLES), $request->user())
                ->where('active', true)->orderBy('name')->get(),
            // كتالوج منتقي الأصناف — لتعديل الطلب المستني (١٢/٨)
            'products' => Product::where('active', true)->orderBy('code')
                ->get(['id', 'code', 'name', 'name_en']),
            'filters' => $request->only('status'),
        ]);
    }

    /**
     * ═══ تعديل أصناف/كميات طلب ريفيل مستني (١٢ أغسطس ٢٠٢٦) ═══
     *
     * طلب المالك: «أعدل وألغي طلبات الريفيل». الإلغاء كان موجود —
     * ده التعديل: قبل التنزيل بس (`pending`)، لأن بعده الطلب بقى
     * أمر توريد وله مساره (`poEditable` ورجوعه للحسابات).
     *
     * ⚠️ مفيش تسعير هنا خالص — البنود كميات بالقطع، والتسعير كله
     * بيحصل وقت التحويل في `ReplenishmentRequest::assignTo` زي ما هو.
     */
    public function updateReplenishment(Request $request, ReplenishmentRequest $replenishmentRequest)
    {
        // نفس حارس الإلغاء بالحرف — مدير مايلمسش طلب فرع مش بتاعه
        Scope::assertClient($request->user(), $replenishmentRequest->client);

        if ($replenishmentRequest->status !== 'pending') {
            return back()->withErrors(['status' => __('api.request_already_assigned')]);
        }

        $data = $request->validate([
            'qty' => ['required', 'array'],
            // نفس سقف طلب البضاعة من الأبلكيشن (9999 بالقطع)
            'qty.*' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        $qty = [];
        foreach ($data['qty'] as $pid => $q) {
            if ((int) $q > 0) {
                $qty[(int) $pid] = (int) $q;
            }
        }

        // صنف مش موجود في الكتالوج = مفتاح مزوّر — مش فاليديشن عادي
        //
        // ⚠️ **`sellable()`** (١٧/٨) — دي كانت `Product::whereIn` من
        // غير فلتر، بينما `SupplierController::storeOrder` (نفس
        // النمط بالحرف) فيها `->where('active', true)`. الفرق ده كان
        // بيخلّي صنف درافت يتحقن في طلب ريفيل معلّق → يتوافق عليه →
        // يبقى أمر توريد.
        $known = Product::sellable()->whereIn('id', array_keys($qty))->pluck('id')
            ->map(fn ($i) => (int) $i)->all();
        $qty = array_intersect_key($qty, array_flip($known));

        if ($qty === []) {
            return back()->withErrors(['qty' => __('stock.pick_no_items')]);
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($replenishmentRequest, $qty) {
            $replenishmentRequest->items()->delete();

            foreach ($qty as $pid => $q) {
                \App\Models\ReplenishmentItem::create([
                    'replenishment_request_id' => $replenishmentRequest->id,
                    'product_id' => $pid,
                    'qty' => $q,
                ]);
            }
        });

        // «طلبك اتعدل» — نفس منطق لينكات الإلغاء: طلب المندوب مالوش
        // تاب ريفيل فلينكه فاضي، والبروموتر بيفتح تاب الريفيل
        \App\Models\AppNotification::send(
            $replenishmentRequest->promoter,
            fn () => __('field.notif_replenishment_edited_title', [
                'number' => $replenishmentRequest->number,
            ]),
            fn () => __('field.notif_replenishment_edited_body', [
                'client' => $replenishmentRequest->client->displayName(),
            ]),
            good: true,
            link: $replenishmentRequest->origin() === 'rep'
                ? null
                : \App\Models\AppNotification::replenishmentLink($replenishmentRequest->id),
        );

        return back()->with('ok', __('flash.replenishment_updated'));
    }

    /**
     * الموافقة على طلب الريفيل وتنزيله على مندوب.
     *
     * ⚠️ **مابقاش بيعمل أمر توريد** (قرار المالك ١٥/٨) — بيرفع أمر
     * تجهيز على المخزن، والمندوب بيستلم في عهدته. مفيش قيود مالية.
     */
    public function assignReplenishment(Request $request, ReplenishmentRequest $replenishmentRequest)
    {
        $data = $request->validate([
            'assigned_to' => ['required', 'exists:users,id'],
            // ⚠️ `price_mode` بقى **متجاهَل** — مافيش سعر في فلو
            // الريفيل أصلاً. باقي `nullable` عشان أي فورم قديم
            // لسه بيبعته مايتردّش بـ422.
            'price_mode' => ['nullable', 'in:client,channel,old,new'],
        ]);

        try {
            // المنطق كله في الموديل عشان الويب والأبلكيشن يمشوا بنفس الفلو
            // ⚠️ الفاعل بيتبعت للموديل عشان الحارس يتنفّذ هناك —
            // الراوت ده كان بلا حارس قناة ولا فحص مستلم، والتوأم في
            // الـAPI كان بيفحص الطلب مش المستلم.
            $pick = $replenishmentRequest->assignTo(
                User::findOrFail($data['assigned_to']),
                $data['price_mode'] ?? 'client',
                $request->user(),
            );
        } catch (Rejected $e) {
            // رفض متوقّع بس — خطأ SQL بيكمّل لـ 500 عن قصد
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return back()->with('ok', __('flash.replenishment_picked', ['number' => $pick->number]));
    }

    public function cancelReplenishment(Request $request, ReplenishmentRequest $replenishmentRequest)
    {
        // ⚠️ **كانت بلا أي حارس إطلاقاً** — أي مدير بيلغي طلب ريفيل
        // لأي فرع في الشركة، والبروموتر بيرجع الأسبوع الجاي يلاقي
        // الرف فاضي وطلبه ملغي من حد مالوش علاقة.
        Scope::assertClient($request->user(), $replenishmentRequest->client);

        // ⚠️ الإلغاء + إشعار الطالب في الموديل — الويب كان بيلغي
        // في صمت والـAPI بيبلّغ، فاتوحّد المسار (١١/٨ مساءً).
        $replenishmentRequest->cancelAndNotify();

        return back()->with('ok', __('flash.replenishment_cancelled'));
    }
}
