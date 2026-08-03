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

    public function overview()
    {
        // ملاحظة: `returns` كلمة محجوزة في MySQL — لازم backticks
        $totals = Client::query()->selectRaw('
            SUM(`purchases`) as purchases,
            SUM(`collections`) as collections,
            SUM(`returns`) as total_returns,
            SUM(`rebates`) as rebates,
            SUM(`settlements`) as settlements,
            SUM(`balance`) as balance,
            COUNT(*) as n_clients
        ')->first();

        $byFamily = DB::table('invoice_items')
            ->join('products', 'products.id', '=', 'invoice_items.product_id')
            ->selectRaw('products.family, SUM(invoice_items.total) as amt')
            ->groupBy('products.family')
            ->pluck('amt', 'family')
            ->all();

        // لو لسه مفيش فواتير في السيستم، نعرض توزيع المخزون بدلها
        if (empty($byFamily)) {
            $byFamily = DB::table('stocks')
                ->join('products', 'products.id', '=', 'stocks.product_id')
                ->selectRaw('products.family, SUM(stocks.qty * products.price_new) as amt')
                ->groupBy('products.family')
                ->pluck('amt', 'family')
                ->all();
        }

        $monthly = Transaction::query()
            ->selectRaw("DATE_FORMAT(date, '%Y-%m') as m,
                         SUM(CASE WHEN kind = 'sale' THEN debit ELSE 0 END) as sales,
                         SUM(CASE WHEN kind = 'collection' THEN credit ELSE 0 END) as coll")
            ->groupBy('m')->orderBy('m')->get();

        $catCounts = Client::query()
            ->selectRaw('category, COUNT(*) as n')
            ->groupBy('category')->pluck('n', 'category')->all();

        return view('erp.overview', [
            'totals' => $totals,
            'byFamily' => $byFamily,
            'monthly' => $monthly,
            'catCounts' => $catCounts,
            'aging' => $this->agingTotals(),
            // ⚠️ `group` و`zone` eager — `fullName()` بتقرا السلسلة لكل
            // صف، ومن غيرها 15 صف = 15 كويري زيادة.
            'top' => Client::with(['group', 'zone'])->orderByDesc('purchases')->take(15)->get(),
            'stockValue' => Stock::join('products', 'products.id', '=', 'stocks.product_id')
                ->sum(DB::raw('stocks.qty * products.price_new')),
            'todayInvoices' => Invoice::whereDate('created_at', today())->sum('total'),
            'todayPos' => PurchaseOrder::whereDate('delivered_at', today())->sum('total'),
            'openRequests' => \App\Models\ClientRequest::whereIn('status', ['pending', 'review'])->count(),
        ]);
    }

    /** أعمار المديونية إجمالاً (تقديري FIFO) */
    private function agingTotals(): array
    {
        $t = ['a30' => 0.0, 'a60' => 0.0, 'a90' => 0.0, 'a180' => 0.0, 'a180p' => 0.0];

        Client::where('balance', '>', 0)
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
        $q = \App\Models\Branch::scope(
            Client::query()->with(['zone', 'contract', 'group.contract']),
        );

        // ⚠️ **الافتراضي الكل مش الشغّال بس.** بعد استيراد الـ455،
        // معظم القايمة `pending` — لو خبّيناهم افتراضياً المستخدم
        // بيدوّر على عميل لسه مفعّلوش ومش بيلاقيه ويفتكر الاستيراد
        // ضاع. الفلتر بيوريه اللي عايزه، والشارة على الصف بتفرّق.
        if ($st = $request->string('status')->value()) {
            $q->where('status', $st);
        }

        if ($s = $request->string('q')->trim()->value()) {
            $q->where(fn ($w) => $w->where('name', 'like', "%$s%")
                ->orWhere('phone', 'like', "%$s%")
                ->orWhere('code', 'like', "%$s%"));
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

        if ($request->string('contract')->value() === 'yes') {
            $hasAny($q);
        } elseif ($request->string('contract')->value() === 'no') {
            $q->whereDoesntHave('contract', $liveContract)
                ->whereDoesntHave('group.contract', $liveContract);
        }

        return view('erp.clients', [
            // ⚠️ فورم الإضافة اتنقل لصفحة مستقلة (`erp.clients.new`)،
            // فالقايمة دي مابقتش محتاجة الفروع والسلاسل والمناديب.
            // سيبانهم هنا كان بيحمّل 4 كويريز في كل صفحة من غير ما
            // حد يستخدمهم.
            'clients' => $q->with('channel')->orderByDesc('purchases')
                ->paginate(40)->withQueryString(),
            'zones' => Zone::orderBy('code')->get(),
            'channels' => \App\Models\Channel::orderBy('id')->get(),
            'catCounts' => Client::selectRaw('category, COUNT(*) as n')
                ->groupBy('category')->pluck('n', 'category')->all(),
            'channelCounts' => Client::selectRaw('channel_id, COUNT(*) as n')
                ->groupBy('channel_id')->pluck('n', 'channel_id')->all(),
            'filters' => $request->only(['q', 'cat', 'zone', 'gov', 'contract', 'channel', 'sub', 'status']),
            // ⚠️ بنفس سكوب الفرع بتاع القايمة — عداد بيقول 455 وقايمة
            // بتوري 80 بيخلّي مدير الفرع يفتكر في حاجة مخفية عنه.
            'statusCounts' => \App\Models\Branch::scope(Client::query())
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
            ->whereIn('role', User::MANAGER_ROLES)
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

        $client->load([
            'zone', 'channel', 'rep',
            'contract.contractClauses', 'group.contract.contractClauses',
            'invoices.items.product', 'invoices.items.batch', 'visits.user',
        ]);

        // ⚠️ العقد الفعّال ممكن يكون موروث من السلسلة — بنحسبه هنا مرة واحدة
        // بدل ما الفيو ينادي liveContract() في كل سطر.
        $contract = $client->liveContract();

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
            'txns' => $client->transactions()->orderByDesc('date')->paginate(60),
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
        ]);
    }

    public function updateClient(Request $request, Client $client)
    {
        // ⚠️ نفس حارس كارت العميل — من غيره مدير فرع بيبعت PUT على
        // عميل فرع تاني ويغيّر خصمه، والفلترة في القايمة مابتوقفوش.
        abort_unless($request->user()->canSeeBranch($client->branch_id), 403);

        $data = $this->guardBranch($request, $request->validate($this->clientRules()), creating: false);
        $this->checkContractDuration($data);

        DB::transaction(function () use ($data, $request, $client) {
            $client->update($this->clientFields($data));
            $this->syncContract($client, $data, $request);
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
            'name_en' => ['nullable', 'string', 'max:190'],
            'name' => ['required', 'string', 'max:190'],
            'phone' => ['nullable', 'string', 'max:30'],
            'governorate' => ['nullable', Governorates::rule()],
            'address' => ['nullable', 'string', 'max:190'],
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
            'channel_id' => ['nullable', 'exists:channels,id'],
            'group_id' => ['nullable', 'exists:client_groups,id'],
            'sub_channel' => ['nullable', 'in:chain,convenience'],
            // ⚠️ **مش في فورم العميل الجديد.** التصنيف نتيجة سلوك مش
            // مدخل: بيدفع في مواعيده ولا لأ، بيكبر ولا لأ. تحديده وقت
            // التعريف تخمين بيتحوّل لحقيقة في الشاشة — عميل يتعلّم
            // «تحصيل فوري» من يومه الأول ويتقفل عليه الآجل من غير
            // أي سبب. بيتظبط من كارت العميل بعد أول تعاملات.
            // ⚠️ من الثابت مباشرة — القايمة كانت مكتوبة بالنص هنا وفي
            // الفيو، فإضافة تصنيف جديد كانت بتوري أوبشن الفاليديشن
            // يرفضها.
            'category' => ['nullable', Rule::in(array_keys(Client::CATEGORIES))],
            // كاش/آجل — فاضي = حسب القناة، و`danger` كاش إجباري
            'payment_terms' => ['nullable', 'in:cash,credit'],
            'discount' => ['required', 'numeric', 'min:0', 'max:100'],
            // قائمة السعر اللي العميل بيتحاسب بيها — إجبارية
            'price_list' => ['required', 'in:old,new'],
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
            'contract_starts_at' => ['nullable', 'date'],
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

        $data = $this->guardBranch($request, $data, creating: true);

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

            return $client;
        });

        return redirect()->route('erp.clients.show', $client)
            ->with('ok', __('flash.client_added'));
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
            'terms' => ($data['contract_payment_days'] ?? null)
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
        $all = Contract::with(['client.zone', 'group', 'contractClauses'])->get()
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
            'totalPurch' => (float) Client::sum('purchases'),
            'clientsCount' => Client::count(),
            // ⚠️ "من غير عقد" = مفيش صف عقد سارٍ. ممنوع نستنتجها من discount،
            // لأن العميل ممكن ياخد خصم من القناة أو السلسلة وهو من غير عقد،
            // وساعتها كان بيختفي من القايمة ومكنّاش نعرف نكتب له عقد.
            'noContract' => Client::whereDoesntHave('contract', $this->liveContractScope())
                ->where('category', '!=', 'internal')
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

        // البنود متقسّمة لمجموعات عرض: المهم فوق والتفاصيل تحت
        $clauses = $contract->contractClauses;

        $bucket = fn (array $kinds) => $clauses
            ->whereIn('kind', $kinds)
            ->sortByDesc(fn ($c) => (float) ($c->pct ?? 0))
            ->values();

        // الفروع اللي العقد بيغطيها — عقد السلسلة بيغطي كل فروعها
        $branches = $contract->group_id
            ? Client::where('group_id', $contract->group_id)->orderBy('name')->get()
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
                ? Client::orderBy('code')->get(['id', 'code', 'name', 'name_en'])
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

        $products = $q->orderBy('code')->get();
        $all = Product::with('stocks')->get();

        return view('erp.stock', [
            'products' => $products,
            'families' => Product::FAMILIES,
            // ⚠️ **المفعّل + أي موقوف لسه فيه رصيد.**
            // لو عرضنا المفعّل بس، بضاعة قاعدة في مخزن اتوقف بتختفي
            // من الأعمدة بينما `qtyTotal()` بتعدّها — فمجموع الأعمدة
            // مايساويش عمود «الكمية كلها» ومحدش يعرف الفرق راح فين.
            // المخزن الموقوف اللي رصيده صفر مابيظهرش، فالجدول
            // مابيتوسّعش من غير داعي.
            'warehouses' => \App\Models\Warehouse::query()
                ->where(fn ($w) => $w->where('active', true)
                    ->orWhereHas('stocks', fn ($s) => $s->where('qty', '>', 0)))
                ->orderBy('type')->orderBy('code')->get(),
            'filters' => $request->only(['q', 'family']),
            // ⚠️ كل الـ KPIs دي على $all (المخزن كله) — ممنوع تخلط واحد منهم
            // مع رقم محسوب من $products المفلترة، الهامش يطلع غلط.
            'totalVal' => $all->sum(fn ($p) => $p->qtyTotal() * $p->sellingPrice()),
            'costVal' => $all->sum(fn ($p) => $p->qtyTotal() * (float) $p->cost),
            'skuCount' => $all->count(),
            'holdVal' => $all->sum(fn ($p) => $p->holdTotal() * $p->sellingPrice()),
            'goodVal' => $all->sum(fn ($p) => $p->goodTotal() * $p->sellingPrice()),
            'totalQty' => $all->sum(fn ($p) => $p->qtyTotal()),
            'famStats' => $all->groupBy('family')->map(fn ($g) => [
                'n' => $g->count(),
                'qty' => $g->sum(fn ($p) => $p->qtyTotal()),
                'val' => $g->sum(fn ($p) => $p->qtyTotal() * $p->sellingPrice()),
                'hold' => $g->sum(fn ($p) => $p->holdTotal() * $p->sellingPrice()),
            ])->all(),
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
            'name_en' => ['nullable', 'string', 'max:190'],
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
            'cost' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'price_old' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'price_new' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'taxable' => ['nullable', 'boolean'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],

            'active' => ['nullable', 'boolean'],
            'image' => \App\Services\ProductImage::rule(),
        ];
    }

    /** الحقول اللي مش أعمدة في `products` */
    private const PRODUCT_NOT_COLUMNS = [
        'qty', 'hold_qty', 'warehouse_id', 'taxable', 'tax_rate', 'image', 'active',
    ];

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
            'families' => Product::FAMILIES,
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
        $data = $request->validate($this->productRules() + [
            'code' => ['required', 'string', 'max:20', 'unique:products,code'],
            'family' => ['required', 'string', 'max:40'],
        ]);

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

            // عمود ← قايمة، والاتجاه العكسي في شاشة التسعير
            \App\Services\Pricing::syncColumnsToLists($product);

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

            // عمود ← قايمة، والاتجاه العكسي في شاشة التسعير
            \App\Services\Pricing::syncColumnsToLists($product->refresh());
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

        return view('erp.reports', [
            'tab' => $tab,
            'aging' => $this->agingTotals(),
            // ⚠️ `contract` و`group.contract` لازم eager — `overdue()` بتنادي
            // `liveContract()` لكل صف، والـ25 صف كانوا بيعملوا 50 كويري.
            'topDebt' => Client::with([
                'transactions' => fn ($q) => $q->where('debit', '>', 0),
                'contract', 'group.contract',
            ])->orderByDesc('balance')->take(25)->get(),
            'returns' => Client::where('returns', '>', 0)->orderByDesc('returns')->get(),
            'rebates' => Client::whereRaw('(rebates + settlements) > 0')
                ->orderByRaw('(rebates + settlements) DESC')->get(),
            'circleK' => Client::where('name', 'like', 'Circle K%')->orderByDesc('purchases')->get(),
            // ⚠️ الفيو بينادي hasContract() لكل صف → liveContract() → العقد والسلسلة
            'risk' => Client::with(['contract', 'group.contract'])
                ->where('balance', '>', 50000)
                ->whereRaw('collections < purchases * 0.5')
                ->orderByDesc('balance')->get(),
            'credit' => Client::where('balance', '<', -1)->orderBy('balance')->get(),
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
            'name_en' => ['nullable', 'string', 'max:190'],
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
        User::create($data + ['active' => $request->boolean('active', true)]);

        return back()->with('ok', __('team.user_added', ['name' => $data['name']]));
    }

    public function updateUser(Request $request, User $user)
    {
        $data = $this->guardSelfEdit($request, $user,
            $request->validate($this->userRules($user)));

        $user->update($data + ['active' => $request->boolean('active')]);

        return back()->with('ok', __('team.user_updated', ['name' => $user->displayName()]));
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
     * صفحة إدارة المناطق — مجمّعة بالمحافظات.
     *
     * ⚠️ المحافظات الـ27 ثابتة في `Governorates::KEYS` — مش بتتعمل
     * من الشاشة. اللي بيتعمل هو المناطق جواها: منطقة جديدة في
     * محافظة، تعديل اسمها (عربي + إنجليزي)، نقلها لمحافظة تانية،
     * أو إيقافها.
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
        ]);
    }

    public function updateZone(Request $request, Zone $zone)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'name_en' => ['nullable', 'string', 'max:190'],
            'governorate' => ['nullable', Governorates::rule()],
            'day_label' => ['nullable', 'string', 'max:60'],
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
            'name_en' => ['nullable', 'string', 'max:190'],
            'governorate' => ['nullable', Governorates::rule()],
            'day_label' => ['nullable', 'string', 'max:60'],
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
