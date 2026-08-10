<?php

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Models\Client;
use App\Models\ClientRequest;
use App\Models\Invoice;
use App\Models\PickOrder;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\TrackEvent;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\Zone;
use App\Support\Scope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * العمليات الميدانية — المناديب، العهدة، أوامر التوريد، موافقات العملاء، التراكينج
 */
class OpsController extends Controller
{
    // ================= لوحة العمليات =================

    public function dashboard()
    {
        // ⚠️ سكوب الفرع على لوحة العمليات
        $field = \App\Models\Branch::scope(
            User::fieldVisibleTo(User::whereIn('role', User::FIELD_ROLES)->with('zone')),
        )->get();

        // ⚠️ أرقام اللوحة من نفس الفريق المعروض — للمدير ده فريقه بس،
        // وللأدمن كل الميدان. رقم فوق وكروت تحت من نطاقين = شاشة بتكدب.
        $teamIds = $field->pluck('id');

        return view('ops.dashboard', [
            'field' => $field->map(fn ($u) => $this->userStats($u)),
            'todaySales' => Invoice::whereIn('user_id', $teamIds)->whereDate('created_at', today())->sum('total'),
            'todayPos' => PurchaseOrder::whereIn('assigned_to', $teamIds)->whereDate('delivered_at', today())->sum('total'),
            'openRequests' => ClientRequest::whereIn('created_by', $teamIds)->whereIn('status', ['pending', 'review'])->count(),
            'visitsDone' => DB::table('visits')->whereIn('user_id', $teamIds)->whereDate('created_at', today())
                ->whereNotNull('checked_out_at')->count(),
            'events' => TrackEvent::with('user')->whereIn('user_id', $teamIds)->whereDate('happened_at', today())
                ->orderByDesc('happened_at')->take(30)->get(),
        ]);
    }

    private function userStats(User $u): array
    {
        $custody = $u->currentCustody();
        $custody?->load('items.product');

        return [
            'user' => $u,
            'custody' => $custody,
            'remaining' => $custody?->remainingUnits() ?? 0,
            'remainingValue' => $custody?->remainingValue($u->isDriver() ? 'old' : 'new') ?? 0,
            'sales' => Invoice::where('user_id', $u->id)->whereDate('created_at', today())->sum('total'),
            'visits' => $u->visits()->whereDate('created_at', today())->count(),
            'visitsDone' => $u->visits()->whereDate('created_at', today())->whereNotNull('checked_out_at')->count(),
            'pos' => PurchaseOrder::where('assigned_to', $u->id)->whereDate('created_at', today())->count(),
            'posDone' => PurchaseOrder::where('assigned_to', $u->id)->where('status', 'delivered')
                ->whereDate('delivered_at', today())->count(),
            // ⚠️ «قيمة التسليمات» = اللي السواق حصّله فعلاً، فبالإجمالي
            // شامل الضريبة. الصافي مكانه تقارير المبيعات.
            'posValue' => PurchaseOrder::where('assigned_to', $u->id)->where('status', 'delivered')
                ->whereDate('delivered_at', today())->sum('grand_total'),
            'openVisit' => $u->openVisit(),
        ];
    }

    public function rep(Request $request, User $user)
    {
        // ⚠️ نفس القاعدة: الشاشة بتوري عهدة المندوب وفواتيره وتحركاته
        abort_unless($request->user()->canSeeBranch($user->branch_id), 403);
        // ⚠️ وسكوب التشانل مانجر — مندوب مش من فريقه مايتفتحش بالـid
        abort_unless($request->user()->role !== 'manager'
            || (int) $user->manager_id === (int) $request->user()->id, 403);

        $custody = $user->currentCustody();
        $custody?->load('items.product');

        return view('ops.rep', [
            'u' => $user,
            'stats' => $this->userStats($user),
            'custody' => $custody,
            'invoices' => Invoice::with('client')->where('user_id', $user->id)
                ->latest()->take(30)->get(),
            'visits' => $user->visits()->with('client')->take(30)->get(),
            'events' => $user->trackEvents()->whereDate('happened_at', today())->get(),
            'products' => Product::orderBy('code')->get(),
        ]);
    }

    // ================= العهدة =================

    // ⚠️ `loadVan` (التحميل المباشر) **اتشال** (قرار المالك 2026-08-03):
    // كان بيجهّز ويسلّم في نفس الثانية من غير استلام المندوب من
    // الأبلكيشن — التحميل الرسمي بقى من فلو تسليم العهدة:
    // CustodyHandoutController::store ← تجهيز الطلبات ← تأكيد ← استلام.

    public function closeCustody(Request $request, User $user)
    {
        // ⚠️ **كان بلا حارس** — أي مدير بيقفل يوم أي مندوب في الشركة،
        // والمندوب بيلاقي عهدته اتقفلت وهو لسه في الشارع.
        Scope::assertRep($request->user(), $user);

        $custody = $user->currentCustody();
        $custody?->update(['status' => 'closed', 'closed_at' => now()]);

        return back()->with('ok', __('flash.van_closed'));
    }

    // ================= أوامر التوريد =================

    /**
     * لوحة التوريد (كي أكاونت + أونلاين) — 2026-08-05: KPIs بحالات
     * الموافقة والتسليم والتأخير + فلاتر (بحث بالفرع، قناة، سلسلة،
     * موافقة، حالة، تواريخ) — والـKPIs بنفس فلاتر القايمة (نطاق واحد).
     */
    public function purchaseOrders(Request $request)
    {
        // ⚠️ سكوب التشانل مانجر: أوامر عملائه بس
        $u = auth()->user();

        // الفلاتر المشتركة (من غير الحالة) — الأساس للقايمة والـKPIs
        $base = fn () => PurchaseOrder::query()
            ->when($u?->role === 'manager',
                fn ($q2) => $q2->whereIn('client_id', Client::visibleTo(Client::query(), $u)->select('id')))
            ->when($request->string('q')->trim()->value(), function ($q2, $s) {
                $q2->where(fn ($w) => $w->where('number', 'like', "%$s%")
                    ->orWhere('source', 'like', "%$s%")
                    ->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%$s%")
                        ->orWhere('name_en', 'like', "%$s%")
                        ->orWhere('code', 'like', "%$s%")));
            })
            ->when($request->integer('channel'),
                fn ($q2, $ch) => $q2->whereHas('client', fn ($c) => $c->where('channel_id', $ch)))
            ->when($request->integer('group'),
                fn ($q2, $g) => $q2->whereHas('client', fn ($c) => $c->where('group_id', $g)))
            ->when($request->string('from')->value(), fn ($q2, $d) => $q2->whereDate('created_at', '>=', $d))
            ->when($request->string('to')->value(), fn ($q2, $d) => $q2->whereDate('created_at', '<=', $d));

        // «متأخر» = عدّى معاده ولسه ماتسلمش (والمرفوض مش متأخر — اتقفل)
        $lateScope = fn ($q2) => $q2->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->where('status', '!=', 'delivered')
            ->where(fn ($w) => $w->whereNull('approval_status')->orWhere('approval_status', '!=', 'rejected'));

        $q = $base()->with(['client.channel', 'courier', 'items', 'creator', 'approvedBy', 'editor']);

        if ($status = $request->string('status')->value()) {
            $q->where('status', $status);
        }
        if ($approval = $request->string('approval')->value()) {
            $q->where('approval_status', $approval);
        }
        if ($request->boolean('late')) {
            $lateScope($q);
        }

