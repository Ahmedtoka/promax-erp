<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Stock;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Zone;
use App\Services\ContractIntake;
use App\Support\Governorates;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ErpController extends Controller
{
    // ================= نظرة عامة =================

    /**
     * مسح الترانزاكشنز من الداش بورد — الماستر داتا بتفضل.
     *
     * ⚠️ **التأكيد بكتابة WIPE مش تشيك بوكس.** المسح مالوش رجعة،
     * وضغطة غلط على زرار جنب زرار تانية بتضيع شغل — الكتابة بتجبر
     * على قصد حقيقي. المنطق في `App\Services\ResetTransactions`.
     */
    public function wipeTransactions(Request $request)
    {
        $request->validate(
            ['confirm' => ['required', 'in:WIPE']],
            ['confirm.in' => __('ops.wipe_bad_confirm')],
        );

        $wiped = \App\Services\ResetTransactions::run();

        return back()->with('ok', __('flash.wiped_done', [
            'n' => number_format(array_sum($wiped)),
        ]));
    }

    /** تحميل داتا ديمو لمندوب — بيمشي في الفلو الحقيقي (promax:demo) */
    public function demoData(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email', 'exists:users,email']]);

        \Illuminate\Support\Facades\Artisan::call('promax:demo', ['email' => $data['email']]);

        return back()->with('ok', __('flash.demo_loaded'));
    }

    /**
     * ═══ الداشبورد الرئيسية (إعادة بناء ٢٢ أغسطس ٢٠٢٦) ═══
     *
     * طلب المالك: «بالعين أقدر أشوف حال الشركة كاملة» — تشارتات
     * ودواير، فلاتر (فترة + مدير + مندوب) على كل الأرقام، وكل رقم
     * **لينكابل** بيوديك للتقرير أو الصفحة بتاعته بنفس الفلاتر.
     *
     * ⚠️ عقيدة الأرقام زي ما هي: مبيعات = `grand_total` من الفواتير،
     * التحصيل = قيود `collection` الدائنة، والمديونية سنابشوت حالي
     * (مش بتتفلتر بالفترة — الرصيد رصيد).
     */
    public function overview(Request $request)
    {
        $u = auth()->user();

        // ═══ الفلاتر ═══
        try {
            $from = $request->filled('from') ? \Illuminate\Support\Carbon::parse($request->input('from')) : today()->startOfMonth();
        } catch (\Throwable) {
            $from = today()->startOfMonth();
        }
        try {
            $to = $request->filled('to') ? \Illuminate\Support\Carbon::parse($request->input('to')) : today();
        } catch (\Throwable) {
            $to = today();
        }
        [$a, $b] = [$from->copy()->startOfDay(), $to->copy()->endOfDay()];

        // المدير: الأدمن بيختاره من الفلتر — والتشانل مانجر هو نفسه دايماً
        $mgrId = $u?->role === 'manager' ? $u->id : ($request->integer('manager_id') ?: null);
        $repId = $request->integer('user_id') ?: null;

        // المناديب المعنيين: مندوب محدد ← هو بس · مدير ← فريقه كله
        $repIds = null;
        if ($repId) {
            $repIds = [$repId];
        } elseif ($mgrId) {
            $repIds = User::whereIn('role', User::FIELD_WORK_ROLES)
                ->where('manager_id', $mgrId)->pluck('id')->push($mgrId)->all();
        }

        $invQ = fn () => Invoice::whereBetween('created_at', [$a, $b])
            ->when($repIds, fn ($q) => $q->whereIn('user_id', $repIds));

        // ═══ KPIs الفترة ═══
        $inv = $invQ()->selectRaw("COUNT(*) n, COALESCE(SUM(grand_total),0) g,
            COALESCE(SUM(total),0) net, COALESCE(SUM(tax_total),0) tax,
            COALESCE(SUM(CASE WHEN payment='cash' THEN grand_total ELSE 0 END),0) cash_g")->first();

        // التحصيل — قيود collection، ولو مفلتر بمندوب/مدير بنمسك القيود
        // اللي مصدرها فواتيره (كاش مع فاتورة) أو زياراته (تحصيل ميداني)
        $coll = Transaction::where('kind', 'collection')
            ->whereBetween('created_at', [$a, $b])
            ->when($repIds, fn ($q) => $q->where(fn ($w) => $w
                ->where(fn ($x) => $x->where('source_type', Invoice::class)
                    ->whereIn('source_id', Invoice::whereIn('user_id', $repIds)->select('id')))
                ->orWhere(fn ($x) => $x->where('source_type', \App\Models\Visit::class)
                    ->whereIn('source_id', \App\Models\Visit::whereIn('user_id', $repIds)->select('id')))))
            ->sum('credit');

        $rets = \App\Models\ClientReturn::whereBetween('created_at', [$a, $b])
            ->when($repIds, fn ($q) => $q->whereIn('user_id', $repIds))
            ->selectRaw('COUNT(*) n, COALESCE(SUM(grand_total),0) g')->first();

        $visitsN = \App\Models\Visit::whereBetween('created_at', [$a, $b])
            ->when($repIds, fn ($q) => $q->whereIn('user_id', $repIds))->count();

        $giftsQ = \App\Models\GiftHandout::whereBetween('created_at', [$a, $b])
            ->when($repIds, fn ($q) => $q->whereIn('user_id', $repIds))->sum('qty');

        $newClientsN = Client::visibleTo(Client::whereBetween('created_at', [$a, $b]))
            ->when($mgrId, fn ($q) => $q->where('manager_id', $mgrId))->count();

        // المديونية سنابشوت — مش بتتفلتر بالفترة
        $debt = Client::visibleTo(Client::where('balance', '>', 0))
            ->when($mgrId, fn ($q) => $q->where('manager_id', $mgrId))
            ->selectRaw('COALESCE(SUM(balance),0) g, COUNT(*) n')->first();

        // ═══ السلسلة الزمنية: مبيعات مقابل تحصيل ═══
        // الفترة ≤ ٣٥ يوم = باليوم، أطول = بالشهر
        $daily = $a->diffInDays($b) <= 35;
        $fmt = $daily ? '%Y-%m-%d' : '%Y-%m';

        $salesSeries = $invQ()->selectRaw("DATE_FORMAT(created_at, '$fmt') k, SUM(grand_total) v")
            ->groupBy('k')->pluck('v', 'k');
        $collSeries = Transaction::where('kind', 'collection')
            ->whereBetween('created_at', [$a, $b])
            ->when($repIds, fn ($q) => $q->where(fn ($w) => $w
                ->where(fn ($x) => $x->where('source_type', Invoice::class)
                    ->whereIn('source_id', Invoice::whereIn('user_id', $repIds)->select('id')))
                ->orWhere(fn ($x) => $x->where('source_type', \App\Models\Visit::class)
                    ->whereIn('source_id', \App\Models\Visit::whereIn('user_id', $repIds)->select('id')))))
            ->selectRaw("DATE_FORMAT(created_at, '$fmt') k, SUM(credit) v")
            ->groupBy('k')->pluck('v', 'k');

        $series = [];
        $cursor = $a->copy();
        while ($cursor <= $b) {
            $k = $cursor->format($daily ? 'Y-m-d' : 'Y-m');
            $series[$k] = [
                'sales' => (float) ($salesSeries[$k] ?? 0),
                'coll' => (float) ($collSeries[$k] ?? 0),
            ];
            $daily ? $cursor->addDay() : $cursor->addMonthNoOverflow()->startOfMonth();
        }

        // ═══ الدواير والتوزيعات ═══
        $byChannel = $invQ()->join('clients', 'clients.id', '=', 'invoices.client_id')
            ->join('channels', 'channels.id', '=', 'clients.channel_id')
            ->selectRaw('channels.id cid, channels.name cname, SUM(invoices.grand_total) v')
            ->groupBy('cid', 'cname')->orderByDesc('v')->get();

        $byFamily = DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->join('products', 'products.id', '=', 'invoice_items.product_id')
            ->whereBetween('invoices.created_at', [$a, $b])
            ->when($repIds, fn ($q) => $q->whereIn('invoices.user_id', $repIds))
            ->selectRaw('products.family, SUM(invoice_items.total) v')
            ->groupBy('products.family')->orderByDesc('v')->get();

        $catCounts = Client::visibleTo(Client::query())
            ->when($mgrId, fn ($q) => $q->where('manager_id', $mgrId))
            ->selectRaw('category, COUNT(*) as n')
            ->groupBy('category')->pluck('n', 'category')->all();

        // ═══ أفضل المناديب والعملاء في الفترة ═══
        $topReps = $invQ()->selectRaw('user_id, COUNT(*) n, SUM(grand_total) v')
            ->groupBy('user_id')->orderByDesc('v')->take(8)->get()
            ->map(function ($r) {
                $r->rep = User::find($r->user_id);

                return $r;
            });

        $topClients = $invQ()->selectRaw('client_id, COUNT(*) n, SUM(grand_total) v')
            ->groupBy('client_id')->orderByDesc('v')->take(10)->get();
        $topClientRows = Client::with(['group', 'channel'])
            ->whereIn('id', $topClients->pluck('client_id'))->get()->keyBy('id');

        return view('erp.overview', [
            'from' => $a->toDateString(),
            'to' => $to->toDateString(),
            'mgrId' => $mgrId,
            'repId' => $repId,
            'managers' => $u?->role === 'manager' ? collect()
                : User::whereIn('role', User::ASSIGNABLE_MANAGER_ROLES)->where('active', true)->orderBy('name')->get(),
            'reps' => User::fieldVisibleTo(User::whereIn('role', User::FIELD_WORK_ROLES))
                ->where('active', true)
                ->when($mgrId, fn ($q) => $q->where(fn ($w) => $w->where('manager_id', $mgrId)->orWhere('id', $mgrId)))
                ->orderBy('name')->get(),

            'inv' => $inv,
            'coll' => (float) $coll,
            'rets' => $rets,
            'visitsN' => $visitsN,
            'giftsQ' => (int) $giftsQ,
            'newClientsN' => $newClientsN,
            'debt' => $debt,
            'series' => $series,
            'daily' => $daily,
            'byChannel' => $byChannel,
            'byFamily' => $byFamily,
            'catCounts' => $catCounts,
            'aging' => $this->agingTotals(),
            'topReps' => $topReps,
            'topClients' => $topClients,
            'topClientRows' => $topClientRows,
            'stockValue' => Stock::join('products', 'products.id', '=', 'stocks.product_id')
                ->sum(DB::raw('stocks.qty * products.price_new')),
            'openRequests' => \App\Models\ClientRequest::whereIn('status', ['pending', 'review'])->count(),
            'openPos' => PurchaseOrder::whereIn('status', ['pending', 'arrived'])
                ->when($repIds, fn ($q) => $q->whereIn('assigned_to', $repIds))->count(),
        ]);
    }

    /** أعمار المديونية إجمالاً (تقديري FIFO) */
    private function agingTotals(): array
    {
        $t = ['a30' => 0.0, 'a60' => 0.0, 'a90' => 0.0, 'a180' => 0.0, 'a180p' => 0.0];

        Client::visibleTo(Client::where('balance', '>', 0))
            ->with(['transactions' => fn ($q) => $q->where('debit', '>', 0)])
            ->chunk(200, function ($chunk) use (&$t) {
                foreach ($chunk as $client) {
                    foreach ($client->aging() as $k => $v) {
                        $t[$k] += $v;
                    }
                }
            });

        return $t;
    }

    // ================= العملاء =================

    public function clients(Request $request)
    {
        // contract و group لازم eager — effectiveDiscount() بتنادي عليهم لكل صف
        // ⚠️ وسكوب الفرع: مدير المعادي بيشوف عملاء المعادي والمركزي بس
        // ⚠️ وسكوب التشانل مانجر: عملاءه المسكّنين له بس (2026-08-05)
        // ⚠️ **`manager` eager** (١٥ أغسطس ٢٠٢٦) — عمود «مدير القناة»
        // بيقرا العلاقة لكل صف، ومن غيرها 40 صف = 40 كويري زيادة.
        $q = Client::visibleTo(\App\Models\Branch::scope(
            Client::query()->with(['zone', 'contract', 'group.contract', 'manager']),
        ));

        // ⚠️ **الافتراضي الكل مش الشغّال بس.** بعد استيراد الـ455،
        // معظم القايمة `pending` — لو خبّيناهم افتراضياً المستخدم
        // بيدوّر على عميل لسه مفعّلوش ومش بيلاقيه ويفتكر الاستيراد
        // ضاع. الفلتر بيوريه اللي عايزه، والشارة على الصف بتفرّق.
        if ($st = $request->string('status')->value()) {
            $q->where('status', $st);
        }

        if ($s = $request->string('q')->trim()->value()) {
            // البحث الموحّد: فرع + سلسلة، عربي + إنجليزي (١١/٨)
            Client::search($q, $s);
        }
        if ($cat = $request->string('cat')->value()) {
            $q->where('category', $cat);
        }
        if ($zone = $request->integer('zone')) {
            $q->where('zone_id', $zone);
        }
        // ⚠️ فلتر المحافظة بيقرا من عمود العميل نفسه مش من منطقته —
        // عميل من غير منطقة لسه ليه محافظة ولازم يظهر في فلترها.
        if ($gov = $request->string('gov')->value()) {
            $q->where('governorate', $gov);
        }
        if ($channel = $request->integer('channel')) {
            $q->where('channel_id', $channel);
        }
        if ($sub = $request->string('sub')->value()) {
            $q->where('sub_channel', $sub);
        }
        // ⚠️ "فيه عقد" = عقد سارٍ خاص بالعميل **أو موروث من سلسلته**.
        // لو نسينا عقد السلسلة، 44 فرع Circle K هيظهروا "من غير عقد"
        // وهما فعلاً متغطيين بعقد TMT.
        $liveContract = $this->liveContractScope();
        $hasAny = fn ($q) => $q->where(fn ($w) => $w
            ->whereHas('contract', $liveContract)
            ->orWhereHas('group.contract', $liveContract));
        $noLive = fn ($q) => $q->whereDoesntHave('contract', $liveContract)
            ->whereDoesntHave('group.contract', $liveContract);

        // ═══ حالة التعاقد التلاتة (بلاغ المالك ١٥/٨) ═══
        //
        // ⚠️ **«منتهي» كان مندمج في «بدون عقد».** الفلتر القديم كان
        // `yes` / `no` بس، و`no` بترجّع اللي عقده خلص مع اللي عمره
        // ما تعاقد — فالعميل اللي محتاج تجديد بيضيع وسط 400 عميل
        // مالهمش عقد أصلاً. `expired` = مفيش عقد سارٍ **ومع ذلك فيه
        // صف عقد**، و`no` = مفيش صف عقد خالص.
        $contractFilter = $request->string('contract')->value();

        if ($contractFilter === 'yes') {
            $hasAny($q);
        } elseif ($contractFilter === 'expired') {
            $noLive($q)->where(fn ($w) => $w
                ->whereHas('contract')
                ->orWhereHas('group.contract'));
        } elseif ($contractFilter === 'no') {
            $q->whereDoesntHave('contract')->whereDoesntHave('group.contract');
        }

        // ═══ الخصم ومصدره ═══
        //
        // ⚠️ **نفس ترتيب `Client::effectiveDiscount()` بالحرف**
        // (ترتيب ١٨/٨/٢٠٢٦): عقده الشخصي ← خصمه الخاص ← عقد السلسلة.
        // لو الفلتر حكم بترتيب تاني، الشاشة بتوري عميل في نتيجة
        // «بدون خصم» والعمود جنبه بيقول 50%.
        $contractDisc = fn ($c) => $liveContract($c)->where('discount', '>', 0);
        $noContractDisc = fn ($q) => $q->whereDoesntHave('contract', $contractDisc)
            ->whereDoesntHave('group.contract', $contractDisc);
        $discFilter = $request->string('disc')->value();

        if ($discFilter === 'yes') {
            $q->where(fn ($w) => $w->where('discount', '>', 0)
                ->orWhereHas('contract', $contractDisc)
                ->orWhereHas('group.contract', $contractDisc));
        } elseif ($discFilter === 'no') {
            $noContractDisc($q)->where(fn ($w) => $w
                ->whereNull('discount')->orWhere('discount', '<=', 0));
        } elseif ($discFilter === 'custom') {
            // خصم خاص فقط = مكتوب على العميل ومفيش **عقد شخصي** سارٍ
            // بيغطيه. عقد السلسلة مش شرط — خصم العميل بقى بيغلبه.
            $q->whereDoesntHave('contract', $contractDisc)
                ->where('discount', '>', 0);
        }

        // ═══ مدير القناة (١٥ أغسطس ٢٠٢٦) ═══
        //
        // ⚠️ **مالوش لازمة يتحرس هنا** — `Client::visibleTo` فوق أصلاً
        // بتقفل المدير على `manager_id` بتاعه، فأي قيمة تانية بترجّع
        // صفر صفوف. الحارس الحقيقي هناك مش في الفلتر.
        $managerFilter = $request->string('manager')->value();

        if ($managerFilter === 'none') {
            $q->whereNull('manager_id');
        } elseif (ctype_digit($managerFilter)) {
            $q->where('manager_id', (int) $managerFilter);
        }

        // ⚠️ «بدون مندوب أساسي» غير «بدون مدير قناة»: المندوب بيتغيّر
        // مع خط السير، والمدير هو المسؤول التجاري. عميل من غير مندوب
        // مافيش حد بيزوره، وعميل من غير مدير مافيش حد بيتحاسب عليه.
        if ($request->string('flag')->value() === 'norep') {
            $q->whereNull('rep_id');
        }

        // ═══ KPIs بمعنى (قرار المالك 2026-08-05): بدل كروت التصنيف
        // التجاري اللي محدش فاهمها — كام عميل، كام سلسلة، كام في كل
        // قناة، ومين عليه فلوس ومين ليه. كلها بنفس سكوب الفرع والمدير.
        $scoped = fn () => Client::visibleTo(\App\Models\Branch::scope(Client::query()));

        $debt = $scoped()->where('balance', '>', 0.009)
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(balance), 0) as s')->first();
        $credit = $scoped()->where('balance', '<', -0.009)
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(balance), 0) as s')->first();

        // ⚠️ **السورت من السيرفر مش الجافاسكريبت** (2026-08-06) —
        // القايمة paginated، وسورت الصفحة الحالية بس بيوهم إن أعلى
        // 40 هم أعلى السيستم. القايمة البيضا هي الأعمدة الحقيقية بس.
        $sortable = ['name', 'code', 'status', 'category', 'purchases', 'collections',
            'returns', 'balance', 'discount', 'last_payment_at'];
        $sort = $request->string('sort')->value();
        $sort = in_array($sort, $sortable, true) ? $sort : 'purchases';
        $dir = $request->string('dir')->value() === 'asc' ? 'asc' : 'desc';

        // كام سلسلة وكام مستقل في كل قناة — الرقم الشامل على الكروت
        $chainsByChannel = $scoped()->whereNotNull('group_id')
            ->selectRaw('channel_id, COUNT(DISTINCT group_id) as n')
            ->groupBy('channel_id')->pluck('n', 'channel_id')->all();
        $indepByChannel = $scoped()->whereNull('group_id')
            ->selectRaw('channel_id, COUNT(*) as n')
            ->groupBy('channel_id')->pluck('n', 'channel_id')->all();

        // ═══ كروت الحالة التجارية (١٥ أغسطس ٢٠٢٦) ═══
        //
        // ⚠️ **نفس نطاق باقي الكروت** (`$scoped()` من غير فلاتر الشاشة)
        // — الدوكترين بتقول صف الـKPIs مايخلطش نطاقين. الكارت لينك
        // بيحط الفلتر، فاللي عايز الرقم المفلتر بيدوس عليه.
        $liveContractN = $hasAny($scoped())->count();
        $discountedN = $scoped()->where(fn ($w) => $w->where('discount', '>', 0)
            ->orWhereHas('contract', $contractDisc)
            ->orWhereHas('group.contract', $contractDisc))->count();
        $noManagerN = $scoped()->whereNull('manager_id')->count();

        return view('erp.clients', [
            // ⚠️ فورم الإضافة اتنقل لصفحة مستقلة (`erp.clients.new`)،
            // فالقايمة دي مابقتش محتاجة الفروع والسلاسل والمناديب.
            // سيبانهم هنا كان بيحمّل 4 كويريز في كل صفحة من غير ما
            // حد يستخدمهم.
            // ⚠️ ترتيب ثانوي بالـid — من غيره الصفوف المتساوية بتتنطط
            // بين الصفحات والعميل بيظهر مرتين أو ولا مرة.
            'clients' => $q->with('channel')->orderBy($sort, $dir)->orderBy('id')
                ->paginate(40)->withQueryString(),
            'sort' => $sort,
            'dir' => $dir,
            'chainsByChannel' => $chainsByChannel,
            'indepByChannel' => $indepByChannel,
            'kpi' => [
                'chains' => $scoped()->whereNotNull('group_id')->distinct()->count('group_id'),
                'debt_n' => (int) $debt->n,
                'debt_sum' => (float) $debt->s,
                'credit_n' => (int) $credit->n,
                'credit_sum' => abs((float) $credit->s),
                'live_contract' => $liveContractN,
                'discounted' => $discountedN,
                'no_manager' => $noManagerN,
            ],
            // ⚠️ **المدير بيشوف نفسه بس.** القايمة دي بتتعرض في فلتر،
            // وعرض أسماء مديرين تانيين لمدير معناه كشف هيكل فريق مش
            // بتاعه — حتى لو الفلترة نفسها مش هترجّعله صفوفهم.
            'managerOptions' => $request->user()?->role === 'manager'
                ? User::whereKey($request->user()->id)->get()
                : \App\Models\Branch::scope(User::query(), $request->user())
                    ->whereIn('role', User::ASSIGNABLE_MANAGER_ROLES)
                    ->where('active', true)->orderBy('name')->get(),
            'zones' => Zone::orderBy('code')->get(),
            'channels' => \App\Models\Channel::orderBy('id')->get(),
            'catCounts' => Client::visibleTo(Client::query())->selectRaw('category, COUNT(*) as n')
                ->groupBy('category')->pluck('n', 'category')->all(),
            'channelCounts' => Client::visibleTo(Client::query())->selectRaw('channel_id, COUNT(*) as n')
                ->groupBy('channel_id')->pluck('n', 'channel_id')->all(),
            // ⚠️ أي مفتاح جديد هنا لازم يكون له `<select>` في الفيو —
            // فلتر بيتقرا ومالوش خانة معناه رابط شغّال ومحدش يعرف يلغيه.
            'filters' => $request->only(['q', 'cat', 'zone', 'gov', 'contract', 'channel',
                'sub', 'status', 'manager', 'disc', 'flag']),
            // ⚠️ بنفس سكوب الفرع بتاع القايمة — عداد بيقول 455 وقايمة
            // بتوري 80 بيخلّي مدير الفرع يفتكر في حاجة مخفية عنه.
            'statusCounts' => Client::visibleTo(\App\Models\Branch::scope(Client::query()))
                ->selectRaw('status, COUNT(*) as n')
                ->groupBy('status')->pluck('n', 'status')->all(),
        ]);
    }

    /**
     * صفحة تعريف عميل جديد — الفلو على 3 مراحل.
     *
     * ⚠️ صفحة مستقلة مش مودال. الفلو فيه رفع ملف وبنود بتتفتح
     * وتتقفل، والمودال بارتفاع ثابت كان بيخلّي نص الفورم تحت الشاشة
     * والمستخدم يحفظ وهو ماشافش نص الحقول.
     */
    public function newClient(Request $request)
    {
        return view('erp.client_form', $this->clientFormData($request, null));
    }

    /**
     * فرع جديد بنفس شروط فرع موجود.
     *
     * ⚠️ **بنسخ الشروط مش الأرقام.** الخصم والقناة والعقد وبنوده
     * بيتنسخوا؛ المشتريات والتحصيلات والرصيد لأ. لو اتنسخوا، الفرع
     * الجديد بيفتح وعليه مديونية فرع تاني.
     */
    public function cloneClient(Request $request, Client $client)
    {
        abort_unless($request->user()->canSeeBranch($client->branch_id), 403);
        abort_unless($client->visibleBy($request->user()), 403);   // سكوب التشانل مانجر 2026-08-05

        $client->load(['contract.contractClauses', 'group']);

        return view('erp.client_form', $this->clientFormData($request, $client));
    }

    /**
     * تعديل العميل — **نفس ويزارد الإنشاء**.
     *
     * ⚠️ **كان مودال بحقول قليلة.** كارت العميل فيه زرار «تعديل»
     * بيفتح مودال فيه الاسم والعنوان والقناة وبس — يعني العقد وبنوده
     * والخصومات والتسعير والضريبة، اللي هي أهم حاجة في العميل، مالهاش
     * أي واجهة تعديل. اللي عايز يظبط نسبة خصم كان لازم يمسح العميل
     * ويعمله من تاني.
     *
     * ⚠️ **نفس الفيو مش نسخة منه.** الويزارد فيه فاليديشن وترتيب
     * وبنود عقد ومنطق إظهار وإخفاء؛ نسخة تانية معناها إن أي حقل جديد
     * لازم يتضاف مرتين، والمرة اللي بتتنسى بتخلّي الحقل يتحفظ من
     * شاشة ومايتحفظش من التانية.
     */
    public function editClient(Request $request, Client $client)
    {
        abort_unless($request->user()->canSeeBranch($client->branch_id), 403);
        abort_unless($client->visibleBy($request->user()), 403);   // سكوب التشانل مانجر 2026-08-05

        // ⚠️ `group.contract` لازم — الفيو بيسأل عليه عشان يقول
        // «العقد جاي من السلسلة» بدل ما يوري بلوك فاضي. و`manager`
        // عشان يفضل في الدروب داون حتى لو اتوقف.
        $client->load(['contract.contractClauses', 'group.contract', 'manager', 'zone', 'channel']);

        // ⚠️ **`array_merge` مش `+`.** المعامل `+` بيسيب قيمة الشمال
        // للمفتاح المكرر — و`clientFormData` بتحط `editing => false`،
        // فالتجاوز كان بيتبلع في صمت والشاشة تفضل «عميل جديد».
        return view('erp.client_form', array_merge(
            $this->clientFormData($request, $client),
            // العلم ده هو اللي بيخلّي الفورم يبعت `PUT` على العميل بدل
            // `POST` بعميل جديد. من غيره «تعديل» كان هيعمل نسخة.
            ['editing' => true],
        ));
    }

    /**
     * منطقة جديدة من جوه فورم العميل — بترجع JSON.
     *
     * ⚠️ **مالهاش صفحة ومابتعملش redirect.** المستخدم واقف في نص فورم
     * من 3 مراحل وكاتب نص الحقول. أي ريفرش هنا معناه إن اللي كتبه
     * يضيع ويبدأ من الأول — وده اللي كان بيخلّيه يحط العميل في منطقة
     * غلط بدل ما يعمل منطقته.
     */
    public function quickZone(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            // ⚠️ **`nullable` مش `required` (١٣ أغسطس ٢٠٢٦).** الخانة
            // دي جوه بوكس «منطقة جديدة» اللي بيتفتح **في نص فورم
            // العميل** — مالهاش `data-req` ولا نجمة، والجافاسكربت
            // بيتحقق من الاسم العربي بس. يعني السيرفر كان بيرفض
            // بـ422 والبوكس بيعرض «فشل» من غير ما يقول الخانة —
            // والمستخدم واقف في نص فورم من ٣ مراحل مش عارف يكمّل.
            //
            // ⚠️ الرجوع مضمون: `displayName()` بترجّع العربي لما
            // الإنجليزي فاضي، والاسم بيتكمّل من شاشة «المناطق
            // والمحافظات». التشدّد مكانه الشاشة دي مش الإضافة السريعة.
            'name_en' => ['nullable', 'string', 'max:190'],
            'governorate' => ['nullable', Governorates::rule()],
        ]);

        // ⚠️ الكود بيتولّد — المستخدم في نص فورم تاني ومش هيقف يفكّر
        // في كود منطقة. والتكرار بيتفادى بالعدّاد مش بالرمي في وشه.
        $base = 'Z'.str_pad((string) (Zone::count() + 1), 2, '0', STR_PAD_LEFT);
        $code = $base;
        $n = 2;

        while (Zone::where('code', $code)->exists()) {
            $code = $base.'-'.$n++;
        }

        $zone = Zone::create($data + [
            'code' => $code,
            // ⚠️ المنطقة بتتولد في فرع اللي عملها. مدير المعادي
            // مالوش يعمل منطقة مركزية بتبان لكل الفروع.
            'branch_id' => $request->user()->seesAllBranches() ? null : $request->user()->branch_id,
            'active' => true,
        ]);

        return response()->json([
            'id' => $zone->id,
            'name' => $zone->displayName(),
            'governorate' => $zone->governorate,
        ], 201);
    }

    /**
     * سلسلة جديدة من جوه فورم العميل — بترجع JSON.
     *
     * ⚠️ **الخصم والقناة مش هنا.** السلسلة اللي بتتعمل من هنا مجرد
     * وعاء بيجمّع الفروع عشان نشوف إجماليهم. شروطها التجارية بتتظبط
     * من شاشة السلاسل بعدين. لو خلّينا الفورم ده يكتب خصم، مستعجل
     * بيحط رقم عشوائي وبيتطبق على كل فروع السلسلة.
     */
    public function quickGroup(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            // ⚠️ **`nullable` مش `required` (١٣ أغسطس ٢٠٢٦)** — نفس سبب
            // `quickZone` بالحرف: بوكس «سلسلة جديدة» جوه فورم العميل
            // خانته الإنجليزي مالهاش `data-req` ولا نجمة، والرفض من
            // السيرفر كان بيوقف المستخدم في نص الفورم برسالة «فشل»
            // مالهاش تفصيل. `displayName()` بترجع للعربي، والاسم
            // بيتكمّل من شاشة السلاسل.
            'name_en' => ['nullable', 'string', 'max:190'],
        ]);

        // ⚠️ نفس منطق `GroupController::store` — الكود من الاسم، وبيتزوّد
        // رقم لو اتكرر بدل ما يقع على قيد التفرد في وش المستخدم.
        $base = \App\Models\ClientGroup::nextCode($data['name']);
        $code = $base;
        $n = 2;

        while (\App\Models\ClientGroup::where('code', $code)->exists()) {
            $code = mb_substr($base, 0, 34).'-'.$n++;
        }

        // ⚠️ **مفيش `uses_group_discount`** — العمود اتشال في مايجريشن
        // `000028`. السلسلة تجميعة عرض بس، والخصم على الفرع نفسه.
        $group = \App\Models\ClientGroup::create($data + [
            'code' => $code,
            'active' => true,
        ]);

        return response()->json([
            'id' => $group->id,
            'name' => $group->displayName(),
        ], 201);
    }

    /**
     * قراءة الإحداثيات من لينك اللوكيشن.
     *
     * ⚠️ الرابط المختصر (`maps.app.goo.gl`) مافيهوش إحداثيات — لازم
     * يتفك من السيرفر. والدومين بيتفحص جوه `MapLink` قبل أي اتصال،
     * وإلا اليوزر بيلزق رابط داخلي والسيرفر يروح يطلبه.
     */
    public function resolveLocation(Request $request)
    {
        $data = $request->validate([
            'url' => ['required', 'string', 'max:500', 'regex:#^https?://#i'],
        ]);

        $point = \App\Support\MapLink::resolve($data['url']);

        return $point === null
            ? response()->json(['error' => __('geo.detect_failed')], 422)
            : response()->json($point);
    }

    /**
     * المديرين اللي ينفع يتحطوا كمسؤولين تجاريين عن عميل.
     *
     * ⚠️ الأدمن مشمول — في شركة بالحجم ده هو فعلاً بيمسك حسابات
     * بنفسه، واستبعاده كان بيخلّي أكبر العملاء من غير مسؤول.
     */
    private function managerOptions(Request $request)
    {
        return \App\Models\Branch::scope(User::query(), $request->user())
            ->whereIn('role', User::ASSIGNABLE_MANAGER_ROLES)
            ->where('active', true)
            ->orderBy('name')
            ->get();
    }

    /** الداتا المشتركة لفورم العميل — إضافة أو استنساخ */
    private function clientFormData(Request $request, ?Client $src): array
    {
        $contract = $src?->contract;

        return [
            'src' => $src,
            'ct' => $contract,
            // ⚠️ الافتراضي `false` — الفيو بيستخدمه من غير `??`،
            // وأي مسار بينسى يبعته كان هيرمي «Undefined variable».
            'editing' => false,
            // ═══ «انقل من فرع زيه» (طلب المالك ١٨/٨/٢٠٢٦) ═══
            //
            // فروع نفس السلسلة بشروطهم التجارية — الفيو بيعرض دروب
            // داون يملا الفورم من الفرع المظبوط، والمالك يعدّل الاسم
            // ويحفظ حفظة واحدة. **الهوية مش بتتنقل**: اسم/منطقة/عنوان/
            // محافظة/تليفون/لوكيشن/رقم ضريبي بتوع الفرع نفسه.
            // ⚠️ visibleTo — نفس دوكترين أي عرض عملاء.
            'siblings' => $src && $src->group_id
                ? Client::visibleTo(Client::query(), $request->user())
                    ->where('group_id', $src->group_id)
                    ->where('id', '!=', $src->id)
                    ->where('status', '!=', 'rejected')
                    ->orderBy('name')
                    ->get(['id', 'name', 'name_en', 'channel_id', 'sub_channel',
                        'division', 'branch_id', 'manager_id', 'payment_terms',
                        'payment_days', 'payment_days_from', 'price_list_id',
                        'discount', 'category', 'taxable', 'tax_rate', 'tax_cycle',
                        'eta_type'])
                : collect(),
            'presets' => ContractIntake::currentPresets($contract),
            'governorates' => Governorates::options(),
            'branches' => \App\Models\Branch::scope(
                \App\Models\Branch::where('active', true), $request->user(), 'id',
            )->orderBy('code')->get(),
            // ⚠️ المناطق بتتبعت مع محافظة كل واحدة والفلترة بتحصل في
            // المتصفح. لو فلترنا بالمحافظة في السيرفر، تغييرها كان
            // هيحتاج ريفرش والمستخدم يفقد اللي كتبه.
            // ⚠️ بس **مسكوبة بالفرع** — مدير المعادي كان بيشوف كل مناطق
            // الشركة بأسمائها ويقدر يحط عميله في منطقة فرع تاني.
            'zones' => \App\Models\Branch::scope(Zone::query(), $request->user())
                ->orderBy('code')->get(['id', 'code', 'name', 'name_en', 'governorate']),
            'channels' => \App\Models\Channel::orderBy('id')->get(),
            // ⚠️ **قوايم الأسعار الحقيقية من الداتابيز** (2026-08-07).
            // الفورم كان بيعرض «قديم/جديد» متبتّتين، فأي قايمة جديدة
            // بيتعملها المستخدم من شاشة التسعير ماكانش فيه طريقة
            // يسكّن عليها عميل — والسيستم متبني على قوايم مفتوحة العدد.
            // القايمة الموقوفة بتتضاف لو العميل عليها، وإلا الـselect
            // بيبعت فاضي وأول حفظة بتفكّه منها في صمت.
            'priceLists' => (function () use ($src) {
                $lists = \App\Models\PriceList::where('active', true)->orderBy('id')->get();

                return $src?->priceListRow && ! $lists->contains('id', $src->price_list_id)
                    ? $lists->push($src->priceListRow)
                    : $lists;
            })(),
            // ⚠️ **سلسلة العميل بتتضاف حتى لو موقوفة.** القايمة بتعرض
            // المفعّل بس، فالعميل اللي سلسلته اتوقفت مافيش أوبشن
            // بتطابقه — الـselect بيبعت فاضي وأول حفظ بيفك ربطه
            // بالسلسلة في صمت، ومعاه عقد السلسلة وخصمه.
            'groups' => (function () use ($src) {
                $groups = \App\Models\ClientGroup::where('active', true)->orderBy('name')->get();

                return $src?->group && ! $groups->contains('id', $src->group_id)
                    ? $groups->concat([$src->group])
                    : $groups;
            })(),
            // ⚠️ المديرين مسكوبين كمان — القايمة بتكشف أسماء فريق فرع
            // تاني، و`exists:users,id` مابيسألش عن الفرع فالتخصيص كان
            // بيعدّي.
            // ⚠️ نفس السبب: مدير الحساب اللي اتوقف أو اتغيّر رولّه
            // مش في `managerOptions()`، وبيتصفّر عند أول حفظ.
            'managers' => (function () use ($request, $src) {
                $managers = $this->managerOptions($request);

                return $src?->manager && ! $managers->contains('id', $src->manager_id)
                    ? $managers->concat([$src->manager])
                    : $managers;
            })(),
        ];
    }

    public function client(Request $request, Client $client)
    {
        // ⚠️ **فلترة القايمة بتخبّي الصف عن العين مش عن الراوت.**
        // أي حد بيعرف الـ id بيفتح كارت أي عميل بكشف حسابه كله.
        abort_unless($request->user()->canSeeBranch($client->branch_id), 403);
        // ⚠️ ونفس الكلام لسكوب التشانل مانجر — عملاءه بس (2026-08-05)
        abort_unless($client->visibleBy($request->user()), 403);

        // ⚠️ **`visits.user` اتشالت من التحميل المسبق (١٥ أغسطس ٢٠٢٦)**
        // — كانت بتحمّل **كل** زيارات العميل من أول يوم والفيو
        // مابيستخدمهاش أصلاً. كارت «آخر الزيارات» تحت بياخد آخر ١٠ بس.
        $client->load([
            'zone', 'channel', 'rep',
            'contract.contractClauses', 'group.contract.contractClauses',
            'invoices.items.product', 'invoices.items.batch',
        ]);

        // ⚠️ العقد الفعّال ممكن يكون موروث من السلسلة — بنحسبه هنا مرة واحدة
        // بدل ما الفيو ينادي liveContract() في كل سطر.
        $contract = $client->liveContract();

        // آخر ١٠ زيارات على العميل ده — أي مندوب، بأحدث تشيك إن
        $recentVisits = \App\Models\Visit::where('client_id', $client->id)
            ->with('user')
            ->orderByDesc('checked_in_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        return view('erp.client', [
            // ⚠️ مسكوبة — القايمة دي في المودال وبتكشف كود واسم كل فرع
            // لمدير فرع تاني حتى لو `guardBranch` بيمنعه يحفظ عليه.
            'branches' => \App\Models\Branch::scope(
                \App\Models\Branch::where('active', true), $request->user(), 'id',
            )->orderBy('code')->get(),
            'c' => $client,
            'ct' => $contract,
            // ⚠️ **عقد العميل نفسه** — غير `$ct` اللي ممكن يكون موروث من
            // السلسلة. فورم التعديل بيكتب على عقد العميل، ولو اتعبّى من
            // عقد السلسلة أول حفظ بيعمل عقد خاص فاضي بيحجب عقد السلسلة
            // والعميل بيفقد خصمه كله في صمت.
            'own' => $client->contract,
            'governorates' => Governorates::options(),
            'presets' => ContractIntake::currentPresets($client->contract),
            // مستحقات لسه ماترحّلتش — بتظهر كزرار على كارت العقد
            'dueCount' => $client->dues()->due()->count(),
            'dueAmount' => (float) $client->dues()->due()->sum('amount'),
            'aging' => $client->aging(),
            // ⚠️ **غير الأعمار.** الأعمار بتقول الفلوس بقالها كام يوم،
            // ودي بتقول كام منها عدّى ميعاد سداده حسب شروط العقد.
            'overdue' => $client->overdue(),
            'split' => $client->familySplit(),
            'monthly' => $client->transactions()
                ->selectRaw("DATE_FORMAT(date, '%Y-%m') as m,
                             SUM(CASE WHEN kind = 'sale' THEN debit ELSE 0 END) as sales,
                             SUM(CASE WHEN kind = 'collection' THEN credit ELSE 0 END) as coll")
                ->groupBy('m')->orderBy('m')->get(),
            // ⚠️ **`reorder()` قبل الترتيب** (إصلاح 2026-08-08). علاقة
            // `transactions()` عليها `orderBy('date')` تصاعدي، وإضافة
            // `orderByDesc` عليها بتنتج `ORDER BY date ASC, date DESC`
            // — والأول هو اللي بيحكم. النتيجة إن كشف الحساب كان
            // بيفتح على أقدم حركة والصفحة الأولى فيها قيود سنة فاتت،
            // واللي بيدوّر على آخر تحصيل بيروح لآخر صفحة.
            'txns' => $client->transactions()->reorder()
                ->orderByDesc('date')->orderByDesc('id')->paginate(60),
            // ⚠️ مسكوبين بالفرع — نفس سبب `clientFormData()`: القوايم دي
            // بتكشف مناطق وفريق فرع تاني، و`exists:` مابيسألش عن الفرع
            // فالتخصيص ليهم كان بيعدّي.
            'zones' => \App\Models\Branch::scope(Zone::query(), $request->user())
                ->orderBy('code')->get(),
            'channels' => \App\Models\Channel::orderBy('id')->get(),
            'groups' => \App\Models\ClientGroup::where('active', true)->orderBy('name')->get(),
            'reps' => \App\Models\Branch::scope(User::query(), $request->user())
                ->whereIn('role', User::FIELD_ROLES)->where('active', true)
                ->orderBy('name')->get(),
            'managers' => $this->managerOptions($request),
            // تسعيرة العميل مطبّقة على الكتالوج — عشان يشوف بيدفع كام فعلاً
            'priced' => Product::where('active', true)->orderBy('code')->get()
                ->map(fn (Product $p) => [
                    'product' => $p,
                    'quote' => \App\Services\Pricing::quote($client, $p),
                ]),
            // ═══ آخر الزيارات (١٥ أغسطس ٢٠٢٦) ═══
            // ⚠️ بلاغ المالك: «مش شايف الزيارات اللي اتعملت». كارت
            // العميل هو أول مكان بيفتحه، فلازم يقول له: مين جه، امتى،
            // قعد قد إيه، وطلع من الزيارة إيه — وصور الرف لو فيه.
            // الناتج بيتجمّع بكويريز باتش (`VisitOutcomes`) مش صف صف.
            'visits' => $recentVisits,
            'visitOut' => \App\Support\VisitOutcomes::map($recentVisits->pluck('id')->all()),
        ]);
    }

    public function updateClient(Request $request, Client $client)
    {
        // ⚠️ نفس حارس كارت العميل — من غيره مدير فرع بيبعت PUT على
        // عميل فرع تاني ويغيّر خصمه، والفلترة في القايمة مابتوقفوش.
        abort_unless($request->user()->canSeeBranch($client->branch_id), 403);
        abort_unless($client->visibleBy($request->user()), 403);   // سكوب التشانل مانجر 2026-08-05

        $data = $this->guardBranch($request, $request->validate($this->clientRules()), creating: false);
        $this->checkContractDuration($data);

        // ⚠️ **التعديل محتاج نفس الحارس** — تغيير اسم عميل لاسم عميل
        // تاني موجود بيعمل نفس التكرار بالظبط، والمسار ده كان مفتوح
        // خالص. `$client` بيتستثنى عشان «العميل مش تكرار لنفسه».
        if ($blocked = $this->dupeGuard($request, $data, $client)) {
            return $blocked;
        }

        DB::transaction(function () use ($data, $request, $client) {
            $client->update($this->clientFields($data));
            $this->syncContract($client, $data, $request);

            // التعديل بيجرّ التغطية وراه (٢١/٨) — غيّرت منطقته أو
            // مندوبه؟ المنطقة بتتفعّل وبتتعلّم للفريق أوتوماتيك
            \App\Services\Coverage::sync($client->fresh());
        });

        // ⚠️ **`back()` كان بيرجّع للويزارد نفسه.** المستخدم بيحفظ
        // ويلاقي نفس الفورم قدامه فمش عارف إتحفظ ولا لأ. الكارت هو
        // المكان اللي بيشوف فيه النتيجة.
        return redirect()->route('erp.clients.show', $client)
            ->with('ok', __('flash.client_saved'));
    }

    /**
     * رصيد أول المدة — بداية الشغل على السيستم.
     *
     * ⚠️ القيد ده **بيستبدل** أي رصيد افتتاحي قديم للعميل. لو اتزوّد
     * بدل ما يتستبدل، أول تصحيح لرقم غلط بيخلّي الرصيد ضعف الحقيقي.
     *
     * ⚠️ التاريخ مهم مش شكلي: هو `first_activity_at` للعميل، ومنه
     * بيتحسب ميعاد السداد لما العقد بيعد «من أول توريد».
     */
    public function openingBalance(Request $request, Client $client)
    {
        abort_unless($request->user()->canSeeBranch($client->branch_id), 403);
        abort_unless($client->visibleBy($request->user()), 403);   // سكوب التشانل مانجر 2026-08-05

        $data = $request->validate([
            // ⚠️ السالب مسموح ومقصود = رصيد دائن (العميل دافع مقدماً)
            'amount' => ['required', 'numeric', 'between:-99999999,99999999'],
            'date' => ['required', 'date', 'before_or_equal:today'],
            'memo' => ['nullable', 'string', 'max:190'],
        ]);

        $client->setOpeningBalance(
            (float) $data['amount'],
            $data['date'],
            $data['memo'] ?? null,
        );

        return back()->with('ok', __('flash.opening_saved'));
    }

    /**
     * حارس الفرع على الحفظ.
     *
     * ⚠️ الحارس اللي على `client()` بيحرس **القراءة**. من غير الحارس
     * ده، مدير المعادي بيبعت `branch_id` بتاع الجيزة في الفورم ويعمل
     * (أو ينقل) عميل لفرع مش بتاعه.
     *
     * ⚠️ في **الإنشاء** بس: الخانة الفاضية بتتحوّل لفرعه هو. الفاضي
     * معناه «مركزي بيبان لكل الفروع» — يعني مدير فرع بيقدر يعمل
     * عملاء الشركة كلها تشوفهم من غير ما يقصد.
     *
     * ⚠️ في **التعديل**: الفاضي بيتشال خالص ومابيتكتبش. لو حوّلناه
     * لفرعه، كل عميل مركزي قديم (وكلهم مركزيين — الشركة كانت فرع
     * واحد) كان بيتحوّل لفرع المعادي بمجرد إن مديره فتح الكارت وضغط
     * حفظ عشان يصلّح تليفون. العميل بيختفي من كل الفروع التانية ومن
     * التقارير المركزية، في صمت.
     */
    private function guardBranch(Request $request, array $data, bool $creating): array
    {
        $user = $request->user();

        if ($user->seesAllBranches()) {
            return $data;
        }

        $wanted = $data['branch_id'] ?? null;

        if ($wanted === null) {
            if ($creating) {
                $data['branch_id'] = $user->branch_id;
            } else {
                unset($data['branch_id']);
            }

            return $data;
        }

        abort_unless($user->canSeeBranch((int) $wanted), 403);

        return $data;
    }

    /**
     * قواعد فورم العميل — **مصدر واحد** للإضافة والتعديل والاستنساخ.
     *
     * ⚠️ القواعد دي كانت مكتوبة مرتين بالنص. أي حقل جديد كان بيتضاف
     * في واحدة وينتسي في التانية، فالحقل بيتحفظ من شاشة ويتجاهل من
     * التانية من غير أي رسالة خطأ.
     */
    /**
     * المدة والتواريخ متطابقين؟
     *
     * ⚠️ **مش قاعدة `Rule` عشان بتقارن تلات حقول مع بعض.** قواعد
     * لارافيل بتشوف حقل واحد، والحكم هنا: «سنة» بتاريخين شهرين
     * بينهم = غلط. الغلط ده مابيتمسكش بأي قاعدة مفردة، وبيعدّي
     * ويخلّي تنبيه التجديد يرنّ بعد شهرين والخصومات تقف وهي المفروض
     * شغالة سنة.
     *
     * @param  array<string, mixed>  $data
     */
    private function checkContractDuration(array $data): void
    {
        if ((int) ($data['has_contract'] ?? 0) !== 1) {
            return;
        }

        $err = Contract::checkDuration(
            $data['contract_duration'] ?? null,
            $data['contract_starts_at'] ?? null,
            $data['contract_ends_at'] ?? null,
        );

        if ($err !== null) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                // ⚠️ الرسالة بتتعلّق على **المدة** مش على التاريخ:
                // دي الخانة اللي المستخدم بيقرر منها، والتاريخ نتيجة.
                'contract_duration' => $err,
            ]);
        }
    }

    private function clientRules(): array
    {
        return [
            // ⚠️ الإنجليزي هو الأساس في تعريف العميل — بس فاضل
            // `nullable` هنا عشان الـ103 عميل القدام اتعملوا قبل
            // القاعدة دي، وأي حفظ لواحد فيهم كان هيترفض.
            // ⚠️ **إجباري على السيرفر كمان** (2026-08-08). الفورم عليه
            // `data-req` ونجمة، والسيرفر كان `nullable` — يعني المتصفح
            // بيمنع واللي بيبعت من غير المتصفح (استيراد، tinker، فورم
            // معدّل) بيعدّي. والإنجليزي هو الافتراضي في كل الشاشات
            // والمطبوعات، فعميل من غيره اسمه بيطلع فاضي قدام العميل.
            'name_en' => ['required', 'string', 'max:190'],
            'name' => ['required', 'string', 'max:190'],
            'phone' => ['nullable', 'string', 'max:30'],
            'governorate' => ['nullable', Governorates::rule()],
            'address' => ['nullable', 'string', 'max:190'],
            // العنوان العربي — بقى في الفورم جنب الإنجليزي (٢٠/٨)
            'address_ar' => ['nullable', 'string', 'max:190'],
            // ⚠️ `url` مش كفاية — بيقبل `javascript:` في بعض النسخ.
            // الحقل ده بيتحط في `href` في كارت العميل.
            'location_url' => ['nullable', 'string', 'max:500', 'regex:#^https?://#i'],
            'zone_id' => ['nullable', 'exists:zones,id'],
            // ⚠️ المندوب **مش** في الفورم ده. تخصيصه بيحصل من شاشة
            // توزيع المناطق (`JourneyController`) لأنه بيتغيّر مع خط
            // السير. سيبه هنا كان بيخلّي كل حفظ لبيانات العميل يقدر
            // يعيد توزيعه من غير ما التوزيع يعرف.
            'manager_id' => ['nullable', 'exists:users,id'],
            'contacts' => ['nullable', 'array', 'max:12'],
            'contacts.*.name' => ['nullable', 'string', 'max:120'],
            'contacts.*.role' => ['nullable', 'string', 'max:120'],
            'contacts.*.phone' => ['nullable', 'string', 'max:30'],
            // ⚠️ **إجباري** — القناة بتحدد التسعير والافتراضي بتاع
            // كاش/آجل ومين بيخدم العميل. عميل من غير قناة بياخد
            // «آجل» افتراضياً ومابيظهرش لأي مندوب — يعني بيتخلق
            // ويختفي، وده اللي كان بيحصل فعلاً.
            'channel_id' => ['required', 'exists:channels,id'],
            'group_id' => ['nullable', 'exists:client_groups,id'],
            'sub_channel' => ['nullable', 'in:chain,convenience'],
            // الديفيجن التجاري (١٧/٨) — القايمة من مصدرها الوحيد
            'division' => ['nullable', \App\Support\Divisions::rule()],
            // ⚠️ **مش في فورم العميل الجديد.** التصنيف نتيجة سلوك مش
            // مدخل: بيدفع في مواعيده ولا لأ، بيكبر ولا لأ. تحديده وقت
            // التعريف تخمين بيتحوّل لحقيقة في الشاشة — عميل يتعلّم
            // «تحصيل فوري» من يومه الأول ويتقفل عليه الآجل من غير
            // أي سبب. بيتظبط من كارت العميل بعد أول تعاملات.
            // ⚠️ من الثابت مباشرة — القايمة كانت مكتوبة بالنص هنا وفي
            // الفيو، فإضافة تصنيف جديد كانت بتوري أوبشن الفاليديشن
            // يرفضها.
            'category' => ['nullable', Rule::in(array_keys(Client::CATEGORIES))],
            // كاش/آجل/الاتنين — فاضي = حسب القناة، و`danger` كاش إجباري.
            // ⚠️ من الثابت مش مكتوبة بالنص — `both` اتضافت 2026-08-08
            // والقايمة المكتوبة بالإيد كانت هترفضها في صمت.
            'payment_terms' => ['nullable', Rule::in(Client::PAY_TERMS)],
            // ═══ سياسة المرتجع (قرار المالك ٨/٨/٢٠٢٦) ═══
            // ⚠️ **مصفوفة مش قيمة واحدة** — العميل ممكن يكون مسموح
            // له بأكتر من طريقة، والمندوب بيختار من المسموح وقت
            // المرتجع. فاضية = ارجع للافتراضي حسب شروط الدفع
            // (`Client::returnPolicies()`).
            'return_policies' => ['nullable', 'array'],
            'return_policies.*' => [Rule::in(Client::RETURN_POLICIES)],
            // ⚠️ **`nullable` مش `required` حتى للآجل.** فيه عملاء آجل
            // بالفعل من غير مدة متفق عليها، وإجبار رقم هنا كان معناه
            // إن اللي بيعدّل أي حاجة تانية على العميل ده يتحبس لحد ما
            // يخترع مدة — فيكتب 30 عشوائي وتبقى داتا غلط بالورقة.
            'payment_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            // ⚠️ **`required_with`** — رقم أيام من غير نقطة بداية مالوش
            // معنى، وكان بيروح لافتراضي مختلف في العميل عن العقد فنفس
            // الداتا تطلع تاريخين. نفس القاعدة بتاعة العقد بالظبط.
            'payment_days_from' => ['nullable', 'required_with:payment_days', Rule::in(Contract::DAYS_FROM)],
            'discount' => ['required', 'numeric', 'min:0', 'max:100'],
            // ⚠️ **قائمة السعر بقت بالـid مش بالنص** (2026-08-07).
            // الفاتورة بتتحاسب من `price_list_id` (عبر `Pricing::listRowFor`)،
            // والفورم كان بيحفظ عمود `price_list` النصي بس — فالعميل
            // الجديد كان `price_list_id` بتاعه null والفاتورة تاخد
            // القايمة الافتراضية بدل اللي المستخدم اختارها.
            // إجبارية: البيع من غير قايمة معتمدة = سعر محدش أقرّه.
            'price_list_id' => ['required', 'exists:price_lists,id'],
            'taxable' => ['nullable', 'boolean'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'tax_id' => ['nullable', 'string', 'max:40'],
            'tax_cycle' => ['nullable', 'in:'.implode(',', Client::TAX_CYCLES)],
            'eta_type' => ['nullable', 'in:B,P'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'notes' => ['nullable', 'string'],

            // ===== العقد — اختياري، ولو اتعلّم بيتعمل عقد =====
            'has_contract' => ['nullable', 'boolean'],
            // ⚠️ مفتاح ثابت مش نص حر: النص الحر كان بيتخزن بلغة الواجهة
            // وقت الإنشاء، فعقد اتعمل بالإنجليزي كان بيعرض "Agreement" في
            // الشاشة العربية.
            // ⚠️ **إجباري لما يكون فيه عقد.** العقد من غير نوع بيتحفظ
            // بـ«اتفاق» افتراضي — وبعد شهور محدش يعرف ده كان عقد توريد
            // ولا موزع معتمد. و`required_if` مش `required` عشان العميل
            // اللي مالوش عقد يعدّي عادي.
            'contract_type' => ['nullable', 'required_if:has_contract,1',
                'in:'.implode(',', array_keys(Contract::TYPE_KEYS))],
            'contract_payment_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            // ⚠️ الرقم من غير نقطة بداية مالوش معنى: 60 يوم من أول
            // توريد غير 60 يوم من كل فاتورة. لو فيه أيام سداد، لازم
            // نعرف بتتحسب منين.
            'contract_payment_days_from' => ['nullable',
                'required_with:contract_payment_days',
                'in:'.implode(',', Contract::DAYS_FROM)],
            // ⚠️ **المدة إجبارية مع العقد.** هي اللي بتحدد يعني إيه
            // التواريخ الفاضية: «مفتوح المدة» ولا «حد نسي يملاها».
            // من غيرها تنبيه التجديد بيفضل ساكت أو بيرنّ في يوم
            // محدش قرره.
            'contract_duration' => ['nullable', 'required_if:has_contract,1',
                'in:'.implode(',', array_keys(Contract::DURATIONS))],
            // ⚠️ **`required_with:contract_ends_at` مش `required_if`
            // على العقد.** «تعامل بالطلب» عقد بجد بس مالوش تواريخ
            // خالص (الحقول بتتخبّى)، فإجبار تاريخ بداية على كل عقد
            // كان بيمنع النوع ده. لكن نهاية من غير بداية = مدة مالهاش
            // أول، و`after_or_equal` بتاعت النهاية بتتقارن بلا شيء.
            'contract_starts_at' => ['nullable', 'required_with:contract_ends_at', 'date'],
            'contract_ends_at' => ['nullable', 'date', 'after_or_equal:contract_starts_at'],
            'contract_note' => ['nullable', 'string'],
            'contract_clauses' => ['nullable', 'array'],
            'contract_clauses.*' => ['nullable', 'string', 'max:500'],
            // ⚠️ PDF أو صورة بس. رفع أي امتداد تاني معناه إن الراوت
            // اللي بيقدّم الملف هيبعته بنوع غلط والمتصفح ينزّله كملف
            // مجهول — أو أسوأ، سكريبت جوه مجلد فيه ملفات بتتقدّم.
            'contract_file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:8192'],
        ] + ContractIntake::rules();
    }

    /**
     * عميل جديد + عقده في خطوة واحدة.
     * لو has_contract متعلّم بيتعمل عقد ويتربط بالعميل فوراً،
     * والخصم بياخد أولويته من العقد بعد كده (شوف Client::effectiveDiscount).
     */
    public function storeClient(Request $request)
    {
        $data = $request->validate($this->clientRules());
        $this->checkContractDuration($data);

        // ⚠️ **حارس التكرار** — نفس منطق الاستيراد بالظبط (`Dupes`):
        // اسم مطبّع («المعادى ١» = «فرع المعادي 1») أو تليفون مسجل
        // لعميل تاني أو اسم قريب جداً جوّه نفس السلسلة/الزون.
        if ($blocked = $this->dupeGuard($request, $data, null)) {
            return $blocked;
        }

        $data = $this->guardBranch($request, $data, creating: true);

        // ⚠️ **مدير بيعمل عميل كان بيضيّعه** (تدقيق ٨/٨/٢٠٢٦):
        // `manager_id` اختياري في الفورم وبلا افتراضي، و`Client::visibleTo`
        // بتفلتر المدير على `manager_id` — فالعميل كان بيختفي من شاشة
        // اللي عمله بالظبط في اللحظة اللي بيتحفظ فيها.
        if ($request->user()?->role === 'manager' && empty($data['manager_id'])) {
            $data['manager_id'] = $request->user()->id;
        }

        $client = DB::transaction(function () use ($data, $request) {
            $client = Client::create($this->clientFields($data) + [
                'code' => Client::nextCode(),
                'created_by' => $request->user()->id,
                'status' => 'active',
                // ⚠️ **`grow` مش `ok`.** العميل الجديد فرصة لسه مااتجرّبتش،
                // و`ok` معناها «منتظم» — حكم على سلوك لسه مافيش منه حاجة.
                // التصنيف الحقيقي بيتحدد بعد أول تعاملات.
                'category' => 'grow',
                'is_new' => true,
            ]);

            $this->syncContract($client, $data, $request);

            // ═══ الإضافة بتجرّ التغطية وراها (٢١/٨) ═══
            // «ضيفت عميل لمندوب فالمنطقة تنزل أوتوماتيك» — طلب المالك
            // بالنص. مفيش خطوة تعليم مناطق يدوية بعد كده.
            \App\Services\Coverage::sync($client);

            return $client;
        });

        return redirect()->route('erp.clients.show', $client)
            ->with('ok', __('flash.client_added'));
    }

    /**
     * ═══════════════════════════════════════════════════════════
     * حارس التكرار — بيوقف الحفظ، ومابيمنعوش
     * ═══════════════════════════════════════════════════════════
     *
     * ⚠️ **بيرجّع الفورم بتحذير، مش برفض نهائي** (قرار ١٥ أغسطس
     * ٢٠٢٦). الرفض القاطع القديم كان بيقف قدام حالة حقيقية: فرعين
     * لنفس السلسلة في نفس المول باسم واحد ورقم إدارة واحد. اللي
     * بيدخل الداتا ماكانش بيقدر يعرّف التاني، فكان بيغيّر الاسم
     * شوية («المعادي 2») عشان يلف حوالين الحارس — والنتيجة عميل
     * باسم غلط في كل مطبوعة.
     *
     * دلوقتي: الشاشة بتوري الشبيه بكوده ومنطقته ومندوبه ومديره وآخر
     * حركة، ولو المستخدم علّم «أنا متأكد إنه عميل مختلف» بيعدّي.
     * **عمر ما بيتخلق في صمت** — لازم قرار مكتوب.
     *
     * ⚠️ `confirm_duplicate` **مش في `clientRules()`** عن قصد: لو
     * دخل `$data` كان هيوصل لـ`clientFields()` ومنها لـ`create()`
     * على عمود مش موجود في الجدول. بيتقرا من الريكوست مباشرة كبوليان.
     */
    private function dupeGuard(Request $request, array $data, ?Client $ignore)
    {
        if ($request->boolean('confirm_duplicate')) {
            return null;
        }

        $hits = \App\Support\Dupes::matches([
            'name' => $data['name'] ?? null,
            'name_en' => $data['name_en'] ?? null,
            'phone' => $data['phone'] ?? null,
            'zone_id' => $data['zone_id'] ?? null,
            'group_id' => $data['group_id'] ?? null,
        ], $ignore?->id, $request->user());

        if ($hits === []) {
            return null;
        }

        // ⚠️ الخطأ متعلّق على **الاسم** حتى لو المطابقة بالتليفون —
        // دي أول خانة في الفورم واللي المستخدم بيبص لها، والبانل
        // الأصفر تحتها بيقول السبب الحقيقي لكل صف على حدة.
        return back()->withInput()
            ->with('dupes', $hits)
            ->withErrors(['name' => __('client.dup_blocked', ['count' => count($hits)])]);
    }

    /**
     * فحص حي أثناء الكتابة — `POST /erp/clients/check-duplicate`.
     *
     * ⚠️ **مالهاش صفحة ومابتعملش redirect** (نفس نمط `quickZone`).
     * المستخدم واقف في ويزارد من 3 مراحل؛ أي navigation هنا معناه
     * إن اللي كتبه يضيع. بترجّع JSON والفورم بيرسم بانل تحت الخانة.
     *
     * ⚠️ **بترجّع نفس نتيجة الحارس بالظبط** — لو الاتنين اختلفوا،
     * المستخدم بيشوف «مفيش تكرار» وهو بيكتب وبعدين الحفظ يترفض.
     */
    public function checkDuplicate(Request $request)
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:190'],
            'name_en' => ['nullable', 'string', 'max:190'],
            'phone' => ['nullable', 'string', 'max:30'],
            'zone_id' => ['nullable', 'integer'],
            'group_id' => ['nullable', 'integer'],
            'ignore_id' => ['nullable', 'integer'],
        ]);

        return response()->json([
            'matches' => \App\Support\Dupes::matches(
                $data,
                $data['ignore_id'] ?? null,
                $request->user(),
            ),
        ]);
    }

    /**
     * أعمدة `clients` اللي `NOT NULL` وليها `default` في السكيما.
     *
     * ⚠️ **القايمة دي مستخرجة من المايجريشنز مش مكتوبة من الذاكرة.**
     * أي عمود جديد `NOT NULL` بديفولت والفورم بيقدر يبعته فاضي لازم
     * يتزوّد هنا — وإلا أول حفظ بقيمة فاضية بيطلع 500 في وش المستخدم
     * بعد ما يكون ملا الفورم كله.
     *
     * @var list<string>
     */
    private const DB_DEFAULTED = [
        'eta_type', 'tax_rate', 'taxable', 'price_list', 'category',
        'status', 'discount', 'uses_channel_discount', 'is_new', 'has_docs',
    ];

    /** حقول العميل بس — بنشيل حقول العقد ونحوّل النسب */
    private function clientFields(array $data): array
    {
        $fields = collect($data)
            // ⚠️ `clause` لازم تتشال صراحةً. مش عمود في `clients`،
            // ولو عدّت لـ `update()` بتبقى مصفوفة على حقل مش موجود.
            ->except(['has_contract', 'clause'])
            ->reject(fn ($v, $k) => str_starts_with($k, 'contract_'))
            // ⚠️ **الأعمدة دي `NOT NULL` في الداتابيز وليها ديفولت.**
            //
            // الفورم بيبعتها كنص فاضي، وميدل وير لارافيل
            // `ConvertEmptyStringsToNull` بيحوّله لـ`null`، والقاعدة
            // `nullable` بتقبله — وبعدين MySQL بترمي:
            //
            //     SQLSTATE[23000] 1048: Column 'eta_type' cannot be null
            //
            // ظهرت أول ما حطّينا اختيار فاضي («— اختر نوع المستلم —»)
            // على الدروب داون: قبلها كان دايماً فيه قيمة مختارة سلفاً
            // فالمفتاح ماكانش بيوصل `null` أبداً.
            //
            // بنشيل المفتاح خالص بدل ما نبعت `null` — الداتابيز بتحطّ
            // ديفولتها، والعميل اللي مش خاضع للضريبة مالوش لازمة قيمة
            // في خانة نوع المستلم أصلاً.
            ->reject(fn ($v, $k) => $v === null && in_array($k, self::DB_DEFAULTED, true))
            ->all();

        $fields['discount'] = (float) ($data['discount'] ?? 0) / 100;
        // خصم صفر معناه «خُد خصم السلسلة أو القناة»
        $fields['uses_channel_discount'] = $fields['discount'] <= 0;

        // ⚠️ **مزامنة عمود `price_list` النصي مع القايمة المختارة**
        // (2026-08-07). الفورم بقى بيبعت `price_list_id` بس، والعمود
        // النصي لسه بيتقرا في مسارات قديمة (`price_mode` في أوامر
        // التوريد وتقارير). سيبانه بقيمة قديمة كان معناه إن العميل
        // اتنقل لقايمة جديدة والمسارات دي فاضلة على القديمة.
        // القايمة المسمّاة (مش `old`/`new`) بتتخزن `new` لأن العمود
        // enum — و`price_list_id` هو المرجع الحقيقي في كل الأحوال.
        if (! empty($data['price_list_id'])) {
            $code = \App\Models\PriceList::find($data['price_list_id'])?->code;
            $fields['price_list'] = in_array($code, \App\Services\Pricing::LISTS, true)
                ? $code
                : \App\Services\Pricing::LIST_NEW;
        }

        $fields['taxable'] = (bool) ($data['taxable'] ?? false);
        $fields['tax_rate'] = (float) ($data['tax_rate'] ?? 0) / 100;

        // ⚠️ الصفوف الفاضية بتتشال **وقت الحفظ** مش وقت العرض. الفورم
        // بيبعت الصف اللي المستخدم فتحه وساب فيه اسم من غير تليفون أو
        // العكس، وتخزينه معناه صفوف فاضية بتتراكم في الـJSON وبتتعد في
        // حد الـ12، والمستخدم يوصل للحد وهو شايف 3 جهات تواصل بس.
        // ⚠️ و`array_values` إجبارية — مفاتيح الفورم أرقام كبيرة
        // (`Date.now()`)، ومن غيرها بتتخزن ككائن JSON مش مصفوفة.
        if (array_key_exists('contacts', $data)) {
            $fields['contacts'] = array_values(array_filter(
                array_map(fn ($c) => [
                    'name' => trim((string) ($c['name'] ?? '')),
                    'role' => trim((string) ($c['role'] ?? '')) ?: null,
                    'phone' => trim((string) ($c['phone'] ?? '')) ?: null,
                ], $data['contacts'] ?? []),
                fn ($c) => $c['name'] !== '' || $c['phone'] !== null,
            ));
        }

        return $fields;
    }

    /**
     * إنشاء أو تحديث عقد العميل من نفس الفورم.
     * لو الشيك بوكس مش متعلّم، العقد الموجود يتوقّف (مش بيتمسح —
     * عشان تاريخ الخصم يفضل موجود).
     */
    private function syncContract(Client $client, array $data, Request $request): void
    {
        $wants = $request->boolean('has_contract');

        // ⚠️ هنا بنتعامل مع عقد **العميل نفسه** بس، مش liveContract().
        // لو استخدمنا العقد الموروث، تعديل فرع واحد كان هيوقف عقد السلسلة كلها.
        if (! $wants) {
            $client->contract?->update(['active' => false]);

            return;
        }

        $clauses = collect($data['contract_clauses'] ?? [])
            ->map(fn ($c) => trim((string) $c))
            ->filter()
            ->values()
            ->all();

        $contract = $client->contract ?? new Contract(['client_id' => $client->id]);

        $contract->fill([
            'client_id' => $client->id,
            'number' => $contract->number ?? Contract::nextNumber(),
            // ⚠️ اسم السلسلة بيتقرا من مجموعة العميل — الحقل الحر
            // اتشال من الفورم لأن السلسلة بتتحدد في مرحلة التعريف،
            // وحقلين لنفس المعنى معناهم فرع مربوط بسلسلة ومكتوب
            // في عقده اسم سلسلة تانية.
            'chain' => $client->group?->displayName() ?: $contract->chain,
            // ⚠️ الحقول الاختيارية ممكن تكون مش موجودة في $data خالص
            // (validate بيشيل الفاضي)، فلازم ?? قبل ?: عشان مايبقاش Undefined key.
            'type_key' => ($data['contract_type'] ?? null) ?: Contract::TYPE_DEFAULT,
            'duration' => $data['contract_duration'] ?? null,
            // ⚠️ **`type` مابتتلمسش.** فيه نص النوع بالعربي زي ما هو
            // مكتوب في العقد الأصلي («عقد توريد بضائع بالمبيع»)، و
            // `typeLabel()` بتفضّل `type_key` عليه أصلاً. تصفيره كان
            // بيمسح صياغة الـPDF من غير رجعة عند أول حفظ من الكارت.
            // ⚠️ **`discount` مش بتتكتب هنا.** مصدرها الوحيد بند
            // `invoice_discount` عن طريق `recalcFromClauses()` تحت.
            // لما كانت بتتكتب في المكانين، الفورم كان بيحفظ رقم
            // والحساب بيدوس عليه برقم تاني ومحدش يعرف مين الصح.
            // ⚠️ **مابتتكتبش من الفورم.** بقى فيه قائمة سعر واحدة
            // على العميل. العقود القديمة اللي ليها قائمة خاصة
            // بتفضل زي ما هي — تصفيرها هنا كان هيغيّر تسعير 22 عقد
            // حقيقي عند أول حفظ.
            'payment_days' => $data['contract_payment_days'] ?? null,
            'payment_days_from' => ($data['contract_payment_days_from'] ?? null)
                ?: Contract::DAYS_FROM_FIRST_SUPPLY,
            // ⚠️ لو الحقل اتفضّى لازم terms تتفضّى معاه. لو سيبناها زي ما هي،
            // Contract::paymentDays() بترجع تقرا الرقم القديم من النص وتوريه تاني.
            //
            // ⚠️ **`!== null` مش truthy** (إصلاح 2026-08-08) — عقد بصفر
            // يوم (مستحق فوراً) كان بيتكتب `terms = null`، وده اللي
            // كان بيخلّي `paymentDays()` ترجّع `null` وتسيب العقد يتداس.
            'terms' => ($data['contract_payment_days'] ?? null) !== null
                ? __('client.days_countable', ['count' => $data['contract_payment_days']])
                : null,
            // ⚠️ **«تعامل بالطلب» مالوش تاريخ بداية.** الـ`?: today()`
            // كان بيحطّ تاريخ النهاردة على عقد المفروض مالوش تواريخ
            // خالص — وبعد سنة حد بيبص يلاقي «عقد بدأ في يوم كذا» وهو
            // أصلاً مش عقد.
            'starts_at' => Contract::durationHasDates($data['contract_duration'] ?? null)
                ? (($data['contract_starts_at'] ?? null) ?: today())
                : null,
            'ends_at' => Contract::durationHasEnd($data['contract_duration'] ?? null)
                ? ($data['contract_ends_at'] ?? null)
                : null,
            'note' => $data['contract_note'] ?? null,
            'clauses' => $clauses,
            'active' => true,
        ])->save();

        // نسخة العقد على السيرفر
        if ($request->hasFile('contract_file')) {
            $contract->forceFill([
                'file_path' => ContractIntake::storeFile($contract, $request->file('contract_file')),
            ])->save();
        }

        // ⚠️ آخر خطوة — البنود هي اللي بتحسب النِسَب، مش الفورم.
        // ولازم تيجي بعد `save()` عشان العقد الجديد يبقى له `id`.
        ContractIntake::syncClauses($contract, $data['clause'] ?? []);
    }

    // ================= العقود =================

    /**
     * ⚠️ "عقد سارٍ" = active وكمان مش منتهي. نفس شرط Client::hasLiveContract().
     * الشرط ده لازم يكون واحد في كل السيستم، وإلا شاشة تعرض العميل عليه عقد
     * وشاشة تانية تعرضه من غير عقد.
     */
    private function liveContractScope(): callable
    {
        return fn ($w) => $w->where('active', true)
            ->where(fn ($e) => $e->whereNull('ends_at')->orWhere('ends_at', '>=', today()));
    }

    public function contracts()
    {
        // ⚠️ سكوب التشانل مانجر (2026-08-05): عقود عملائه + عقود السلاسل
        // (السلسلة مش مملوكة لمدير — عقدها بيغطي فروع عند كذا مدير).
        $u = auth()->user();
        $all = Contract::with(['client.zone', 'group', 'contractClauses'])
            ->when($u?->role === 'manager', fn ($q) => $q->where(fn ($w) => $w
                ->whereHas('client', fn ($c) => Client::visibleTo($c, $u))
                ->orWhereNotNull('group_id')))
            ->get()
            ->sortBy([
                // المنتهي والمستعجل الأول — ده اللي محتاج قرار
                fn ($a, $b) => ($a->daysLeft() ?? PHP_INT_MAX) <=> ($b->daysLeft() ?? PHP_INT_MAX),
            ])->values();

        // الإحصائيات على العقود السارية بس — عقد موقوف أو منتهي مايدخلش المتوسط
        $live = $all->filter(fn ($c) => $c->active && ! $c->isExpired())->values();

        return view('erp.contracts', [
            'contracts' => $all,
            // ⚠️ التنبيهات دي كانت مش موجودة خالص: عقد بيتجدد تلقائياً
            // وميعاد الإخطار عدّى = التزمنا بسنة تانية من غير ما ناخد بالنا.
            'noticeMissed' => $all->filter(fn ($c) => $c->active && $c->noticeMissed())->values(),
            'noticeSoon' => $all->filter(fn ($c) => $c->active && ! $c->noticeMissed()
                && $c->noticeDaysLeft() !== null && $c->noticeDaysLeft() <= 60)->values(),
            'expiringSoon' => $live->filter(fn ($c) => $c->daysLeft() !== null && $c->daysLeft() <= 90)
                ->sortBy(fn ($c) => $c->daysLeft())->values(),
            'unsigned' => $all->filter(fn ($c) => ! $c->signed_ok)->values(),
            'unlinked' => $all->filter(fn ($c) => $c->client_id === null && $c->group_id === null)->values(),
            'consignment' => $all->filter(fn ($c) => $c->isConsignment())->values(),
            'hiddenCost' => $live->filter(fn ($c) => $c->hiddenDeduction() > 0)
                ->sortByDesc(fn ($c) => $c->hiddenDeduction())->values(),
            'totalCommitment' => round($live->sum(fn ($c) => $c->annualCommitment()), 2),
            'avgTotalDeduction' => $live->count() ? round($live->avg(fn ($c) => $c->totalDeduction()), 4) : 0,
            'avgDisc' => $live->avg('discount') ?? 0,
            'covered' => $live->sum(fn ($c) => (float) ($c->client?->purchases ?? 0)),
            'totalPurch' => (float) Client::visibleTo(Client::query())->sum('purchases'),
            'clientsCount' => Client::visibleTo(Client::query())->count(),
            // ⚠️ "من غير عقد" = مفيش صف عقد سارٍ. ممنوع نستنتجها من discount،
            // لأن العميل ممكن ياخد خصم من القناة أو السلسلة وهو من غير عقد،
            // وساعتها كان بيختفي من القايمة ومكنّاش نعرف نكتب له عقد.
            'noContract' => Client::visibleTo(Client::whereDoesntHave('contract', $this->liveContractScope())
                ->where('category', '!=', 'internal'))
                ->orderByDesc('purchases')->take(20)->get(),
        ]);
    }

    /**
     * صفحة عقد واحد.
     *
     * ⚠️ الفلسفة: الصفحة دي بتعرض **الداتا المنظّمة بس** — نِسَب وتواريخ
     * وبنود مصنّفة. النصوص الحرة الطويلة (شروط السداد، الإنهاء، التجديد)
     * محرّرة بالعربي في العقد الأصلي، فبتتعرض في الواجهة العربية بس،
     * والإنجليزية بتوجّه لأصل الـ PDF. أحسن من ترجمة آلية أو لغة مخلوطة.
     */
    public function contract(Contract $contract)
    {
        $contract->load(['client.zone', 'group', 'contractClauses']);

        // ⚠️ سكوب التشانل مانجر (تدقيق ١٠/٨): عقد عميل مفرد مش بتاعه
        // مايتفتحش بالـid. عقد السلسلة مفتوح — نفس سياسة صفحة العقود.
        abort_unless(
            $contract->client === null || $contract->client->visibleBy(auth()->user()),
            403,
        );

        // البنود متقسّمة لمجموعات عرض: المهم فوق والتفاصيل تحت
        $clauses = $contract->contractClauses;

        $bucket = fn (array $kinds) => $clauses
            ->whereIn('kind', $kinds)
            ->sortByDesc(fn ($c) => (float) ($c->pct ?? 0))
            ->values();

        // الفروع اللي العقد بيغطيها — عقد السلسلة بيغطي كل فروعها
        $branches = $contract->group_id
            ? Client::visibleTo(Client::where('group_id', $contract->group_id))->orderBy('name')->get()
            : collect(array_filter([$contract->client]));

        return view('erp.contract', [
            'ct' => $contract,
            'money' => $bucket(['invoice_discount', 'rebate', 'collection', 'withholding']),
            'fees' => $bucket(['listing_fee', 'opening_fee', 'marketing', 'rent']),
            'penalties' => $bucket(['penalty']),
            'others' => $bucket(['returns', 'credit', 'tax_withheld', 'other']),
            'branches' => $branches,
            // ⚠️ لفورم الربط اليدوي — بيظهر بس للعقد اليتيم. العقود
            // اللي سلاسلها مش في شيتات 2026 (رابيت، رويال هاوس…)
            // مالهاش مطابقة تلقائية، ومن غير الفورم ده بتفضل يتيمة
            // للأبد وكل عملاءها «من غير عقد».
            'linkGroups' => $contract->client_id === null && $contract->group_id === null
                ? \App\Models\ClientGroup::where('active', true)->orderBy('name')->get()
                : collect(),
            'linkClients' => $contract->client_id === null && $contract->group_id === null
                ? Client::visibleTo(Client::orderBy('code'))->get(['id', 'code', 'name', 'name_en'])
                : collect(),
        ]);
    }

    /**
     * ربط عقد يتيم بسلسلة أو عميل.
     *
     * ⚠️ **حصري: سلسلة أو عميل مش الاتنين.** عقد على سلسلة وعميل في
     * نفس الوقت بيتحسب مرتين في `liveContract()` — الفرع بياخده من
     * نفسه ومن سلسلته.
     */
    public function linkContract(Request $request, Contract $contract)
    {
        $data = $request->validate([
            'group_id' => ['nullable', 'required_without:client_id', 'exists:client_groups,id'],
            'client_id' => ['nullable', 'required_without:group_id', 'exists:clients,id'],
        ]);

        if (! empty($data['group_id']) && ! empty($data['client_id'])) {
            return back()->withErrors(['group_id' => __('client.link_pick_one')]);
        }

        $contract->update([
            'group_id' => $data['group_id'] ?? null,
            'client_id' => $data['client_id'] ?? null,
        ]);

        return back()->with('ok', __('client.contract_linked'));
    }

    public function storeContract(Request $request)
    {
        $data = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'chain' => ['nullable', 'string', 'max:190'],
            // ⚠️ `Contract::displayChain()` بترجّع **فاضي** في الإنجليزي
            // لو `chain_en` فاضي — عن قصد، عشان مايسرّبش اسم عربي
            // لواجهة إنجليزية. يعني من غير الحقل ده اسم السلسلة
            // بيختفي خالص من الشاشة الإنجليزية مش بيبان بلغة تانية.
            'chain_en' => ['nullable', 'string', 'max:190'],
            'type' => ['nullable', 'in:'.implode(',', array_keys(Contract::TYPE_KEYS))],
            'discount' => ['required', 'numeric', 'min:0', 'max:100'],
            'terms' => ['nullable', 'string', 'max:100'],
            'ends_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string'],
        ]);
        $data['discount'] = $data['discount'] / 100;

        // العقد لازم يبقى active عشان effectiveDiscount() تشوفه
        // النوع بيتخزن كمفتاح — الترجمة وقت العرض
        $data['type_key'] = $data['type'] ?? Contract::TYPE_DEFAULT;
        unset($data['type']);

        Contract::updateOrCreate(['client_id' => $data['client_id']], $data + ['active' => true]);

        // ⚠️ ممنوع ننسخ خصم العقد في clients.discount. كان بيعمل كده قبل كده،
        // فلما العقد يتوقف أو ينتهي، effectiveDiscount() بتسيب العقد وترجع
        // للخطوة اللي بعدها فتلاقي النسخة القديمة لسه موجودة، والعميل يفضل
        // ياخد خصم عقد ميت للأبد. خصم العقد يعيش في contracts.discount بس.

        return back()->with('ok', __('flash.contract_saved'));
    }

    /**
     * إضافة أو تعديل بند في عقد.
     *
     * ⚠️ بعد أي تغيير لازم recalcFromClauses() — نسب العقد المخزّنة
     * (discount / total_deduction_pct / withholding_pct) مشتقة من البنود،
     * فلو عدّلنا بند ونسينا نعيد الحساب، الفاتورة تفضل بالنسبة القديمة.
     */
    public function storeClause(Request $request, Contract $contract)
    {
        $data = $request->validate([
            'clause_id' => ['nullable', 'integer', 'exists:contract_clauses,id'],
            'kind' => ['required', 'string', 'max:24'],
            'basis' => ['required', 'string', 'max:20'],
            'label' => ['required', 'string', 'max:400'],
            // ⚠️ نص البند بالإنجليزي. `ContractClause::displayLabel()`
            // بترجّع «بند من غير ترجمة» لو فاضي — مش بترجّع العربي —
            // فالبند اللي اتكتب من الشاشة العربية بيختفي من صفحة العقد
            // الإنجليزية.
            'label_en' => ['nullable', 'string', 'max:400'],
            'pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:500'],
            'is_uncertain' => ['nullable', 'boolean'],
        ]);

        if (! array_key_exists($data['kind'], \App\Models\ContractClause::KINDS)
            || ! in_array($data['basis'], \App\Models\ContractClause::BASES, true)) {
            return back()->withErrors(['kind' => __('client.clause_kind_invalid')]);
        }

        DB::transaction(function () use ($data, $contract, $request) {
            $clause = ($data['clause_id'] ?? null)
                ? $contract->contractClauses()->findOrFail($data['clause_id'])
                : new \App\Models\ContractClause(['contract_id' => $contract->id]);

            // ⚠️ **الفورم بقى فيه الخانتين صراحةً** (عربي وإنجليزي جنب
            // بعض)، فكل عمود بياخد نصه. قبل كده كان الحفظ بيروح لعمود
            // لغة الواجهة، يعني المدير اللي بيشتغل بالإنجليزي عمره
            // مايقدر يكتب النص العربي والعكس — وكل بند بيفضل نصّه ناقص.
            $payload = [
                'contract_id' => $contract->id,
                'kind' => $data['kind'],
                'basis' => $data['basis'],
                // الفورم بياخد 15 ويخزّن 0.15 — القسمة مرة واحدة بس
                'pct' => ($data['pct'] ?? null) === null ? null : (float) $data['pct'] / 100,
                'amount' => $data['amount'] ?? null,
                'is_uncertain' => $request->boolean('is_uncertain'),
                'label' => $data['label'],
                'note' => $data['note'] ?? null,
            ];

            // ⚠️ الإنجليزي بيتكتب **بس لو المستخدم كتبه**. لو حفظناه
            // فاضي، أي بند قديم متترجم بيفقد ترجمته أول ما حد يعدّل
            // النسبة بتاعته من غير ما يلمس النص.
            if (filled($data['label_en'] ?? null)) {
                $payload['label_en'] = $data['label_en'];
            }

            $clause->fill($payload)->save();


            $contract->recalcFromClauses();
        });

        return back()->with('ok', __('flash.clause_saved'));
    }

    public function destroyClause(Request $request, Contract $contract)
    {
        $id = $request->integer('clause_id');

        DB::transaction(function () use ($id, $contract) {
            $contract->contractClauses()->whereKey($id)->delete();
            $contract->recalcFromClauses();
        });

        return back()->with('ok', __('flash.clause_deleted'));
    }

    /**
     * أصل العقد — بيفتح الـ PDF في تاب جديد.
     *
     * ⚠️ الملف في storage مش public عن قصد: العقود فيها أسعار وشروط
     * تجارية. الراوت جوه مجموعة auth، والملف بيتقدّم inline عشان
     * المتصفح يعرضه بدل ما ينزّله.
     */
    public function contractFile(Request $request, Contract $contract)
    {
        // ⚠️ الميدلوير بيقفل الرول، وده بيقفل الفرع. من غيره مدير
        // المعادي بينزّل عقود عملاء الجيزة بأسعارهم — والفلترة في
        // القوايم بتخبّي اللينك بس مش الراوت.
        abort_unless($request->user()->canSeeBranch($contract->client?->branch_id), 403);

        // ⚠️ ممنوع Storage::disk('local') هنا. من Laravel 11 جذر الديسك ده
        // بقى storage/app/**private**، والملفات بتاعتنا في storage/app/contracts.
        // كان بيرجّع 404 على كل الـ 22 عقد. المسار المباشر أوضح وأأمن.
        $path = (string) $contract->file_path;
        $full = storage_path('app/'.$path);

        // حارس ضد التسلل بالمسار — file_path جاي من الداتابيز بس نتأكد
        $root = realpath(storage_path('app/contracts'));
        $real = realpath($full);

        if ($path === '' || $real === false || $root === false
            || ! str_starts_with($real, $root) || ! is_file($real)) {
            abort(404, __('client.contract_file_missing'));
        }

        // ⚠️ النوع بيتحدد من الملف نفسه. لما كان مثبّت على PDF، أول عقد
        // اتصوّر بالموبايل واترفع JPG كان المتصفح بيحاول يفتحه كـPDF
        // ويطلع صفحة سودة، والمستخدم يفتكر الملف ضاع.
        $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
        $type = match ($ext) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'application/pdf',
        };

        return response()->file($real, [
            'Content-Type' => $type,
            'Content-Disposition' => 'inline; filename="'.$contract->number.'.'.($ext ?: 'pdf').'"',
        ]);
    }

    public function destroyContract(Contract $contract)
    {
        // مابنلمسش clients.discount — العميل ممكن يكون له خصم خاص مستقل
        // عن العقد، ومسح العقد مايصحّش يصفّره.
        $contract->delete();

        return back()->with('ok', __('flash.contract_deleted'));
    }

    // ================= المخزون =================

    public function stock(Request $request)
    {
        $q = Product::with(['stocks.warehouse']);
        if ($s = $request->string('q')->trim()->value()) {
            $q->where(fn ($w) => $w->where('name', 'like', "%$s%")->orWhere('code', 'like', "%$s%"));
        }
        if ($fam = $request->string('family')->value()) {
            $q->where('family', $fam);
        }

        // ═══ فلتر الحالة — ١٧ أغسطس ٢٠٢٦ ═══
        //
        // ⚠️ **الشاشة دي ماكانتش بتفرّق بين المفعّل والدرافت خالص**:
        // مفيش فلتر ولا شارة ولا عمود (أودِت ١٧/٨). المالك أوقف صنف
        // ومالقاش طريقة يلاقيه تاني غير إنه يفتح كل منتج لوحده.
        //
        // ⚠️ **الافتراضي «الكل» مش «المفعّل».** دي **شاشة الإدارة**
        // — لو خبّينا الدرافت هنا كمان، الصنف اللي اتوقف يبقى مش
        // موجود في أي مكان في السيستم بما فيها المكان اللي المفروض
        // تفعّله منه.
        $status = $request->string('status')->value();

        if ($status === 'active') {
            $q->where('active', true);
        } elseif ($status === 'draft') {
            $q->where('active', false);
        }

        $products = $q->orderBy('code')->get();
        $all = Product::with('stocks')->get();

        // ⚠️ **السعر المعروض واحد بس: سعر القايمة الافتراضية** (قرار
        // المالك 2026-08-06) — مش «قديم/جديد». كل قيم الشاشة بتتحسب
        // بنفس السعر ده عشان الرقم فوق يساوي الجدول تحت.
        $defaultList = \App\Models\PriceList::default();
        $priceOf = fn (Product $p) => \App\Services\Pricing::listPrice($p, $defaultList);

        // الترتيب: بالكود (الافتراضي) / بالكمية / بالقيمة
        $products = match ($request->string('sort')->value()) {
            'qty' => $products->sortByDesc(fn ($p) => $p->qtyTotal())->values(),
            'value' => $products->sortByDesc(fn ($p) => $p->qtyTotal() * $priceOf($p))->values(),
            default => $products,
        };

        // ⚠️ **المفعّل + أي موقوف لسه فيه رصيد.**
        // لو عرضنا المفعّل بس، بضاعة قاعدة في مخزن اتوقف بتختفي
        // من الأعمدة بينما `qtyTotal()` بتعدّها — فمجموع الأعمدة
        // مايساويش عمود «الكمية كلها» ومحدش يعرف الفرق راح فين.
        // المخزن الموقوف اللي رصيده صفر مابيظهرش، فالجدول
        // مابيتوسّعش من غير داعي.
        $warehouses = \App\Models\Warehouse::query()
            ->where(fn ($w) => $w->where('active', true)
                ->orWhereHas('stocks', fn ($s) => $s->where('qty', '>', 0)))
            ->orderBy('type')->orderBy('code')->get();

        return view('erp.stock', [
            'products' => $products,
            'families' => \App\Models\ProductFamily::options(),
            'warehouses' => $warehouses,
            'defaultList' => $defaultList,
            'filters' => $request->only(['q', 'family', 'sort', 'status']),
            // شارة على زرار الفلتر — «عندك ٧ درافت» من غير ما تفتحه
            'draftCount' => Product::where('active', false)->count(),
            // ⚠️ كل الـ KPIs دي على $all (المخزن كله) — ممنوع تخلط واحد منهم
            // مع رقم محسوب من $products المفلترة، الهامش يطلع غلط.
            'totalVal' => $all->sum(fn ($p) => $p->qtyTotal() * $priceOf($p)),
            'costVal' => $all->sum(fn ($p) => $p->qtyTotal() * (float) $p->cost),
            'skuCount' => $all->count(),
            'holdVal' => $all->sum(fn ($p) => $p->holdTotal() * $priceOf($p)),
            'goodVal' => $all->sum(fn ($p) => $p->goodTotal() * $priceOf($p)),
            'totalQty' => $all->sum(fn ($p) => $p->qtyTotal()),
            'famStats' => $all->groupBy('family')->map(fn ($g) => [
                'n' => $g->count(),
                'qty' => $g->sum(fn ($p) => $p->qtyTotal()),
                'val' => $g->sum(fn ($p) => $p->qtyTotal() * $priceOf($p)),
                'hold' => $g->sum(fn ($p) => $p->holdTotal() * $priceOf($p)),
            ])->all(),
            // توزيع المخازن — للشارت (وحدات + قيمة لكل مخزن)
            'whStats' => $warehouses->map(fn ($wh) => [
                'name' => $wh->displayName(),
                'qty' => $all->sum(fn ($p) => $p->qtyIn($wh)),
                'val' => round($all->sum(fn ($p) => $p->qtyIn($wh) * $priceOf($p))),
            ])->values()->all(),
        ]);
    }

    /**
     * قواعد كارت الصنف — مصدر واحد للإضافة والتعديل.
     *
     * ⚠️ **الـ11 عمود اللي كانوا من غير واجهة موجودين هنا دلوقتي.**
     * كانوا بيتكتبوا من `Gs1CatalogueSeeder` بس، يعني أي صنف مش في
     * فيد GS1 بيفضل من غير باركود كرتونة ولا وزن ولا مدة صلاحية —
     * والمخزن بيحسب انتهاء الصلاحية بـ12 شهر افتراضي على صنف عمره
     * الحقيقي 9 شهور.
     *
     * @param  Product|null  $product  للتفرد وقت التعديل
     */
    private function productRules(?Product $product = null): array
    {
        $id = $product?->id;

        return [
            'name' => ['required', 'string', 'max:190'],
            // ⚠️ الاسم والوحدة الإنجليزيين. الصنف بيتعرض في الفاتورة
            // وفي أبلكيشن المندوب وفي التصدير للمصلحة — و«كرتونة» جوه
            // فاتورة إنجليزية بتخلّي المستند يترفض.
            // ⚠️ **إجباري على السيرفر كمان** (2026-08-08). الفورم عليه
            // `data-req` ونجمة، والسيرفر كان `nullable` — يعني المتصفح
            // بيمنع واللي بيبعت من غير المتصفح (استيراد، tinker، فورم
            // معدّل) بيعدّي. والإنجليزي هو الافتراضي في كل الشاشات
            // والمطبوعات، فعميل من غيره اسمه بيطلع فاضي قدام العميل.
            'name_en' => ['required', 'string', 'max:190'],
            'unit' => ['required', 'string', 'max:40'],
            'unit_en' => ['nullable', 'string', 'max:40'],

            // ═══ الباركودات ═══
            // ⚠️ **التفرد لازم.** `Product::findByBarcode()` بترجّع
            // `first()` — باركودين متكررين معناهم إن المسح في الأبلكيشن
            // بيطلّع صنف عشوائي من الاتنين، والفاتورة بتتكتب بصنف غلط.
            'barcode' => ['nullable', 'string', 'max:20',
                Rule::unique('products', 'barcode')->ignore($id)],
            'case_barcode' => ['nullable', 'string', 'max:20'],
            'units_per_case' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'box_units' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'eta_code' => ['nullable', 'string', 'max:30'],

            // ═══ المواصفات ═══
            'net_content' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'net_uom' => ['nullable', 'string', 'max:10'],
            'brand' => ['nullable', 'string', 'max:40'],
            'gpc_category' => ['nullable', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'description_en' => ['nullable', 'string', 'max:2000'],

            // ⚠️ مدة الصلاحية بتحسب تاريخ الانتهاء لكل باتش داخلة.
            // الافتراضي 12 شهر، والكوب الحقيقي 9 — فالفرق ده بيخلّي
            // السيستم يقول إن بضاعة سليمة وهي منتهية.
            'shelf_life_months' => ['nullable', 'integer', 'min:1', 'max:120'],

            // ═══ التسعير ═══
            // ⚠️ **السعر من القوايم (قرار المالك 2026-08-04).** الفورم
            // بيبعت `list_price[list_id]` لكل قائمة، والعمودان القديمان
            // بقوا nullable — لسه بيتقبلوا من المستورد والفولباك لما
            // مفيش قوايم في الداتابيز.
            'cost' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'price_old' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'price_new' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'list_price' => ['nullable', 'array'],
            'list_price.*' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'taxable' => ['nullable', 'boolean'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],

            'active' => ['nullable', 'boolean'],
            'image' => \App\Services\ProductImage::rule(),
        ];
    }

    /** الحقول اللي مش أعمدة في `products` */
    private const PRODUCT_NOT_COLUMNS = [
        'qty', 'hold_qty', 'warehouse_id', 'taxable', 'tax_rate', 'image', 'active',
        'list_price',
    ];

    /**
     * كتابة أسعار القوايم من الفورم — قرار المالك 2026-08-04.
     *
     * لكل قائمة اتبعت لها قيمة: بنكتب `price_list_items` (مصدر
     * الفواتير)، ولو القايمة هي المهاجرة `old`/`new` بنزامن العمود
     * (مصدر الـKPIs والأبلكيشن) — فالاتجاهين متطابقين دايماً.
     *
     * ⚠️ **الخانة الفاضية = ماتلمسش.** null مش صفر: المستخدم اللي
     * ساب قايمة فاضية مش قاصد يصفّر سعرها — والصفر على السعر
     * الافتراضي كان بيخلي الفاتورة تترفض «الصنف مش متسعّر».
     *
     * لو الفورم ماباعتش قوايم خالص (المستورد / داتابيز من غير
     * قوايم) بنرجع للمزامنة القديمة عمود ← قايمة.
     */
    private function applyListPrices(Product $product, array $prices): void
    {
        if ($prices === []) {
            \App\Services\Pricing::syncColumnsToLists($product);

            return;
        }

        $columns = [];

        foreach (\App\Models\PriceList::all() as $list) {
            $value = $prices[$list->id] ?? null;

            // ⚠️ **الصفر زي الفاضي — مابيلمسش.** نفس قاعدة
            // Pricing::syncColumnsToLists: صفر بيتكتب فوق سعر معتمد
            // كان بيخلّي كل فواتير عملاء القايمة تترفض «الصنف مش
            // متسعّر» — وتصفير سعر عن قصد مكانه شاشة التسعير.
            if ($value === null || $value === '' || (float) $value <= 0) {
                continue;
            }

            \App\Models\PriceListItem::updateOrCreate(
                ['price_list_id' => $list->id, 'product_id' => $product->id],
                ['price' => (float) $value],
            );

            if ($list->code === 'old') {
                $columns['price_old'] = (float) $value;
            } elseif ($list->code === 'new') {
                $columns['price_new'] = (float) $value;
            }
        }

        if ($columns !== []) {
            $product->update($columns);
        }
    }

    /**
     * كارت الصنف — صفحة واحدة فيها كل حاجة عنه.
     *
     * ⚠️ **ماكانش فيه صفحة لصنف واحد خالص.** الكتالوج كان جدول
     * و«التعديل» مودال بـ12 حقل — و11 عمود في الجدول مالهمش أي واجهة
     * (باركود الكرتونة، الوزن، البراند، مدة الصلاحية، كود المصلحة…).
     * اللي عايز يعرف صنف بيتباع بكام ومخزونه فين وصلاحيته إمتى كان
     * بيفتح 3 شاشات.
     */
    public function product(Request $request, Product $product)
    {
        // ⚠️ **`stocks` بالجمع.** المخزون بقى صف لكل (صنف، مخزن)،
        // فـ`stock` المفردة اتشالت — والكارت لازم يوري التوزيع مش رقم.
        $product->load('stocks.warehouse');

        // ⚠️ الباتشات بترتيب الصلاحية (FEFO) — الأقرب انتهاءً فوق،
        // لأنها اللي بتتباع الأول واللي بتقلق.
        $batches = $product->batches()
            ->with('warehouse')
            ->orderByRaw('expires_on IS NULL, expires_on')
            ->get();

        // ⚠️ **مين بيشتريه** — من `invoice_items` مش من تقدير.
        // بيجاوب على «الصنف ده ماشي مع مين؟» اللي بيتسأل قبل أي قرار
        // بإيقاف صنف أو زيادة إنتاجه.
        $buyers = \App\Models\InvoiceItem::query()
            ->where('product_id', $product->id)
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->join('clients', 'clients.id', '=', 'invoices.client_id')
            ->selectRaw('clients.id, clients.name, clients.name_en, clients.code,
                SUM(invoice_items.qty) as qty, SUM(invoice_items.total) as total,
                MAX(invoices.created_at) as last_at')
            ->groupBy('clients.id', 'clients.name', 'clients.name_en', 'clients.code')
            ->orderByDesc('qty')
            ->limit(20)
            ->get();

        return view('erp.product', [
            'p' => $product,
            'batches' => $batches,
            'buyers' => $buyers,
            'families' => \App\Models\ProductFamily::options(),
            // ⚠️ سعر الصنف في **كل** قايمة مسمّاة — الفواتير بتتسعّر
            // من القوايم دلوقتي، فكارت بيوري عمودين قديمين بس بيكدب.
            // ⚠️ الـeager load مقيّد بالصنف ده — `priceFor` بتلمس
            // `items` كلها، ومن غير القيد كارت واحد بيحمّل كل أسعار
            // كل القوايم.
            'priceLists' => $request->user()->isWarehouseKeeper()
                ? collect()
                : \App\Models\PriceList::with([
                    'items' => fn ($q) => $q->where('product_id', $product->id),
                ])->orderByDesc('is_default')->orderBy('id')->get(),
            // ⚠️ نفس بوابة شاشة المخزون: أمين المخزن بيشوف كميات
            // مش تكلفة ولا هامش. الشاشة الجديدة كانت هتفتح الباب
            // اللي الشاشة القديمة قافلاه.
            'seeCost' => ! $request->user()->isWarehouseKeeper(),
            'manager' => $request->user()->canDecideOps(),
        ]);
    }

    public function storeProduct(Request $request)
    {
        $rules = $this->productRules() + [
            'code' => ['required', 'string', 'max:20', 'unique:products,code'],
            'family' => ['required', 'string', 'max:40'],
        ];

        // ⚠️ **صنف جديد لازم يتسعّر في القايمة الافتراضية.** من غير
        // القاعدة دي الصنف بيتعرّف من غير سعر خالص (العمودان بقوا
        // nullable) وأول فاتورة بيه في الشارع بتترفض «الصنف مش
        // متسعّر». المستورد القديم بيبعت price_new فبيعدي.
        $defaultList = \App\Models\PriceList::where('is_default', true)->first();

        if ($defaultList !== null) {
            $rules['list_price.'.$defaultList->id] = [
                'required_without:price_new', 'numeric', 'min:0.01', 'max:9999999',
            ];
        }

        $data = $request->validate($rules);

        // ⚠️ **الترانزاكشن بتلم الصنف والأرصدة والمزامنة.** فشل في
        // النص كان بيسيب صنف من غير صفوف رصيد فمايبانش في المخازن،
        // أو عمود سعر اتكتب والقايمة لأ.
        $product = DB::transaction(function () use ($data, $request) {
            $product = Product::create(
                Arr::except($data, self::PRODUCT_NOT_COLUMNS)
                + $this->taxFields($request)
                + ['active' => $request->boolean('active', true)]
            );
        // ⚠️ **صف رصيد بصفر في كل مخزن مفعّل.** من غيره الصنف
        // مابيبانش في شاشة أي مخزن، واللي بيجرد بيفتكر إنه مش متعرّف
        // ويعمله تاني بكود جديد. الكميات نفسها بتتحط من شاشة تعديل
        // أرصدة المخزن — مش من هنا؛ الفورم ده بيعرّف صنف مش بيوزّع
        // بضاعة، والكمية من غير مكان بتخلّي مخزن يطلب بضاعة عنده.
            foreach (\App\Models\Warehouse::where('active', true)->pluck('id') as $warehouseId) {
                $product->stocks()->firstOrCreate(
                    ['warehouse_id' => $warehouseId],
                    ['qty' => 0, 'hold_qty' => 0, 'good_qty' => 0],
                );
            }

            // أسعار القوايم من الفورم (أو مزامنة العمودين لو الفورم قديم)
            $this->applyListPrices($product, $data['list_price'] ?? []);

            return $product;
        });

        // ⚠️ الصورة بره الترانزاكشن — كتابة ملف مش بتترجّع بالرول باك،
        // وفشلها مايستاهلش يضيّع تعريف الصنف كله.
        if ($request->hasFile('image')) {
            $product->update(['image_path' => \App\Services\ProductImage::store($product, $request->file('image'))]);
        }

        return back()->with('ok', __('flash.product_added'));
    }

    public function updateProduct(Request $request, Product $product)
    {
        $data = $request->validate($this->productRules($product));

        DB::transaction(function () use ($data, $request, $product) {
            $product->update(
                Arr::except($data, self::PRODUCT_NOT_COLUMNS)
                + $this->taxFields($request)
                + ['active' => $request->boolean('active')]
            );

            // أسعار القوايم من الفورم (أو مزامنة العمودين لو الفورم قديم)
            $this->applyListPrices($product->refresh(), $data['list_price'] ?? []);
        });

        // ⚠️ **الصورة بتتحدّث بس لو المستخدم رفع واحدة.** لو كتبنا
        // `image_path` على طول، أي حفظ من غير رفع كان بيفضّي الصورة —
        // وواحد بيصلّح سعر بيلاقي صور الكتالوج كلها راحت.
        if ($request->hasFile('image')) {
            $product->update(['image_path' => \App\Services\ProductImage::store($product, $request->file('image'))]);
        }

        // زرار «شيل الصورة» صريح
        if ($request->boolean('remove_image')) {
            \App\Services\ProductImage::forget($product->image_path);
            $product->update(['image_path' => null]);
        }
        // ⚠️ **مفيش أي كتابة على `stocks` هنا خالص.**
        // كان بيكتب الكمية و`counted_at = اليوم` على كل حفظ — يعني
        // تعديل وصف أو كود مصلحة كان بيمسح تاريخ آخر جرد حقيقي،
        // والرقم كان رايح لمخزن غير اللي الفورم عارضه أصلاً.
        // الأرصدة مكانها شاشة «تعديل الأرصدة» لكل مخزن، وهي اللي
        // بتتخطّى الصف اللي مااتغيرش عشان `counted_at` تفضل صادقة.

        return back()->with('ok', __('flash.product_updated'));
    }

    /**
     * ═══ مسح صنف نزل غلط (٢١ أغسطس ٢٠٢٦) — أدمن بس ═══
     *
     * نفس عقيدة مسح العميل «البِكر»: أي حركة حقيقية بتمنع المسح
     * والرسالة بتقول فيه إيه بالظبط.
     *
     * ⚠️ **الجداول بتتجاب ديناميكياً من السكيما** — كل جدول فيه
     * `product_id` بيحمي نفسه لوحده، حتى الجداول اللي هتتضاف
     * في المستقبل. مفيش قايمة مكتوبة بالإيد تنسى جدول.
     *
     * المسموح ينضّف مع الصنف (مش حركة):
     *   • أسعار القوايم (`price_list_items`) — إعداد كتالوج.
     *   • الباتشات **الفاضية بس** (استيراد ماتحركش) — أي باتش
     *     استلم أو صرف بيمنع المسح.
     *   • صورة الصنف من التخزين.
     */
    public function destroyProduct(Request $request, Product $product)
    {
        abort_unless($request->user()->role === 'admin', 403);

        $tables = collect(DB::select(
            "SELECT DISTINCT TABLE_NAME AS t FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'product_id'"
        ))->pluck('t');

        // اللي بينضّف مش بيمنع.
        // ⚠️ `stocks` اتضافت (بلاغ ٢١/٨): صفوف الأرصدة بتتولد مع
        // الاستيراد لكل مخزن — مش حركة. أي كمية دخلت بطريق حقيقي
        // (استلام مورد / جرد / تجهيز) سايبة أثر في جدول تاني لسه
        // بيمنع المسح عادي، فمفيش رصيد حقيقي بيضيع في صمت.
        // ⚠️ `replenishment_items` كمان (بلاغ ٢١/٨): بند طلب بضاعة/
        // ريفيل مجرد **طلب** — البضاعة الفعلية بتتحرك عبر أوامر
        // التجهيز والعهدة والفواتير ودول لسه بيمنعوا المسح عادي.
        // بنمسح بند الصنف الغلط من الطلب، مش الطلب كله.
        $cleanable = ['price_list_items', 'stocks', 'replenishment_items'];

        $labels = [
            'invoice_items' => __('stock.del_invoices'),
            'return_items' => __('stock.del_returns'),
            'custody_items' => __('stock.del_custody'),
            'gift_handouts' => __('stock.del_gifts'),
            'batches' => __('stock.del_batches'),
        ];

        $found = [];

        foreach ($tables as $t) {
            if (in_array($t, $cleanable, true)) {
                continue;
            }

            $q = DB::table($t)->where('product_id', $product->id);

            // الباتش الفاضي (اتولد مع الاستيراد وماتحركش) مش حركة
            if ($t === 'batches') {
                $q->where(fn ($w) => $w->where('qty_received', '>', 0)
                    ->orWhere('qty_issued', '>', 0));
            }

            $n = $q->count();

            if ($n > 0) {
                $found[] = ($labels[$t] ?? $t).' ('.$n.')';
            }
        }

        if ($found !== []) {
            return back()->withErrors([
                'product' => __('stock.cannot_delete_product', [
                    'name' => $product->displayName(),
                    'things' => implode(' · ', $found),
                ]),
            ]);
        }

        $name = $product->displayName();

        DB::transaction(function () use ($product) {
            foreach (['price_list_items', 'stocks', 'replenishment_items', 'batches'] as $t) {
                if (\Illuminate\Support\Facades\Schema::hasTable($t)) {
                    DB::table($t)->where('product_id', $product->id)->delete();
                }
            }

            if ($product->image_path) {
                \App\Services\ProductImage::forget($product->image_path);
            }

            $product->delete();
        });

        return redirect(route('erp.stock'))
            ->with('ok', __('stock.product_deleted', ['name' => $name]));
    }

    /**
     * المخزن اللي الرصيد بيتكتب عليه.
     *
     * ⚠️ **بترجّع `null` لو مفيش مخازن خالص** بدل ما تخترع واحد.
     * الرصيد اللي بيتكتب على مخزن اتعمل تلقائياً بيبان في شاشة محدش
     * يعرف إيه هي، والمخزن الحقيقي بيفضل فاضي.
     */
    private function stockWarehouseId(Request $request): ?int
    {
        $id = $request->integer('warehouse_id') ?: null;

        if ($id !== null && \App\Models\Warehouse::whereKey($id)->exists()) {
            return $id;
        }

        return \App\Models\Warehouse::where('active', true)->orderBy('id')->value('id');
    }

    /**
     * خانات الضريبة للمنتج.
     *
     * ⚠️ الشيك بوكس مابيتبعتش خالص وهو مقفول، فـ `$data['taxable']`
     * بتختفي بدل ما تبقى false — لازم `boolean()` مش `??`.
     * ⚠️ والنسبة بتتقسم على 100 **هنا بس**: الشاشة بالنسبة المئوية
     * والداتابيز بالكسر، والقسمة في مكانين بتطلع 0.0014.
     */
    private function taxFields(Request $request): array
    {
        return [
            'taxable' => $request->boolean('taxable'),
            'tax_rate' => round((float) $request->input('tax_rate', 0) / 100, 4),
        ];
    }

    // ================= التقارير =================

    public function reports(Request $request)
    {
        $tab = $request->string('tab')->value() ?: 'aging';

        // ⚠️ سكوب التشانل مانجر (2026-08-05): كل جداول التقارير من عملائه بس
        $vis = fn ($q) => Client::visibleTo($q);

        return view('erp.reports', [
            'tab' => $tab,
            'aging' => $this->agingTotals(),
            // ⚠️ `contract` و`group.contract` لازم eager — `overdue()` بتنادي
            // `liveContract()` لكل صف، والـ25 صف كانوا بيعملوا 50 كويري.
            'topDebt' => $vis(Client::with([
                'transactions' => fn ($q) => $q->where('debit', '>', 0),
                'contract', 'group.contract',
            ]))->orderByDesc('balance')->take(25)->get(),
            'returns' => $vis(Client::where('returns', '>', 0))->orderByDesc('returns')->get(),
            'rebates' => $vis(Client::whereRaw('(rebates + settlements) > 0'))
                ->orderByRaw('(rebates + settlements) DESC')->get(),
            'circleK' => $vis(Client::where('name', 'like', 'Circle K%'))->orderByDesc('purchases')->get(),
            // ⚠️ الفيو بينادي hasContract() لكل صف → liveContract() → العقد والسلسلة
            'risk' => $vis(Client::with(['contract', 'group.contract'])
                ->where('balance', '>', 50000)
                ->whereRaw('collections < purchases * 0.5'))
                ->orderByDesc('balance')->get(),
            'credit' => $vis(Client::where('balance', '<', -1))->orderBy('balance')->get(),
        ]);
    }

    // ================= الفريق =================

    /**
     * تغيير باسورد يوزر — من شاشة الفريق.
     *
     * ⚠️ **السيستم مايعرفش الباسورد الحالي** (متخزن مشفّر) — فمفيش
     * «عرض الباسورد»، فيه تعيين واحد جديد وبس. اللي نسي بيتعمله
     * واحد جديد من هنا.
     */
    public function setPassword(Request $request, User $user)
    {
        $data = $request->validate([
            // ⚠️ `confirmed` — غلطة كتابة في باسورد بيتكتب مرة واحدة
            // بتقفل الحساب من غير ما حد يعرف الحرف اللي اتكتب غلط.
            'password' => ['required', 'string', 'min:8', 'max:100', 'confirmed'],
        ]);

        // ⚠️ من غير `Hash::make` — كاست `hashed` على الموديل بيشفّر
        // بنفسه، والتشفير المزدوج كان هيخلّي الباسورد الجديد مايشتغلش.
        $user->update(['password' => $data['password']]);

        // ⚠️ **توكينات الأبلكيشن بتتلغي.** تغيير الباسورد غالباً سببه
        // إن الجهاز ضاع أو الموظف مشي — لو التوكن القديم فضل شغال،
        // التغيير مالوش أي لازمة: الأبلكيشن القديم لسه داخل عادي.
        $user->tokens()->delete();

        return back()->with('ok', __('team.password_changed', ['name' => $user->displayName()]));
    }

    /** قواعد فورم اليوزر — مصدر واحد للإضافة والتعديل */
    private function userRules(?User $user = null): array
    {
        return [
            'name' => ['required', 'string', 'max:190'],
            // ⚠️ **إجباري على السيرفر كمان** (2026-08-08). الفورم عليه
            // `data-req` ونجمة، والسيرفر كان `nullable` — يعني المتصفح
            // بيمنع واللي بيبعت من غير المتصفح (استيراد، tinker، فورم
            // معدّل) بيعدّي. والإنجليزي هو الافتراضي في كل الشاشات
            // والمطبوعات، فعميل من غيره اسمه بيطلع فاضي قدام العميل.
            'name_en' => ['required', 'string', 'max:190'],
            'email' => ['required', 'email', 'max:190',
                \Illuminate\Validation\Rule::unique('users', 'email')->ignore($user?->id)],
            'code' => ['nullable', 'string', 'max:30',
                \Illuminate\Validation\Rule::unique('users', 'code')->ignore($user?->id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'role' => ['required', \Illuminate\Validation\Rule::in(array_keys(User::ROLES))],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'zone_id' => ['nullable', 'exists:zones,id'],
            // ⚠️ أمين المخزن من غير مخزن مايعرفش يفتح أي شاشة شغل —
            // كل شاشات `wh.` بتفلتر بمخزنه.
            'warehouse_id' => ['nullable', 'required_if:role,warehouse_keeper', 'exists:warehouses,id'],
        ];
    }

    public function storeUser(Request $request)
    {
        $data = $request->validate($this->userRules() + [
            'password' => ['required', 'string', 'min:8', 'max:100'],
        ]);

        // الكاست `hashed` على الموديل بيشفّر الباسورد بنفسه
        User::create($data
            + $this->avatarData($request)
            + ['active' => $request->boolean('active', true)]);

        return back()->with('ok', __('team.user_added', ['name' => $data['name']]));
    }

    public function updateUser(Request $request, User $user)
    {
        $data = $this->guardSelfEdit($request, $user,
            $request->validate($this->userRules($user)));

        $user->update($data
            + $this->avatarData($request, $user)
            + ['active' => $request->boolean('active')]);

        return back()->with('ok', __('team.user_updated', ['name' => $user->displayName()]));
    }

    /**
     * صورة الموظف من فورم الفريق (٩ أغسطس ٢٠٢٦) — نفس العمود اللي
     * الأبلكيشن بيرفع عليه من «حسابي». القديمة بتتمسح من الديسك
     * عشان مايتكوّمش ملفات يتيمة مع كل تغيير.
     */
    private function avatarData(Request $request, ?User $user = null): array
    {
        $file = $request->file('avatar');

        if (! $file) {
            return [];
        }

        $request->validate(['avatar' => ['image', 'max:4096']]);

        if ($user?->avatar_path) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($user->avatar_path);
        }

        return ['avatar_path' => $file->store('avatars', 'public')];
    }

    /**
     * ⚠️ **الأدمن مايقدرش يقفل على نفسه.** تعديل رول نفسه أو إيقاف
     * حسابه كان بيخرجه من السيستم في نفس الثانية — ومفيش حد تاني
     * يرجّعه. الرول والحالة بتاعته بيتسابوا زي ما هم.
     */
    private function guardSelfEdit(Request $request, User $user, array $data): array
    {
        if ($user->id === $request->user()->id) {
            unset($data['role']);
            $request->merge(['active' => true]);
        }

        return $data;
    }

    /**
     * صفحة إدارة المناطق والمحافظات.
     *
     * المحافظات بقت داتابيز (2026-08-05): تعديل الأسماء عربي/إنجليزي
     * وإضافة محافظات جديدة من نفس الشاشة — والمناطق جواها زي ما هي:
     * منطقة جديدة، تعديل اسمها، نقلها لمحافظة، أو إيقافها.
     */
    public function zones(Request $request)
    {
        return view('erp.zones', [
            'zones' => \App\Models\Branch::scope(
                Zone::withCount([
                    'clients as active_clients' => fn ($q) => $q->where('status', 'active'),
                    'clients',
                ])->with('users:id,name,name_en,zone_id'),
                $request->user(),
            )->get(),
            'govRows' => \App\Models\Governorate::orderBy('sort')->orderBy('id')->get(),
        ]);
    }

    /** محافظة جديدة — المفتاح slug ثابت من الاسم الإنجليزي */
    public function storeGovernorate(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'name_en' => ['required', 'string', 'max:120'],
            'capital' => ['nullable', 'string', 'max:120'],
            'capital_en' => ['nullable', 'string', 'max:120'],
            'region' => ['nullable', 'string', 'max:120'],
            'region_en' => ['nullable', 'string', 'max:120'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $key = \Illuminate\Support\Str::slug($data['name_en'], '_');

        if ($key === '' || \App\Models\Governorate::where('key', $key)->exists()) {
            return back()->withErrors(['name_en' => __('geo.gov_key_taken')]);
        }

        \App\Models\Governorate::create($data + [
            'key' => $key,
            'sort' => (int) \App\Models\Governorate::max('sort') + 1,
            'active' => true,
        ]);

        \App\Support\Governorates::flush();

        return back()->with('ok', __('geo.gov_saved'));
    }

    /**
     * تعديل أسماء محافظة — **المفتاح مابيتغيرش.** العملاء والمناطق
     * متخزن عليهم المفتاح، وتغييره بيسيبهم على مفتاح ميت.
     */
    public function updateGovernorate(Request $request, \App\Models\Governorate $governorate)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'name_en' => ['required', 'string', 'max:120'],
            'capital' => ['nullable', 'string', 'max:120'],
            'capital_en' => ['nullable', 'string', 'max:120'],
            'region' => ['nullable', 'string', 'max:120'],
            'region_en' => ['nullable', 'string', 'max:120'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $governorate->update($data);
        \App\Support\Governorates::flush();

        return back()->with('ok', __('geo.gov_saved'));
    }

    public function updateZone(Request $request, Zone $zone)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            // ⚠️ **إجباري على السيرفر كمان** (2026-08-08). الفورم عليه
            // `data-req` ونجمة، والسيرفر كان `nullable` — يعني المتصفح
            // بيمنع واللي بيبعت من غير المتصفح (استيراد، tinker، فورم
            // معدّل) بيعدّي. والإنجليزي هو الافتراضي في كل الشاشات
            // والمطبوعات، فعميل من غيره اسمه بيطلع فاضي قدام العميل.
            'name_en' => ['required', 'string', 'max:190'],
            'governorate' => ['nullable', Governorates::rule()],
            'day_label' => ['nullable', 'string', 'max:60'],
            'type' => ['nullable', 'string', 'max:40'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        // ⚠️ **الإيقاف مرفوض والمنطقة عليها عملاء شغّالين** — المنطقة
        // الموقوفة بتختفي من قوايم الاختيار، وعملاؤها كانوا هيفضلوا
        // متسكّنين على حاجة محدش يقدر يختارها تاني.
        $active = $request->boolean('active', true);

        if (! $active && $zone->clients()->where('status', 'active')->exists()) {
            return back()->withErrors([
                'active' => __('team.zone_has_active_clients', [
                    'count' => $zone->clients()->where('status', 'active')->count(),
                ]),
            ]);
        }

        $zone->update($data + ['active' => $active]);

        return back()->with('ok', __('team.zone_updated', ['name' => $zone->displayName()]));
    }

    /** منطقة جديدة من شاشة الفريق — الكود بيتولّد زي `quickZone` */
    public function storeZone(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            // ⚠️ **إجباري على السيرفر كمان** (2026-08-08). الفورم عليه
            // `data-req` ونجمة، والسيرفر كان `nullable` — يعني المتصفح
            // بيمنع واللي بيبعت من غير المتصفح (استيراد، tinker، فورم
            // معدّل) بيعدّي. والإنجليزي هو الافتراضي في كل الشاشات
            // والمطبوعات، فعميل من غيره اسمه بيطلع فاضي قدام العميل.
            'name_en' => ['required', 'string', 'max:190'],
            'governorate' => ['nullable', Governorates::rule()],
            'day_label' => ['nullable', 'string', 'max:60'],
            'type' => ['nullable', 'string', 'max:40'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $base = 'Z'.str_pad((string) (Zone::count() + 1), 2, '0', STR_PAD_LEFT);
        $code = $base;
        $n = 2;

        while (Zone::where('code', $code)->exists()) {
            $code = $base.'-'.$n++;
        }

        Zone::create($data + [
            'code' => $code,
            'branch_id' => $request->user()->seesAllBranches() ? null : $request->user()->branch_id,
            'active' => true,
        ]);

        return back()->with('ok', __('team.zone_added', ['name' => $data['name']]));
    }

    public function team(Request $request)
    {
        // ⚠️ سكوب الفرع — مدير المعادي بيشوف فريق المعادي بس
        return view('erp.team', [
            'users' => \App\Models\Branch::scope(
                User::with(['zone', 'branch']), $request->user(),
            )->orderBy('role')->get(),
            'branches' => \App\Models\Branch::where('active', true)->orderBy('code')->get(),
            'warehouses' => \App\Models\Warehouse::where('active', true)->orderBy('code')->get(),
            'zones' => \App\Models\Branch::scope(Zone::query(), $request->user())
                ->orderBy('code')->get(),
            // العربية المخصصة لكل واحد — كويري واحدة مش لوب
            'vehicles' => \App\Models\Vehicle::where('active', true)->get(),
        ]);
    }
}