        return view('ops.pos', [
            'pos' => $q->latest()->paginate(30)->withQueryString(),
            // ⚠️ كل الأرقام من نفس الأساس المفلتر — رقم فوق وجدول تحت
            // من نطاقين = شاشة بتكدب
            'kpi' => [
                'total' => $base()->count(),
                'pending' => $base()->where('approval_status', 'pending')->count(),
                'approved' => $base()->where('approval_status', 'approved')->count(),
                'rejected' => $base()->where('approval_status', 'rejected')->count(),
                'delivered' => $base()->where('status', 'delivered')->count(),
                'late' => $lateScope($base())->count(),
                'value' => (float) $base()->where(fn ($w) => $w->whereNull('approval_status')
                    ->orWhere('approval_status', '!=', 'rejected'))->sum('grand_total'),
            ],
            'channels' => \App\Models\Channel::orderBy('id')->get(),
            'groups' => \App\Models\ClientGroup::whereHas('clients')->orderBy('name')->get(['id', 'name', 'name_en']),
            // دايالوج الأساين بس — دايالوج الإنشاء اليدوي اتشال (2026-08-06)
            'couriers' => User::fieldVisibleTo(User::where('role', 'driver'))->get(),
            'filters' => $request->only(['status', 'approval', 'late', 'q', 'channel', 'group', 'from', 'to']),
        ]);
    }

    public function storePurchaseOrder(Request $request)
    {
        $data = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'source' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:190'],
            'assigned_to' => ['nullable', 'exists:users,id'],
            'price_mode' => ['required', 'in:channel,old,new'],
            'due_date' => ['nullable', 'date'],
            // ═══ فلو الكي أكاونت (2026-08-04): موعد بالساعة + مخزن
            // التجهيز + فلاج «محتاج موافقة الحسابات» ═══
            // ⚠️ **معادين مختلفين — الخلط بينهم بيوقّف المندوب.**
            // `due_at` = البضاعة توصل **الفرع** إمتى.
            // `pickup_at` = المندوب ييجي **المخزن** ياخدها إمتى.
            // ممكن يبقى بينهم أيام (خد بكره، سلّم بعد 3 أيام).
            'due_at' => ['nullable', 'date'],
            'pickup_at' => ['nullable', 'date'],
            'warehouse_id' => ['nullable', 'exists:warehouses,id'],
            // ⚠️ **صورة أمر الشراء الأصلي** (طلب المالك ٨/٨/٢٠٢٦) —
            // صورة أو PDF من ورقة السلسلة. `max` بالكيلوبايت.
            'image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'],
            'approval' => ['nullable', 'boolean'],
            'qty' => ['required', 'array'],
            'qty.*' => ['nullable', 'integer', 'min:0'],
            'unit' => ['nullable', 'array'],
            'unit.*' => ['nullable', 'in:piece,box,case'],
        ]);

        // ⚠️ فلو الموافقة لازم له مندوب ومخزن — التجهيز بيتعمل منهم
        // وقت موافقة الحسابات، مش وقت الإنشاء.
        if ($request->boolean('approval') && (empty($data['assigned_to']) || empty($data['warehouse_id']))) {
            return back()->withErrors(['assigned_to' => __('ops.po_needs_rep_wh')])->withInput();
        }

        // ⚠️ **وحدة الإدخال بتتضرب هنا مش في الجافاسكريبت.** «5 كراتين»
        // بتتخزن 60 قطعة على بنود الأمر — والتسعير بالقطعة زي ما هو.
        // وحدة مش معرّفة للصنف = رفض الأمر كله، مش افتراض قطعة.
        foreach ($request->input('unit', []) as $productId => $unit) {
            if (! $unit || $unit === 'piece' || empty($data['qty'][$productId])) {
                continue;
            }

            $factor = Product::find($productId)?->unitFactor($unit);

            if ($factor === null) {
                return back()->withErrors([
                    'qty' => __('stock.unit_not_for_product', ['name' => Product::find($productId)?->displayName() ?? $productId]),
                ])->withInput();
            }

            $data['qty'][$productId] = (int) $data['qty'][$productId] * $factor;
        }

        $needsApproval = $request->boolean('approval');

        // ⚠️ **الرفع قبل الترانزاكشن** — كتابة الملف على الديسك مش
        // جزء من ترانزاكشن الداتابيز، ولو حصلت جواها وحصل rollback
        // بيفضل ملف يتيم. ولو الرفع نفسه فشل، مايتعملش أمر أصلاً.
        $imagePath = $request->hasFile('image')
            ? $request->file('image')->store('po-images', 'public')
            : null;

        try {
        // ⚠️ `$imagePath` لازم في الـ`use` — الرفع بيحصل قبل الترانزاكشن
        // (عشان الـrollback مايسيبش ملف يتيم)، والكلوجر من غيره بترمي
        // «Undefined variable $imagePath» **بس لما يكون فيه صورة فعلاً**
        // — عشان كده الباج عدّى من كل التيستات اللي من غير صورة.
        DB::transaction(function () use ($data, $request, $needsApproval, $imagePath) {
            // العميل محتاجينه عشان نحسب تسعيرته لو الوضع channel
            $client = Client::findOrFail($data['client_id']);

            // ⚠️ سكوب التشانل مانجر — مايعملش أمر لعميل مش بتاعه حتى
            // لو عرف الـid (القايمة في الشاشة مفلترة، وده حارس الراوت)
            //
            // ⚠️ **والمندوب كمان.** `exists:users,id` كانت بتخلّي أي
            // أمر ينزل على أي يوزر في الشركة — محاسب، مندوب مدير
            // تاني، أو حساب موقوف. `Scope::assertRep` بتفحص الرول
            // والنشاط والفرع والمدير، واتساق العميل مع المندوب.
            //
            // ⚠️ الأمر ممكن يتعمل **من غير** مندوب (`assigned_to`
            // nullable) — ساعتها بنفحص العميل بس، والمندوب بيتفحص
            // وقت التسكين في `assignPurchaseOrder`.
            Scope::assertClient($request->user(), $client);

            if (! empty($data['assigned_to'])) {
                Scope::assertRep($request->user(), User::find($data['assigned_to']), $client);
            }

            $po = PurchaseOrder::create([
                'number' => PurchaseOrder::nextNumber(),
                'client_id' => $client->id,
                'source' => $data['source'] ?? null,
                'address' => $data['address'] ?? null,
                'assigned_to' => $data['assigned_to'] ?? null,
                // ⚠️ channel معناها إن السطور اتسعّرت بتسعيرة العميل (بخصمه)،
                // فالـ PO نفسه بيتسجل بالقائمة اللي العميل عليها عشان
                // مايعيدش الحساب بسعر قائمة عند التسليم.
                'price_mode' => $data['price_mode'] === 'channel'
                    ? $client->priceList()
                    : $data['price_mode'],
                'due_date' => $data['due_date'] ?? null,
                // ═══ فلو الكي أكاونت: مستني الحسابات ═══
                'approval_status' => $needsApproval ? 'pending' : null,
                'due_at' => $data['due_at'] ?? null,
                // ⚠️ بيتخزن هنا وبيتنسخ لأمر التجهيز وقت موافقة
                // الحسابات — أمر التجهيز مابيتعملش قبلها
                'pickup_at' => $data['pickup_at'] ?? null,
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'image_path' => $imagePath,
                'created_by' => $request->user()->id,
                'status' => 'pending',
                'total' => 0,
            ]);

            $this->fillPoItems($po, $client, $data['qty'], $data['price_mode']);

            // ⚠️ **في فلو الموافقة المندوب مايتبلغش هنا** — بيتبلغ لما
            // المخزن يجهّز بعد موافقة الحسابات. إشعار بدري = مندوب
            // بيستنى بضاعة الحسابات ممكن ترفضها أصلاً.
            if ($po->assigned_to && ! $needsApproval) {
                AppNotification::send(
                    User::find($po->assigned_to),
                    fn () => __('field.notif_po_new_title', ['number' => $po->number]),
                    fn () => __('field.notif_po_new_body', [
                        'client' => $po->client->displayName(),
                        // ⚠️ المبلغ اللي السواق هيحصّله، مش الصافي
                        'amount' => number_format($po->fresh()->payable()),
                    ]),
                    // ⚠️ الوجهة = الأمر نفسه. من غيرها المندوب بيدوس
                    // على الإشعار ويقع على الرئيسية ويدوّر بإيده.
                    link: AppNotification::poLink($po->id),
                );
            }
        });
        } catch (\App\Exceptions\Rejected $e) {
            // صنف مش متسعّر — الأمر كله بيترفض بدل ما يدخل بسطر بصفر
            return back()->withErrors(['qty' => $e->getMessage()])->withInput();
        }

        return back()->with('ok', $needsApproval ? __('flash.po_sent_accounting') : __('flash.po_created'));
    }

    /**
     * بناء بنود الأمر وتسعيرها وإجمالياته — مشترك بين الإنشاء والتعديل.
     *
     * ⚠️ channel = سعر العميل بخصمه (زي الفاتورة). old/new = سعر قائمة
     * بدون خصم — مقصود لسلاسل بتتحاسب بسعر صافي متفق عليه.
     *
     * ⚠️ **سعر صفر = رفض الأمر كله** (نفس دوكترين الفاتورة «الصنف مش
     * متسعّر») — أمر PO-2001 دخل فعلاً بصنف بسعر 0.00 وكان هيتقيّد
     * على الفرع ناقص (اتشاف 2026-08-04).
     */
    private function fillPoItems(PurchaseOrder $po, Client $client, array $qtyByProduct, string $priceMode): void
    {
        $rows = [];

        foreach ($qtyByProduct as $productId => $qty) {
            $qty = (int) $qty;
            if ($qty <= 0) {
                continue;
            }
            $product = Product::find($productId);
            if (! $product) {
                continue;
            }

            $price = $priceMode === 'channel'
                ? $client->priceFor($product)
                : $product->priceFor($priceMode);

            if ((float) $price <= 0) {
                throw new \App\Exceptions\Rejected(
                    __('stock.po_not_priced', ['name' => $product->displayName()])
                );
            }

            // ⚠️ **الخصم بيتسجّل على السطر مش بيتقرا وقت الطباعة.**
            // خصم العميل بيتغيّر (عقد بينتهي، نسبة بتتظبط)، والورقة
            // اللي الفرع بيمضي عليها لازم تفضل شاهدة على اللحظة اللي
            // الأمر اتسعّر فيها. `list_price` قبل الخصم، و`price`
            // فضل بعد الخصم زي ما هو عشان `total` مايتحركش.
            //
            // ⚠️ وضع `old`/`new` **مالوش خصم أصلاً** — ده سعر قايمة
            // صافي متفق عليه مع السلسلة. اشتقاق النسبة من الفرق
            // بين القايمتين كان هيطبع «خصم 8%» على أمر محدش اتفق
            // فيه على خصم.
            //
            // ⚠️ **النسبة بتتاخد من العميل مباشرة، مش بتتعكس من السعر.**
            // `Pricing::unitPrice` بتقرّب لقرشين قبل ما نقسم، فعكسها
            // (`1 - price/list`) كان بيطلّع 9.98% لعقد 10% على صنف
            // سعره 13.33 — ومستند بيتمضى عليه بيقول نسبة مختلفة عن
            // العقد وعن فاتورة نفس العميل (اللي بتخزّن الكسر الصح).
            $discountPct = $priceMode === 'channel' ? $client->effectiveDiscount() : 0.0;

            $listPrice = $priceMode === 'channel'
                ? \App\Services\Pricing::listPriceFor($client, $product)
                : (float) $price;

            // القايمة الناقصة بترجّع 0 — ساعتها السعر المطبوع هو المتاح
            if ($listPrice <= 0) {
                $listPrice = (float) $price;
            }

            $lineTotal = round($qty * $price, 2);

            // الضريبة سطر بسطر من `Tax` — نفس قاعدة الفاتورة بالظبط
            $taxRate = \App\Services\Tax::rate($client, $product);
            $lineTax = \App\Services\Tax::on($lineTotal, $client, $product);

            PurchaseOrderItem::create([
                'purchase_order_id' => $po->id,
                'product_id' => $product->id,
                'qty' => $qty,
                'price' => $price,
                'list_price' => $listPrice,
                'discount_pct' => $discountPct,
                'total' => $lineTotal,
                'tax_rate' => $taxRate,
                'tax' => $lineTax,
            ]);

            $rows[] = ['total' => $lineTotal, 'tax' => $lineTax];
        }

        // `total` صافي المبيعات، و`grand_total` اللي بيتقيّد عند التسليم
        $sums = \App\Services\Tax::totals($rows);

        $po->update([
            'total' => $sums['net'],
            'tax_total' => $sums['tax'],
            'grand_total' => $sums['grand'],
        ]);
    }

    // ═══════════ رفع POs من شيتات السلاسل — 2026-08-05 ═══════════

    /**
     * شاشة الرفع: قناة + مخزن + مندوب + معاد + ملفات PO متعددة.
     * كل ملف = أمر لفرع — صيغة شيتات رابت وأمثالها (Store ID/Name
     * + Order Nr + جدول أصناف بالباركود والكمية بالقطع).
     */
    public function poImport()
    {
        // السلاسل ومين منها في أنهي قناة — عشان سيلكت «العميل الأساسي»
        // يتفلتر بالقناة المختارة في الشاشة
        $groupChannels = Client::visibleTo(Client::query())
            ->whereNotNull('group_id')->whereNotNull('channel_id')
            ->distinct()->get(['group_id', 'channel_id']);

        return view('ops.po_import', [
            'channels' => \App\Models\Channel::orderBy('id')->get(),
            'groups' => \App\Models\ClientGroup::whereIn('id', $groupChannels->pluck('group_id')->unique())
                ->orderBy('name')->get(['id', 'name', 'name_en']),
            'groupChannels' => $groupChannels,
            'warehouses' => Warehouse::where('active', true)->orderBy('name')->get(['id', 'name', 'name_en']),
            'reps' => User::fieldVisibleTo(User::whereIn('role', ['sales_agent', 'driver'])
                ->where('active', true))->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** معاينة: بارس كل ملف + أوتوماتش الفرع — مفيش كتابة هنا */
    public function poImportPreview(Request $request)
    {
        $data = $request->validate([
            'channel_id' => ['required', 'exists:channels,id'],
            // السلسلة (العميل الأساسي) — اختيارية: بتحصر الديتكشن
            // والقوايم في فروعها بدل القناة كلها
            'group_id' => ['nullable', 'exists:client_groups,id'],
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'assigned_to' => ['required', 'exists:users,id'],
            'due_at' => ['required', 'date'],
            'files' => ['required', 'array', 'min:1', 'max:30'],
            'files.*' => ['file', 'mimes:xlsx,xls', 'max:10240'],
        ]);

        // فروع القناة (أو السلسلة المختارة منها) — وبسكوب التشانل مانجر
        $clients = Client::visibleTo(Client::where('channel_id', $data['channel_id'])
            ->when($data['group_id'] ?? null, fn ($q, $g) => $q->where('group_id', $g))
            ->where('status', 'active'))->orderBy('name')
            ->get(['id', 'name', 'name_en', 'code']);

        if ($clients->isEmpty()) {
            return back()->withErrors(['group_id' => __('ops.po_no_branches')])->withInput();
        }

        $entries = [];

        foreach ($request->file('files') as $file) {
            $parsed = $this->parsePoSheet($file->getRealPath());

            // أوتوماتش الفرع: بالاسم زي ما هو، وإلا بكود بيخلص بـStore ID
            $store = mb_strtolower(trim((string) ($parsed['store_name'] ?? '')));
            $sid = preg_replace('/\D+/', '', (string) ($parsed['store_id'] ?? ''));
            $guess = $clients->first(fn ($c) => mb_strtolower(trim($c->name)) === $store
                    || ($c->name_en && mb_strtolower(trim($c->name_en)) === $store))
                ?? ($sid !== '' ? $clients->first(fn ($c) => preg_match('/-0*'.$sid.'$/', $c->code)) : null);

            // ⚠️ الشيت بيتحفظ هنا (مش وقت الإنشاء) — الإنشاء AJAX من غير
            // الملفات. المسار بيمشي مع الصف وبيتسجل على الأمر كمرجع.
            // نفس نمط العقود: storage/app مش public — الشيتات فيها أسعار.
            $origName = $file->getClientOriginalName();
            $safe = uniqid().'_'.preg_replace('/[^\w.\-\x{0600}-\x{06FF}]+/u', '_', $origName);
            $dir = 'po-sheets/'.now()->format('Y-m');
            $file->move(storage_path('app/'.$dir), $safe);

            // ⚠️ منع التكرار (2026-08-06): نفس رقم PO السلسلة اترفع قبل
            // كده لفرع من فروع الاختيار؟ الملف بيتعلم «مكرر» وبيتعمله
            // skip أوتوماتيك — والمرفوض/الملغي مش بيمنع إعادة الرفع.
            $dup = null;

            if ($parsed['po_no']) {
                // ⚠️ `!= 'rejected'` لوحدها بتستبعد الـNULL (الفلو القديم) في SQL
                $dup = PurchaseOrder::where('source', $parsed['po_no'])
                    ->whereIn('client_id', $clients->pluck('id'))
                    ->where(fn ($w) => $w->whereNull('approval_status')->orWhere('approval_status', '!=', 'rejected'))
                    ->where('status', '!=', 'cancelled')
                    ->first();
            }

            $entries[] = [
                'file' => $origName,
                'sheet_path' => $dir.'/'.$safe,
                'po_no' => $parsed['po_no'],
                'store_name' => $parsed['store_name'],
                'store_id' => $parsed['store_id'],
                'client_id' => $guess?->id,
                'items' => $parsed['items'],
                'qty_total' => array_sum(array_column($parsed['items'], 'qty')),
                'unknown' => $parsed['unknown'],
                'dup' => $dup?->number,
            ];
        }

        return view('ops.po_import_preview', [
            'entries' => $entries,
            'clients' => $clients,
            'batch' => [
                'channel_id' => (int) $data['channel_id'],
                'warehouse_id' => (int) $data['warehouse_id'],
                'assigned_to' => (int) $data['assigned_to'],
                'due_at' => $data['due_at'],
            ],
        ]);
    }

    /**
     * التنفيذ بعد التأكيد: أمر لكل ملف — **نفس فلو الإنشاء اليدوي
     * بالظبط**: pending للحسابات، تسعير قايمة العميل، رفض الصنف
     * الغير متسعّر. أمر واقع مايوقّعش الباقي — أخطاؤه بتتجمع.
     */
    public function poImportStore(Request $request)
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'assigned_to' => ['required', 'exists:users,id'],
            'due_at' => ['required', 'date'],
            'orders' => ['required', 'array', 'min:1'],
            'orders.*.client_id' => ['nullable', 'exists:clients,id'],
            'orders.*.source' => ['nullable', 'string', 'max:40'],
            'orders.*.items' => ['required', 'string'],
            'orders.*.skip' => ['nullable', 'boolean'],
        ]);

        $created = 0;
        $errors = [];

        foreach ($data['orders'] as $order) {
            if (! empty($order['skip']) || empty($order['client_id'])) {
                continue;
            }

            // نفس حارس التكرار بتاع المسار المتتابع (2026-08-06)
            if ($dupErr = $this->duplicatePoError((int) $order['client_id'], $order['source'] ?? null)) {
                $errors[] = ($order['source'] ?: '—').': '.$dupErr;

                continue;
            }

            $qty = json_decode($order['items'], true);

            if (! is_array($qty) || $qty === []) {
                continue;
            }

            // أرقام صحيحة بس — أي حاجة تانية في الـJSON بتتداس
            $qty = collect($qty)->mapWithKeys(fn ($q, $pid) => [(int) $pid => (int) $q])
                ->filter(fn ($q, $pid) => $pid > 0 && $q > 0)->all();

            try {
                DB::transaction(function () use ($order, $qty, $data, $request) {
                    $client = Client::findOrFail($order['client_id']);

                    abort_unless($client->visibleBy($request->user()), 403);

                    $po = PurchaseOrder::create([
                        'number' => PurchaseOrder::nextNumber(),
                        'client_id' => $client->id,
                        // رقم أمر السلسلة (Order Nr) — بيتطبع على المستند
                        'source' => $order['source'] ?? null,
                        'assigned_to' => $data['assigned_to'],
                        'price_mode' => $client->priceList(),
                        'approval_status' => 'pending',
                        'due_at' => $data['due_at'],
                        'warehouse_id' => $data['warehouse_id'],
                        'created_by' => $request->user()->id,
                        'status' => 'pending',
                        'total' => 0,
                    ]);

                    $this->fillPoItems($po, $client, $qty, 'channel');
                });

                $created++;
            } catch (\App\Exceptions\Rejected $e) {
                $errors[] = ($order['source'] ?: '—').': '.$e->getMessage();
            }
        }

        $resp = redirect()->route('ops.po.approvals')
            ->with('ok', __('flash.pos_imported', ['count' => $created]));

        return $errors === [] ? $resp : $resp->withErrors($errors);
    }

    /**
     * إنشاء أمر واحد من المعاينة — AJAX (2026-08-06): الشاشة بتنشئ
     * الأوامر واحد ورا الثاني ببروجريس بار، فكل نداء بيرجع JSON
     * برقم الأمر أو رسالة الرفض. نفس منطق `poImportStore` بالظبط.
     */
    public function poImportStoreOne(Request $request)
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'assigned_to' => ['required', 'exists:users,id'],
            // معاد التوريد بقى لكل أمر لوحده (2026-08-06) — الصف بيبعت بتاعه
            'due_at' => ['required', 'date'],
            'client_id' => ['required', 'exists:clients,id'],
            'source' => ['nullable', 'string', 'max:40'],
            'items' => ['required', 'string'],
            'sheet_path' => ['nullable', 'string', 'max:255'],
            'sheet_name' => ['nullable', 'string', 'max:190'],
        ]);

        // ⚠️ المسار جاي من hidden input — نتأكد إنه جوه po-sheets فعلاً
        // وموجود، وإلا بيتداس. حارس ضد التسلل بالمسار.
        $sheet = (string) ($data['sheet_path'] ?? '');

        if ($sheet !== '') {
            $real = realpath(storage_path('app/'.$sheet));
            $root = realpath(storage_path('app/po-sheets'));

            if ($root === false || $real === false || ! str_starts_with($real, $root) || ! is_file($real)) {
                $sheet = '';
            }
        }

        // أرقام صحيحة بس — أي حاجة تانية في الـJSON بتتداس
        $qty = collect(json_decode($data['items'], true) ?: [])
            ->mapWithKeys(fn ($q, $pid) => [(int) $pid => (int) $q])
            ->filter(fn ($q, $pid) => $pid > 0 && $q > 0)->all();

        if ($qty === []) {
            return response()->json(['message' => __('ops.po_no_items')], 422);
        }

        // ⚠️ منع التكرار (2026-08-06): نفس رقم PO السلسلة لنفس الفرع
        // مايتعملوش مرتين — الشيت اللي بيترفع تاني بيترفض برقم الأمر
        // الموجود. المرفوض/الملغي مش بيمنع إعادة الرفع.
        if ($dupErr = $this->duplicatePoError($data['client_id'], $data['source'] ?? null)) {
            return response()->json(['message' => $dupErr], 422);
        }

        try {
            $po = DB::transaction(function () use ($data, $qty, $sheet, $request) {
                $client = Client::findOrFail($data['client_id']);

                // ⚠️ سكوب التشانل مانجر — حارس الراوت زي الرفع الجماعي
                abort_unless($client->visibleBy($request->user()), 403);

                $po = PurchaseOrder::create([
                    'number' => PurchaseOrder::nextNumber(),
                    'client_id' => $client->id,
                    'source' => $data['source'] ?? null,
                    'sheet_path' => $sheet !== '' ? $sheet : null,
                    'sheet_name' => $sheet !== '' ? ($data['sheet_name'] ?? null) : null,
                    'assigned_to' => $data['assigned_to'],
                    'price_mode' => $client->priceList(),
                    'approval_status' => 'pending',
                    'due_at' => $data['due_at'],
                    'warehouse_id' => $data['warehouse_id'],
                    'created_by' => $request->user()->id,
                    'status' => 'pending',
                    'total' => 0,
                ]);

                $this->fillPoItems($po, $client, $qty, 'channel');

                return $po;
            });
        } catch (\App\Exceptions\Rejected $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'id' => $po->id, 'number' => $po->number]);
    }

    /**
     * فيه أمر شغّال بنفس رقم PO السلسلة لنفس الفرع؟ بترجع رسالة
     * الرفض برقم الأمر الموجود، أو null لو مفيش تكرار.
     *
     * ⚠️ المرفوض والملغي مش بيمنعوا — رفض الحسابات لازم يسمح
     * بإعادة رفع الشيت بعد التصحيح.
     */
    private function duplicatePoError(int $clientId, ?string $source): ?string
    {
        $source = trim((string) $source);

        if ($source === '') {
            return null;
        }

        $dup = PurchaseOrder::where('client_id', $clientId)
            ->where('source', $source)
            // ⚠️ `!= 'rejected'` لوحدها بتستبعد الـNULL (الفلو القديم) في SQL
            ->where(fn ($w) => $w->whereNull('approval_status')->orWhere('approval_status', '!=', 'rejected'))
            ->where('status', '!=', 'cancelled')
            ->first();

        return $dup ? __('ops.po_dup_reject', ['number' => $dup->number]) : null;
    }

    /**
     * بارس شيت PO واحد — صيغة رابت: أزواج (ليبل، قيمة) في الهيدر،
     * وبعدين جدول أصناف عموده الفاصل «Barcode»، ولحد صف «Total».
     *
     * @return array{po_no: ?string, store_id: ?string, store_name: ?string,
     *               items: list<array{product_id: int, name: string, qty: int}>,
     *               unknown: list<string>}
     */
    private function parsePoSheet(string $path): array
    {
        $rows = \App\Services\Sheet::rows($path);

        $meta = ['po_no' => null, 'store_id' => null, 'store_name' => null];
        $items = [];
        $unknown = [];
        $cols = null;

        foreach ($rows as $row) {
            $cells = array_map(fn ($c) => trim((string) $c), $row);

            // الهيدر: الليبل وجنبه القيمة — الليبلات بأسماء رابت الحرفية
            foreach ($cells as $i => $cell) {
                $next = $cells[$i + 1] ?? null;
                if ($next === null || $next === '') {
                    continue;
                }
                match (mb_strtolower($cell)) {
                    'order nr', 'po nr', 'po #' => $meta['po_no'] = $meta['po_no'] ?? $next,
                    'store id' => $meta['store_id'] = $meta['store_id'] ?? $next,
                    'store name' => $meta['store_name'] = $meta['store_name'] ?? $next,
                    default => null,
                };
            }

            // صف عناوين الجدول — بيحدد أماكن الأعمدة
            $lower = array_map('mb_strtolower', $cells);
            if (in_array('barcode', $lower, true)) {
                $cols = [
                    'barcode' => array_search('barcode', $lower, true),
                    'sku' => array_search('sku', $lower, true),
                    'qty' => array_search('total pc', $lower, true),
                ];

                continue;
            }

            if ($cols === null) {
                continue;
            }

            // نهاية الجدول
            if (in_array('total', $lower, true) && trim((string) ($cells[$cols['barcode']] ?? '')) === '') {
                break;
            }

            $barcode = preg_replace('/\D+/', '', (string) ($cells[$cols['barcode']] ?? ''));
            $qty = (int) \App\Services\Sheet::number($cells[$cols['qty']] ?? null);

            if ($barcode === '' || $qty <= 0) {
                continue;
            }

            // الباركود الأول (المصدر الأدق) وبعدين SKU ككود صنف
            $product = Product::findByBarcode($barcode)
                ?? ($cols['sku'] !== false ? Product::where('code', $cells[$cols['sku']] ?? '')->first() : null);

            if ($product === null) {
                $unknown[] = $barcode;

                continue;
            }

            // نفس الصنف في سطرين — الكميات بتتجمع
            if (isset($items[$product->id])) {
                $items[$product->id]['qty'] += $qty;
            } else {
                $items[$product->id] = [
                    'product_id' => $product->id,
                    'name' => $product->displayName(),
                    'qty' => $qty,
                ];
            }
        }

        return $meta + ['items' => array_values($items), 'unknown' => $unknown];
    }

    /** مستند أمر التوريد للطباعة — نسخ الحسابات المختومة */
    public function printPo(PurchaseOrder $purchaseOrder)
    {
        $purchaseOrder->load(['client.group', 'client.zone', 'courier', 'items.product', 'warehouse', 'creator']);

        return view('ops.po_print', ['po' => $purchaseOrder]);
    }

    /**
     * تنزيل شيت الأمر الأصلي (2026-08-06) — المرجع اللي السلسلة بعتته.
     * نفس حراسة ملفات العقود: realpath جوه po-sheets وبس.
     */
    public function downloadPoSheet(PurchaseOrder $purchaseOrder)
    {
        $path = (string) $purchaseOrder->sheet_path;
        $real = $path !== '' ? realpath(storage_path('app/'.$path)) : false;
        $root = realpath(storage_path('app/po-sheets'));

        if ($real === false || $root === false || ! str_starts_with($real, $root) || ! is_file($real)) {
            abort(404);
        }

        $name = $purchaseOrder->number.' - '.($purchaseOrder->sheet_name ?: basename($real));

        return response()->download($real, $name);
    }

    /**
     * طباعة مجمعة (2026-08-06) — كل الأوامر المطلوبة في مستند واحد،
     * أمر في صفحة A4 لوحده. الحسابات بتطبع دفعة السلسلة كلها بضغطة.
     */
    public function printPoBatch(Request $request)
    {
        $ids = collect(explode(',', (string) $request->query('ids')))
            ->map(fn ($v) => (int) $v)->filter()->unique()->take(100);

        $pos = PurchaseOrder::with(['client.group', 'client.zone', 'courier', 'items.product', 'warehouse', 'creator'])
            ->whereIn('id', $ids)->orderBy('id')->get();

        abort_if($pos->isEmpty(), 404);

        return view('ops.po_print_batch', ['pos' => $pos]);
    }

    /**
     * موافقة جماعية (2026-08-06) — كل أمر في ترانزاكشن لوحده:
     * أمر واقع (عجز رف مثلاً) مايوقّعش باقي الدفعة، وبيتبلغ عنه
     * بالاسم. مفيش تعديل كميات هنا — التعديل من صف الأمر نفسه.
     */
    public function decideAllPoApprovals(Request $request)
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer'],
        ]);

        $done = 0;
        $errors = [];

        $orders = PurchaseOrder::with(['items.product', 'warehouse', 'courier'])
            ->whereIn('id', $data['ids'])
            ->where('approval_status', 'pending')
            ->get();

        foreach ($orders as $po) {
            if ($po->warehouse === null || $po->courier === null) {
                $errors[] = $po->number.': '.__('ops.po_needs_rep_wh');

                continue;
            }

            try {
                DB::transaction(function () use ($po, $request) {
                    // ⚠️ **أمر التجهيز بيتعمل هنا** — نفس فلو الموافقة
                    // الفردية بالظبط: requested بينزل شاشة التجهيز،
                    // وتأكيد المخزن هو اللي بيخصم ويبلغ المندوب.
                    $result = \App\Models\PickOrder::raise(
                        $po->warehouse,
                        $po->courier,
                        $po->items->pluck('qty', 'product_id')->all(),
                        \App\Models\PickOrder::PURPOSE_CUSTOMER_PO,
                        $request->user(),
                        [
                            // ⚠️ **الأمر ده لأمر توريد مش عهدة** — الغرض
                            // كان متسجّل `van_load` غلط، فالمندوب وأمين
                            // المخزن مكانش عندهم أي طريقة يفرّقوا.
                            'purchase_order_id' => $po->id,
                            // موعد وصول المندوب المخزن — اتحدد وقت
                            // إنشاء الأمر واستنى لحد الموافقة
                            'pickup_at' => $po->pickup_at,
                        ],
                    );

                    if ($result['error']) {
                        throw new \App\Exceptions\Rejected($result['error']);
                    }

                    $po->update([
                        'approval_status' => 'approved',
                        'approved_by' => $request->user()->id,
                        'approved_at' => now(),
                        'pick_order_id' => $result['order']->id,
                    ]);
                });

                $done++;
            } catch (\App\Exceptions\Rejected $e) {
                $errors[] = $po->number.': '.$e->getMessage();
            }
        }

        $resp = back()->with('ok', __('flash.pos_bulk_approved', ['count' => $done]));

        return $errors === [] ? $resp : $resp->withErrors($errors);
    }

    // ═══════════ أوامر توريد الكي أكاونت — 2026-08-04 ═══════════

    /** فتح أمر pending للتعديل — نفس شاشة الإنشاء متملية بالبيانات */
    public function editPo(PurchaseOrder $purchaseOrder)
    {
        // ⚠️ سكوب التشانل مانجر — مايعدّلش أمر عميل مش بتاعه
        abort_unless($purchaseOrder->client?->visibleBy(auth()->user()) ?? true, 403);

        if ($purchaseOrder->approval_status !== 'pending') {
            return redirect()->route('ops.po.approvals')
                ->withErrors(['decision' => __('ops.po_already_decided')]);
        }

        $data = $this->poHandout()->getData();
        $data['editing'] = $purchaseOrder->load(['items', 'client']);

        return view('ops.po_handout', $data);
    }

    /**
     * حفظ تعديل أمر pending — للحسابات ولصاحب الأمر (أدمن/مدير قناة).
     *
     * ⚠️ **البنود بتتبني من الأول بأسعار النهارده** — التعديل مش
     * بيرقّع، بيعيد التسعير كأنه أمر جديد بنفس الرقم. والقرار لسه
     * عند الحسابات: التعديل مابيوافقش، بيرجّع الأمر للطابور.
     */
    public function updatePo(Request $request, PurchaseOrder $purchaseOrder)
    {
        if ($purchaseOrder->approval_status !== 'pending') {
            return back()->withErrors(['decision' => __('ops.po_already_decided')]);
        }

        $data = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'assigned_to' => ['required', 'exists:users,id'],
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'due_at' => ['required', 'date'],
            // ⚠️ **الفورم بيبعتهم في وضع التعديل كمان** (نفس الفيو)
            // وكانوا بيتبلعوا في صمت: المستخدم يستبدل صورة الأمر أو
            // يصلّح موعد الاستلام، يدوس حفظ، ومايحصلش حاجة ومفيش خطأ.
            'pickup_at' => ['nullable', 'date'],
            'image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'],
            'source' => ['nullable', 'string', 'max:40'],
            'qty' => ['required', 'array'],
            'qty.*' => ['nullable', 'integer', 'min:0'],
            'unit' => ['nullable', 'array'],
            'unit.*' => ['nullable', 'in:piece,box,case'],
        ]);

        // وحدة الإدخال → قطع، في السيرفر — نفس قاعدة الإنشاء
        foreach ($request->input('unit', []) as $productId => $unit) {
            if (! $unit || $unit === 'piece' || empty($data['qty'][$productId])) {
                continue;
            }

            $factor = Product::find($productId)?->unitFactor($unit);

            if ($factor === null) {
                return back()->withErrors([
                    'qty' => __('stock.unit_not_for_product', ['name' => Product::find($productId)?->displayName() ?? $productId]),
                ])->withInput();
            }

            $data['qty'][$productId] = (int) $data['qty'][$productId] * $factor;
        }

        // ⚠️ الرفع قبل الترانزاكشن — كتابة الملف على الديسك مش جزء
        // من ترانزاكشن الداتابيز. نفس قاعدة `storePurchaseOrder`.
        $imagePath = $request->hasFile('image')
            ? $request->file('image')->store('po-images', 'public')
            : null;

        try {
            DB::transaction(function () use ($purchaseOrder, $data, $imagePath) {
                $client = Client::findOrFail($data['client_id']);

                // ⚠️ سكوب التشانل مانجر — نفس حارس storePurchaseOrder
                //
                // ⚠️ **والمستلم كمان.** التعديل بيسمح بتبديل
                // `client_id` و`assigned_to` مع بعض، والمحاسب معاه
                // الراوت ده — من غير الحارس ينفع أمر يتنقل لعميل
                // ومندوب بره نطاق المعدِّل في نداء واحد.
                Scope::assertRep(auth()->user(), User::find($data['assigned_to']), $client);

                $purchaseOrder->update([
                    'client_id' => $client->id,
                    'assigned_to' => $data['assigned_to'],
                    'warehouse_id' => $data['warehouse_id'],
                    'due_at' => $data['due_at'],
                    'pickup_at' => $data['pickup_at'] ?? $purchaseOrder->pickup_at,
                    // ⚠️ **الصورة القديمة بتفضل لو مارفعش جديدة** —
                    // `null` هنا كان هيمسحها مع أي تعديل تاني.
                    'image_path' => $imagePath ?? $purchaseOrder->image_path,
                    // ⚠️ **مايتمسحش لو الفورم بعته فاضي** — الأمر
                    // المتولّد من طلب ريفيل بيتعرف بـ`source`،
                    // ومسحه بيقطع `fromReplenishment()` في صمت.
                    'source' => $data['source'] ?? $purchaseOrder->source,
                    'price_mode' => $client->priceList(),
                    // تراك التعديل: مين وإمتى (2026-08-05)
                    'was_edited' => true,
                    'edited_by' => auth()->id(),
                    'edited_at' => now(),
                ]);

                // بنود جديدة بالكامل — التعديل إعادة بناء مش ترقيع
                $purchaseOrder->items()->delete();
                $this->fillPoItems($purchaseOrder, $client, $data['qty'], 'channel');
            });
        } catch (\App\Exceptions\Rejected $e) {
            return back()->withErrors(['qty' => $e->getMessage()])->withInput();
        }

        return redirect()->route('ops.po.approvals')->with('ok', __('flash.po_updated'));
    }

    /** شاشة «تسليم PO للمندوب»: سلسلة ← فرع ← مندوب ← معاد ← أصناف بالوحدات */
    /**
     * المتاح للتجهيز لكل (مخزن، صنف) — **نفس مصدر الحجز بالظبط**
     * (`Warehouse::availableFor`: المرصوف السليم على الأرفف).
     *
     * ⚠️ الشاشة كانت بتعرض إجمالي `stocks` (كل المخازن + غير المرصوف)
     * فالمدير يشوف 120 والموافقة ترفض بـ«المتاح 0» — رقمين من مصدرين
     * (اتشاف 2026-08-05). دلوقتي المعروض هو اللي هيتحجز فعلاً.
     *
     * @return array<int, array<int, int>>  [warehouse_id][product_id] => qty
     */
    private function shelfAvailability(): array
    {
        $rows = \App\Models\BatchLocation::query()
            ->join('locations', 'locations.id', '=', 'batch_locations.location_id')
            ->join('batches', 'batches.id', '=', 'batch_locations.batch_id')
            ->where('batch_locations.qty', '>', 0)
            ->where('batches.blocked', false)
            ->where('batches.qty_remaining', '>', 0)
            ->whereDate('batches.expires_on', '>=', now()->toDateString())
            ->selectRaw('locations.warehouse_id as wid, batches.product_id as pid, SUM(batch_locations.qty) as q')
            ->groupBy('locations.warehouse_id', 'batches.product_id')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->wid][(int) $r->pid] = (int) $r->q;
        }

        return $out;
    }

    public function poHandout()
    {
        // السلاسل ومين منها في أنهي قناة — نفس كاسكيد شاشة رفع الشيتات:
        // القناة ← السلسلة ← الفرع (طلب المالك 2026-08-06)
        $groupChannels = Client::visibleTo(Client::query())
            ->whereNotNull('group_id')->whereNotNull('channel_id')
            ->distinct()->get(['group_id', 'channel_id']);

        return view('ops.po_handout', [
            'shelfAvail' => $this->shelfAvailability(),
            'channels' => \App\Models\Channel::orderBy('id')->get(),
            'groups' => \App\Models\ClientGroup::orderBy('name')->get(),
            'groupChannels' => $groupChannels,
            // الفروع بتتفلتر بالقناة والسلسلة في الجافاسكريبت — فبنبعت الكل
            // ⚠️ العميل حالته عمود `status` نصي ('active') مش بوليان `active`
            // العلاقات دي عشان Pricing::listRowFor تشتغل من الميموري —
            // البحث بيفلتر الأصناف بسعر قايمة الفرع المختار
            // ⚠️ وسكوب التشانل مانجر: مايعملش أمر غير لعملائه (2026-08-05)
            'clients' => Client::visibleTo(Client::with(['group.contract.priceListRow', 'contract.priceListRow', 'priceListRow'])
                ->where('status', 'active'))->orderBy('name')
                ->get(['id', 'name', 'name_en', 'group_id', 'channel_id', 'balance', 'price_list', 'price_list_id']),
            'reps' => User::fieldVisibleTo(User::whereIn('role', ['sales_agent', 'driver']))
                ->where('active', true)->orderBy('name')->get(['id', 'name']),
            'warehouses' => \App\Models\Warehouse::where('active', true)->orderBy('name')->get(['id', 'name', 'name_en']),
            'products' => Product::where('active', true)->orderBy('code')->get(),
        ]);
    }

    /**
     * طابور الحسابات — جدول كولابس (2026-08-06): كل أمر صف، الضغط
     * عليه بيفتح تفاصيله. الفلاتر والترتيب سيرفر سايد، و«آخر
     * القرارات» اتشالت — المتقرر فيه مكانه صفحة أوامر التوريد.
     */
    public function poApprovals(Request $request)
    {
        $q = PurchaseOrder::with(['client.group', 'client.channel', 'courier', 'items.product', 'creator', 'warehouse'])
            ->where('approval_status', 'pending')
            ->when($request->string('q')->trim()->value(), function ($q2, $s) {
                $q2->where(fn ($w) => $w->where('number', 'like', "%$s%")
                    ->orWhere('source', 'like', "%$s%")
                    ->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%$s%")
                        ->orWhere('name_en', 'like', "%$s%")
                        ->orWhere('code', 'like', "%$s%")));
            })
            ->when($request->integer('group'),
                fn ($q2, $g) => $q2->whereHas('client', fn ($c) => $c->where('group_id', $g)))
            ->when($request->string('from')->value(), fn ($q2, $d) => $q2->whereDate('due_at', '>=', $d))
            ->when($request->string('to')->value(), fn ($q2, $d) => $q2->whereDate('due_at', '<=', $d));

        // الترتيب: الافتراضي أقرب معاد توريد — القرار المستعجل الأول
        match ($request->string('sort')->value()) {
            'value' => $q->orderByDesc('grand_total'),
            'newest' => $q->latest(),
            default => $q->orderByRaw('due_at IS NULL')->orderBy('due_at'),
        };

        return view('ops.po_approvals', [
            'pending' => $q->get(),
            // سلاسل الأوامر المستنية بس — سيلكت الفلتر
            'groups' => \App\Models\ClientGroup::whereHas('clients.purchaseOrders',
                fn ($p) => $p->where('approval_status', 'pending'))
                ->orderBy('name')->get(['id', 'name', 'name_en']),
            // المتاح على أرفف كل مخزن — الحسابات تشوف العجز **قبل**
            // ما تدوس موافقة بدل ما الرفض يفاجئها (2026-08-05)
            'shelfAvail' => $this->shelfAvailability(),
            'filters' => $request->only(['q', 'group', 'from', 'to', 'sort']),
        ]);
    }

    /**
     * قرار الحسابات: موافقة / تعديل كميات + موافقة / رفض.
     *
     * ⚠️ **الموافقة هي اللي بتعمل أمر التجهيز** — قبلها البضاعة
     * ماتتحجزش. والرفض/التعديل بيتبلغ بيه صاحب الأمر (مدير القناة).
     */
    public function decidePoApproval(Request $request, PurchaseOrder $purchaseOrder)
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            // ⚠️ الرفض من غير سبب ممنوع — مدير القناة لازم يعرف ليه
            'note' => ['required_if:decision,rejected', 'nullable', 'string', 'max:500'],
            // تعديل الحسابات بالقطع — الشاشة بتوري التجميعة جنب الخانة
            'qty_edit' => ['nullable', 'array'],
            'qty_edit.*' => ['nullable', 'integer', 'min:0', 'max:999999'],
        ], [
            'note.required_if' => __('ops.po_note_required_reject'),
        ]);

        // ⚠️ قرار واحد بس — ضغطتين متتاليتين مايعملوش أمري تجهيز
        if ($purchaseOrder->approval_status !== 'pending') {
            return back()->withErrors(['decision' => __('ops.po_already_decided')]);
        }

        // ⚠️ تعديل كميات من غير ملحوظة ممنوع برضه — التعديل بيتبلغ بيه
        // مدير القناة، والإشعار من غير سبب مايتفهمش (2026-08-06)
        if ($data['decision'] === 'approved' && blank($data['note'] ?? null)) {
            foreach ($data['qty_edit'] ?? [] as $itemId => $newQty) {
                if ($newQty === null || $newQty === '') {
                    continue;
                }

                $item = $purchaseOrder->items->firstWhere('id', (int) $itemId);

                if ($item && (int) $newQty !== (int) $item->qty) {
                    return back()->withErrors(['note' => __('ops.po_note_required_edit')])->withInput();
                }
            }
        }

        // ⚠️ المندوب أو المخزن اتشالوا بعد الإنشاء (nullOnDelete)؟
        // الموافقة بتعمل أمر تجهيز منهم — من غيرهم مفيش قرار.
        if ($data['decision'] === 'approved'
            && ($purchaseOrder->warehouse === null || $purchaseOrder->courier === null)) {
            return back()->withErrors(['decision' => __('ops.po_needs_rep_wh')]);
        }

        if ($data['decision'] === 'rejected') {
            $purchaseOrder->update([
                'approval_status' => 'rejected',
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
                'approval_note' => $data['note'] ?? null,
                'status' => 'cancelled',
            ]);

            // مدير القناة يعرف إن أمره اترفض وليه
            if ($purchaseOrder->created_by) {
                AppNotification::send(
                    User::find($purchaseOrder->created_by),
                    fn () => __('field.notif_po_rejected_title', ['number' => $purchaseOrder->number]),
                    fn () => ($data['note'] ?? null) ?: $purchaseOrder->client->displayName(),
                    false,
                );
            }

            return back()->with('ok', __('flash.po_rejected'));
        }

        // ═══ موافقة (مع تعديل اختياري) ═══
        try {
            DB::transaction(function () use ($purchaseOrder, $data, $request) {
                $changes = [];

                // ⚠️ التعديل بيعيد حساب السطر بنفس سعره وضريبته —
                // السعر ثابت من وقت الإنشاء، الكمية بس اللي بتتغير.
                foreach ($data['qty_edit'] ?? [] as $itemId => $newQty) {
                    if ($newQty === null || $newQty === '') {
                        continue;
                    }

                    $item = $purchaseOrder->items->firstWhere('id', (int) $itemId);

                    if (! $item || (int) $newQty === (int) $item->qty) {
                        continue;
                    }

                    $changes[] = $item->product->displayName().': '.$item->qty.' ← '.(int) $newQty;

                    if ((int) $newQty === 0) {
                        $item->delete();

                        continue;
                    }

                    $lineTotal = round((int) $newQty * (float) $item->price, 2);
                    $item->update([
                        'qty' => (int) $newQty,
                        'total' => $lineTotal,
                        'tax' => round($lineTotal * (float) ($item->tax_rate ?? 0), 2),
                    ]);
                }

                $purchaseOrder->load('items');

                if ($purchaseOrder->items->isEmpty()) {
                    throw new \App\Exceptions\Rejected(__('ops.po_no_items_left'));
                }

                if ($changes !== []) {
                    $rows = $purchaseOrder->items
                        ->map(fn ($i) => ['total' => (float) $i->total, 'tax' => (float) $i->tax])
                        ->all();
                    $sums = \App\Services\Tax::totals($rows);

                    $purchaseOrder->update([
                        'total' => $sums['net'],
                        'tax_total' => $sums['tax'],
                        'grand_total' => $sums['grand'],
                        'was_edited' => true,
                        // تراك: مين عدّل الكميات وإمتى (2026-08-05)
                        'edited_by' => $request->user()->id,
                        'edited_at' => now(),
                    ]);
                }

                // ⚠️ **أمر التجهيز بيتعمل هنا** — طلب (requested) بينزل
                // شاشة «تجهيز الطلبات»، وتأكيد التجهيز هناك هو اللي
                // بيخصم ويبعت إشعار للمندوب (نفس فلو العهدة بالظبط).
                $result = \App\Models\PickOrder::raise(
                    $purchaseOrder->warehouse,
                    $purchaseOrder->courier,
                    $purchaseOrder->items->pluck('qty', 'product_id')->all(),
                    \App\Models\PickOrder::PURPOSE_CUSTOMER_PO,
                    $request->user(),
                    [
                        'purchase_order_id' => $purchaseOrder->id,
                        'pickup_at' => $purchaseOrder->pickup_at,
                    ],
                );

                if ($result['error']) {
                    throw new \App\Exceptions\Rejected($result['error']);
                }

                $purchaseOrder->update([
                    'approval_status' => 'approved',
                    'approved_by' => $request->user()->id,
                    'approved_at' => now(),
                    'approval_note' => $data['note'] ?? null,
                    'pick_order_id' => $result['order']->id,
                    // ⚠️ **بداية عدّاد تجهيز الأمر** — من لحظة ما
                    // الحسابات توافق وأمر التجهيز ينزل المخزن.
                    // من غيرها `PurchaseOrder::prepMinutes()` بترجّع
                    // `null` دايماً والعمود يبقى ميت.
                    'prep_started_at' => $purchaseOrder->prep_started_at ?? now(),
                ]);

                // مدير القناة يعرف إن أمره اتعدل وإيه اللي اتغير
                if ($changes !== [] && $purchaseOrder->created_by) {
                    AppNotification::send(
                        User::find($purchaseOrder->created_by),
                        fn () => __('field.notif_po_edited_title', ['number' => $purchaseOrder->number]),
                        fn () => implode(' · ', $changes),
                        false,
                    );
                }
            });
        } catch (\App\Exceptions\Rejected $e) {
            return back()->withErrors(['decision' => $e->getMessage()]);
        }

        return back()->with('ok', __('flash.po_approved'));
    }

    public function assignPurchaseOrder(Request $request, PurchaseOrder $purchaseOrder)
    {
        $data = $request->validate(['assigned_to' => ['required', 'exists:users,id']]);

        // ⚠️ **الميثود دي كانت بلا أي حارس إطلاقاً** (تدقيق ٨/٨/٢٠٢٦):
        // أي مدير كان يعيد تسكين أي أمر في الشركة على أي يوزر. الحارس
        // بيفحص الأمر نفسه (عميله في نطاق الفاعل) والمستلم الجديد.
        Scope::assertRep(
            $request->user(),
            User::find($data['assigned_to']),
            $purchaseOrder->client,
        );

        $purchaseOrder->update($data);

        AppNotification::send(
            User::find($data['assigned_to']),
            fn () => __('field.notif_po_assigned_title', ['number' => $purchaseOrder->number]),
            fn () => $purchaseOrder->client->displayName(),
            link: AppNotification::poLink($purchaseOrder->id),
        );

        return back()->with('ok', __('flash.po_assigned'));
    }

    // ================= موافقات العملاء الجدد =================

    public function requests(Request $request)
    {
        // ⚠️ **سكوب الفريق** (تدقيق ٨/٨/٢٠٢٦): القايمة كانت على مستوى
        // الشركة، فمدير بيشوف — ويقرر في — طلبات مناديب مدير تاني.
        // الفلترة على المندوب صاحب الطلب، مش على القناة، عشان تتطابق
        // مع `decideRequest` تحت.
        // ⚠️ الفلتر **للمدير بس** مش لكل حد. `whereIn(created_by, ...)`
        // على الأدمن كان هيخفي أي طلب `created_by` بتاعه null — وده
        // بيخبّي طلبات بدل ما يحميها.
        $q = ClientRequest::with(['rep', 'zone', 'client'])
            ->when(
                $request->user()?->role === 'manager',
                fn ($w) => $w->whereIn('created_by',
                    User::fieldVisibleTo(User::query(), $request->user())->select('id')),
            );

        if ($status = $request->string('status')->value()) {
            $q->where('status', $status);
        }

        return view('ops.requests', [
            'requests' => $q->latest()->paginate(30)->withQueryString(),
            'zones' => Zone::orderBy('code')->get(),
            'filters' => $request->only('status'),
        ]);
    }

    public function decideRequest(Request $request, ClientRequest $clientRequest)
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approved,review,rejected'],
            'zone_id' => ['nullable', 'exists:zones,id'],
            'discount' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        // ⚠️ **التوأم في الأبلكيشن بيرجّع 403 والويب كان بيعدّي** —
        // مسار الويب كان مفيهوش حارس خالص، فمدير بيعتمد عميل مندوب
        // مدير تاني. الحارس على المندوب صاحب الطلب.
        Scope::assertRep($request->user(), $clientRequest->rep);

        DB::transaction(function () use ($data, $clientRequest, $request) {
            $clientRequest->status = $data['decision'];
            $clientRequest->decided_by = $request->user()->id;
            $clientRequest->decided_at = now();
            $clientRequest->decision_note = $data['note'] ?? null;

            if ($data['decision'] === 'approved') {
                // ⚠️ **العميل المعتمد كان بيتولد يتيم** (تدقيق ٨/٨):
                // بلا قناة ولا مندوب ولا مدير ولا فرع — فمابيظهرش في
                // `Client::visibleTo` لأي حد، والمندوب اللي فتحه
                // مابيلاقيهوش في الزون. الوراثة من المندوب صاحب الطلب.
                $rep = $clientRequest->rep;

                $client = Client::create([
                    'code' => Client::nextCode(),
                    'name' => $clientRequest->name,
                    'phone' => $clientRequest->phone,
                    'address' => $clientRequest->address,
                    'zone_id' => $data['zone_id'] ?? $clientRequest->zone_id ?? $rep?->zone_id,
                    'rep_id' => $rep?->id,
                    'channel_id' => $rep?->channel_id,
                    // ⚠️ المدير بييجي من تسكين المندوب مش من الفاعل —
                    // الأدمن بيعتمد لمناديب مديرين مختلفين.
                    'manager_id' => $rep?->manager_id,
                    'branch_id' => $rep?->branch_id,
                    'category' => 'grow',
                    'status' => 'active',
                    'discount' => ($data['discount'] ?? 0) / 100,
                    // ⚠️ **توحيد مع التوأم في الـAPI** — الويب ماكانش
                    // بيكتب العمود ده، فنفس الطلب كان بيطلّع عميل
                    // بإعداد خصم مختلف حسب اتعتمد من الويب ولا من
                    // الأبلكيشن.
                    'uses_channel_discount' => ($data['discount'] ?? 0) <= 0,
                    'is_new' => true,
                    'has_docs' => $clientRequest->has_docs,
                    'photo_path' => $clientRequest->photo_path,
                    'docs_path' => $clientRequest->docs_path,
                    'docs_type' => $clientRequest->docs_type,
                    'created_by' => $clientRequest->created_by,
                ]);
                $clientRequest->client_id = $client->id;

                AppNotification::send(
                    $clientRequest->rep,
                    fn () => __('field.notif_client_approved_title', ['name' => $clientRequest->name]),
                    fn () => __('field.notif_client_approved_body'),
                    link: AppNotification::requestLink($clientRequest->id),
                );
            } elseif ($data['decision'] === 'review') {
                AppNotification::send(
                    $clientRequest->rep,
                    fn () => __('field.notif_client_review_title', ['name' => $clientRequest->name]),
                    fn () => $data['note'] ?? __('field.notif_client_review_body'),
                    link: AppNotification::requestLink($clientRequest->id),
                );
            } else {
                AppNotification::send(
                    $clientRequest->rep,
                    fn () => __('field.notif_client_rejected_title', ['name' => $clientRequest->name]),
                    fn () => $data['note'] ?? __('field.notif_client_rejected_body'),
                    false,
                    link: AppNotification::requestLink($clientRequest->id),
                );
            }

            $clientRequest->save();
        });

        return back()->with('ok', __('flash.decision_recorded'));
    }

    // ================= التراكينج =================

    public function tracking(Request $request)
    {
        $userId = $request->integer('user');
        $date = $request->date('date') ?? today();

        $q = TrackEvent::with('user')->whereDate('happened_at', $date);
        if ($userId) {
            $q->where('user_id', $userId);
        }

        // ⚠️ سكوب الفريق: أحداث مناديب مدير تاني متابعة فردية مش
        // «رقم مجمّع» — نفس دوكترين fieldVisibleTo في كل الشاشات.
        $visibleIds = User::fieldVisibleTo(User::query())->pluck('id');
        $events = $q->whereIn('user_id', $visibleIds)
            ->orderByDesc('happened_at')->get()
            ->filter(fn ($e) => $e->user !== null)->values();

        // ═══ إعادة بناء ٩ أغسطس: كل المناديب مرة واحدة ═══
        // لون ثابت لكل مندوب في العرض ده — الماركرز والمسار وحد
        // التايم لاين كلهم بنفس اللون، عشان العين تفرز من غير قراءة.
        $palette = [
            '#12399B', '#B00020', '#0F766E', '#B45309', '#602D90',
            '#DB2777', '#2563EB', '#059669', '#7C2D12', '#4338CA',
        ];
        $reps = $events->pluck('user')->unique('id')->values();
        $colors = [];
        foreach ($reps as $i => $u) {
            $colors[$u->id] = $palette[$i % count($palette)];
        }

        return view('ops.tracking', [
            'events' => $events,
            'reps' => $reps,
            'colors' => $colors,
            'field' => User::fieldVisibleTo(User::whereIn('role', User::FIELD_ROLES))->get(),
            'userId' => $userId,
            'date' => $date->toDateString(),
        ]);
    }

    // ================= الفواتير =================

    public function invoices(Request $request)
    {
        // ⚠️ سكوب التشانل مانجر (2026-08-05): فواتير عملائه بس —
        // والإجمالي من نفس الكويري فمفيش نطاقين مختلطين.
        $u = auth()->user();
        $q = Invoice::with(['client', 'user'])
            ->when($u?->role === 'manager',
                fn ($q2) => $q2->whereIn('client_id', Client::visibleTo(Client::query(), $u)->select('id')));
        if ($userId = $request->integer('user')) {
            $q->where('user_id', $userId);
        }
        if ($from = $request->string('from')->value()) {
            $q->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->string('to')->value()) {
            $q->whereDate('created_at', '<=', $to);
        }

        return view('ops.invoices', [
            'invoices' => $q->latest()->paginate(40)->withQueryString(),
            'field' => User::fieldVisibleTo(User::whereIn('role', User::FIELD_ROLES))->get(),
            'filters' => $request->only(['user', 'from', 'to']),
            'sum' => (clone $q)->sum('total'),
        ]);
    }

    public function invoice(Invoice $invoice)
    {
        abort_unless(
            request()->user()->canSeeBranch($invoice->client->branch_id), 403,
        );
        // ⚠️ سكوب التشانل مانجر — فاتورة عميل مش بتاعه ماتتفتحش بالـid
        abort_unless($invoice->client->visibleBy(request()->user()), 403);

        $invoice->load(['items.product', 'client', 'user', 'visit']);

        // ⚠️ الفاتورة ممكن تجمع نسب مختلفة (صنف بـ 14% وصنف معفى).
        // لو النسب اتعددت مانكتبش نسبة واحدة جنب سطر الضريبة — كتابة
        // نسبة واحدة على فاتورة مختلطة رقم غلط على مستند رسمي.
        $rates = $invoice->items
            ->filter(fn ($i) => (float) $i->tax > 0)
            ->pluck('tax_rate')->map(fn ($r) => round((float) $r, 4))->unique();

        return view('ops.invoice', [
            'inv' => $invoice,
            'taxRateLabel' => $rates->count() === 1 ? \App\Services\Tax::label($rates->first()) : '',
            'companyTaxId' => \App\Models\Setting::read('company_tax_id'),
        ]);
    }

    /** تسجيل تحصيل نقدي من عميل */
    public function collect(Request $request, Client $client)
    {
        // ⚠️ سكوب التشانل مانجر — مايحصّلش من عميل مش بتاعه
        abort_unless($client->visibleBy($request->user()), 403);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'memo' => ['nullable', 'string', 'max:190'],
            'date' => ['nullable', 'date'],
            // ═══ طريقة التحصيل (قرار المالك ٨/٨/٢٠٢٦) ═══
            'method' => ['required', \Illuminate\Validation\Rule::in(Transaction::METHODS)],
            // ⚠️ **إجباري لغير النقدي** — `required_unless` مش
            // `nullable`: من غير ريفرنس المطابقة مع البنك أو الماكينة
            // مستحيلة، والفرق بيتكتشف آخر الشهر ومحدش يعرف مصدره.
            'reference' => ['nullable', 'required_unless:method,cash', 'string', 'max:100'],
            // بيانات الشيك — إجبارية للشيك بس
            'cheque_bank' => ['nullable', 'required_if:method,cheque', 'string', 'max:120'],
            'cheque_due' => ['nullable', 'required_if:method,cheque', 'date'],
        ]);

        // ⚠️ **جوّه ترانزاكشن** (تدقيق ٨/٨/٢٠٢٦). القيد و`recalculate()`
        // كانوا سطرين مكشوفين — ولو الطلب اتقطع بينهم، القيد بيتكتب
        // والأعمدة المجمّعة مابتتحدّثش. **ده السبب رقم ١ الموثّق
        // لـ«رصيد العميل ≠ كشف حسابه»**، وكان بيتصلح بإعادة حساب
        // يدوية من غير ما حد يعرف مصدره.
        DB::transaction(function () use ($client, $data) {
            Transaction::create([
                'client_id' => $client->id,
                'date' => $data['date'] ?? today(),
                'memo' => $data['memo'] ?? __('flash.memo_cash_collection'),
                'debit' => 0,
                'credit' => $data['amount'],
                'kind' => 'collection',
                // ⚠️ **الشيك قيده زي الكاش بالظبط** (قرار المالك) —
                // بيدخل حساب العميل فوراً، والفرق في البيانات
                // المرفقة مش في المحاسبة.
                'method' => $data['method'],
                'reference' => $data['reference'] ?? null,
                'cheque_bank' => $data['method'] === Transaction::METHOD_CHEQUE
                    ? ($data['cheque_bank'] ?? null) : null,
                'cheque_due' => $data['method'] === Transaction::METHOD_CHEQUE
                    ? ($data['cheque_due'] ?? null) : null,
            ]);

            $client->recalculate();
        });

        return back()->with('ok', __('flash.collection_recorded'));
    }
}
