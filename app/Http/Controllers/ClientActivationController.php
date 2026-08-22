<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * تفعيل العملاء — من 455 فرع مستورد لعملاء شغّالين
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **الشاشة دي هي البوابة الوحيدة بين الداتا المستوردة والشغل
 * الحقيقي.** `promax:clients` بينزّل كل حاجة موقوفة، وهنا بس بيبقى
 * العميل شغّال — يعني يبان للمندوب في خط سيره وينفع يتباعله.
 *
 * ⚠️ **التفعيل مش مجرد `active = 1`.** العميل اللي بيتفعّل من غير
 * منطقة مايبانش لأي مندوب، ومن غير قايمة سعر بيتباعله بصفر. فالشاشة
 * بتطلب الاتنين وقت التفعيل بدل ما تسيبه ينزل ناقص وحد يكتشف بعدين.
 */
class ClientActivationController extends Controller
{
    public function index(Request $request)
    {
        // ⚠️ سكوب الشاشة دي أوسع من `visibleTo` عن قصد (١٨/٨/٢٠٢٦):
        // عملاءه + **غير المسكّنين** المش مفعّلين. شوف activationScope.
        $q = $this->activationScope($request->user(),
            Client::query()->with(['group', 'zone', 'rep']));

        // ⚠️ **`status` مش `active`.** الجدول مافيهوش عمود `active` —
        // الحالة enum في `status`. الافتراضي «المستني» لأن ده شغل
        // الشاشة، بس بعد ما التفعيل بدأ لازم تعرف تراجع اللي اتفعّل
        // وتوقفه لو اتفعّل بالغلط.
        $status = $request->string('status')->value() ?: 'waiting';

        match ($status) {
            'active' => $q->where('status', 'active'),
            'all' => null,
            default => $q->where('status', '!=', 'active'),
        };

        if ($s = $request->string('q')->trim()->value()) {
            // البحث الموحّد (فرع+سلسلة، عربي+إنجليزي) + العنوان الخاص بالشاشة دي
            $q->where(fn ($w) => \App\Models\Client::search($w, $s)
                ->orWhere('address', 'like', "%$s%"));
        }

        if ($g = $request->integer('group')) {
            $q->where('group_id', $g);
        }

        if ($gov = $request->string('gov')->value()) {
            $q->where('governorate', $gov);
        }

        // ⚠️ **الناقص الأول.** الفرع اللي مالوش عنوان ولا تليفون هو
        // اللي محتاج شغل قبل ما يتفعّل، والترتيب الأبجدي بيدفنه في
        // الصفحة العاشرة.
        if ($request->boolean('incomplete')) {
            $q->where(fn ($w) => $w->whereNull('address')->orWhereNull('phone')
                ->orWhereNull('governorate')->orWhereNull('name_en'));
        }

        // ⚠️ **الترتيب من قايمة بيضا.** `orderBy($request->input())`
        // مباشرة بيسمح بترتيب بأي عمود — بما فيهم الرصيد والخصم اللي
        // الشاشة أصلاً مش بتعرضهم. المفتاح بيتترجم هنا لعمود حقيقي
        // أو ساب كويري للاسم المرتبط.
        $sort = $request->string('sort')->value();
        $dir = $request->string('dir')->value() === 'desc' ? 'desc' : 'asc';

        match ($sort) {
            'name' => $q->orderBy('name', $dir),
            'gov' => $q->orderBy('governorate', $dir)->orderBy('code'),
            'status' => $q->orderBy('status', $dir)->orderBy('code'),
            'group' => $q->orderBy(
                ClientGroup::select('name')->whereColumn('client_groups.id', 'clients.group_id'),
                $dir,
            )->orderBy('code'),
            'zone' => $q->orderBy(
                Zone::select('name')->whereColumn('zones.id', 'clients.zone_id'),
                $dir,
            )->orderBy('code'),
            default => $q->orderBy('code', $dir),
        };

        // ⚠️ **عدادات المناطق بنفس فلاتر القايمة** (طلب المالك 2026-08-05):
        // عدد العملاء بكل محافظة، ولو محافظة مختارة — عدد كل منطقة فيها.
        // كلون قبل الترقيم عشان العداد على الكل مش على الصفحة.
        $govCounts = (clone $q)->reorder()
            ->selectRaw('governorate, COUNT(*) as n')
            ->groupBy('governorate')
            ->pluck('n', 'governorate')
            ->sortDesc();

        $zoneCounts = collect();

        if ($gov) {
            $byZone = (clone $q)->reorder()
                ->selectRaw('zone_id, COUNT(*) as n')
                ->groupBy('zone_id')
                ->pluck('n', 'zone_id');
            // ⚠️ كويري واحدة للمناطق — مش Zone::find جوه لوب
            $zoneRows = Zone::whereIn('id', $byZone->keys()->filter())->get()->keyBy('id');
            $zoneCounts = $byZone
                ->map(fn ($n, $zid) => ['zone' => $zoneRows->get($zid), 'n' => (int) $n])
                ->values()
                ->sortByDesc('n')
                ->values();
        }

        return view('erp.client_activate', [
            'clients' => $q->paginate(50)->withQueryString(),
            'govCounts' => $govCounts,
            'zoneCounts' => $zoneCounts,
            'groups' => ClientGroup::withCount([
                'clients as off_count' => fn ($w) => $w->where('status', '!=', 'active'),
            ])->orderBy('name')->get(),
            'zones' => Zone::where('active', true)->orderBy('name')->get(),
            // ⚠️ المدير بيوزّع على **فريقه** بس (١٨/٨) — نفس حارس
            // activate() تحت، عشان الدروب داون مايعرضش اسم يترفض.
            'reps' => User::fieldVisibleTo(
                User::whereIn('role', ['sales_agent', 'promoter'])->where('active', true),
                $request->user(),
            )->orderBy('name')->get(),
            // ⚠️ **من غير الأدمنز** (قرار المالك 2026-08-05) — الدروب داون
            // للتشانل مانجرز اللي بيتوزّع عليهم العملاء، والأدمن مش موظف
            // توزيع. وده نفس مصدر شاشة «عملاء المديرين» والسكوبينج.
            // ⚠️ والمدير بيشوف **نفسه بس** (١٨/٨) — مايوزّعش على غيره.
            'managers' => User::where('role', 'manager')
                ->when($request->user()->role === 'manager',
                    fn ($w) => $w->whereKey($request->user()->id))
                ->where('active', true)->orderBy('name')->get(),
            'lists' => $this->priceLists(),
            // ⚠️ `array_merge` مش `+` — المعامل `+` بيسيب قيمة الشمال،
            // و`status=''` من الريكوست كانت هتغطي على `waiting` المطبّع.
            'filters' => array_merge(
                $request->only(['q', 'group', 'gov', 'incomplete', 'sort', 'dir']),
                ['status' => $status],
            ),
            'waiting' => $this->activationScope($request->user(),
                Client::where('status', '!=', 'active'))->count(),
            'live' => $this->activationScope($request->user(),
                Client::where('status', 'active'))->count(),
        ]);
    }

    /**
     * تسكين محافظة/منطقة من شاشة التفعيل (١٨ أغسطس ٢٠٢٦).
     *
     * طلب المالك: «أسكن محافظة وزون وأعمل Save وأقدر أفعّل العميل».
     * التفعيل بيرفض العميل من غير منطقة — والطريق الوحيد كان ويزارد
     * التعديل الكامل بتلات خطواته لكل فرع. المودال هنا بيكتب
     * الاتنين بس ويرجّعك للقايمة تكمّل.
     *
     * ⚠️ المحافظة بتتاخد من المنطقة المختارة لو اتبعتت فاضية —
     * العمودين لازم يفضلوا متسقين (نفس درس zone-govs).
     */
    public function saveGeo(Request $request, Client $client)
    {
        // نفس سكوب الشاشة — عملاءه + غير المسكّنين المش مفعّلين
        abort_unless(
            $this->activationScope($request->user(), Client::whereKey($client->id))->exists(),
            403,
        );

        $data = $request->validate([
            'governorate' => ['nullable', 'string', 'max:60'],
            'zone_id' => ['nullable', 'exists:zones,id'],
        ]);

        // على الأقل حاجة واحدة — سيف فاضي مالوش معنى
        if (blank($data['governorate'] ?? null) && blank($data['zone_id'] ?? null)) {
            return back()->withErrors(['zone_id' => __('client.geo_nothing')]);
        }

        $zone = ! empty($data['zone_id']) ? Zone::find((int) $data['zone_id']) : null;

        $client->update(array_filter([
            'zone_id' => $zone?->id,
            // المحافظة المبعوتة، وإلا محافظة المنطقة — العمودين متسقين
            'governorate' => filled($data['governorate'] ?? null)
                ? $data['governorate']
                : $zone?->governorate,
        ], fn ($v) => $v !== null));

        return back()->with('ok', __('client.geo_saved', ['name' => $client->displayName()]));
    }

    /**
     * ═══ سكوب شاشة التفعيل (١٨ أغسطس ٢٠٢٦) ═══
     *
     * طلب المالك: «عاوز المدير يظهرله العملاء المش متفعلة وتظهرله
     * صفحة تفعيل العملاء». المشكلة كانت إن `visibleTo` بتحصر المدير
     * في `manager_id` بتاعه — والعملاء المستوردين لسه **من غير مدير
     * خالص**، فالشاشة بتطلعله فاضية.
     *
     * المدير هنا بيشوف: عملاءه + **غير المسكّنين** (مفيش مدير) اللي
     * لسه مش مفعّلين — دول ملك حد، والتفعيل هو بالظبط لحظة التوزيع.
     * عملاء مدير تاني (manager_id متسجل) مخفيين زي ما هم — دوكترين
     * فصل القنوات ماتكسرتش.
     */
    private function activationScope(?User $user, $query)
    {
        if ($user?->role !== 'manager') {
            return Client::visibleTo($query, $user);
        }

        return $query->where(fn ($w) => $w
            ->where('manager_id', $user->id)
            ->orWhere(fn ($x) => $x->whereNull('manager_id')
                ->where('status', '!=', 'active')));
    }

    /**
     * تفعيل مجموعة.
     *
     * ⚠️ **المنطقة والمندوب وقايمة السعر بيتطبّقوا على المعلّم عليهم
     * كلهم.** ده المقصود: بتفلتر بسلسلة أو محافظة، بتعلّم على اللي
     * في منطقة واحدة، وبتفعّلهم دفعة. التفعيل واحد واحد بـ455 فرع
     * شغل شهر.
     */
    public function activate(Request $request)
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'zone_id' => ['nullable', 'exists:zones,id'],
            'rep_id' => ['nullable', 'exists:users,id'],
            'manager_id' => ['nullable', 'exists:users,id'],
            'price_list' => ['nullable', 'string', 'max:40'],
            'category' => ['nullable', 'string', 'max:20'],
        ]);

        // ⚠️ **الشاشة بقت تفعيل وتوزيع** (قرار المالك 2026-08-05):
        // المستني بيتفعّل بالقيم المختارة، والشغّال أصلاً بتتطبّق عليه
        // القيم بس — منطقة/مندوب/تشانل مانجر/قايمة — من غير ما نلمس
        // حالته ولا تاريخ أول نشاطه.
        // ⚠️ وسكوب التشانل مانجر — مايوزّعش عميل مش بتاعه حتى لو بعت
        // الـid. نفس سكوب الشاشة (عملاءه + غير المسكّنين المش مفعّلين).
        $rows = $this->activationScope($request->user(),
            Client::whereIn('id', $data['ids']))->get(['id', 'status', 'zone_id']);

        // ═══ المدير بيسكّن على نفسه وبس (١٨/٨) ═══
        //
        // ⚠️ من غير ده كان بيقدر يبعت `manager_id` بتاع زميله في
        // الريكوست ويرمي عملاء على فريق تاني — أو أخطر: يفعّل عميل
        // غير مسكّن ويسيبه يتيم برضه. تفعيلة المدير = العميل بيدخل
        // بوله هو، والمندوب المختار لازم يكون من فريقه.
        if ($request->user()->role === 'manager') {
            $data['manager_id'] = $request->user()->id;

            if (! empty($data['rep_id'])) {
                $rep = User::find((int) $data['rep_id']);
                abort_unless(
                    $rep !== null && User::fieldVisibleTo(User::whereKey($rep->id), $request->user())->exists(),
                    403,
                );
            }
        }
        $toActivate = $rows->where('status', '!=', 'active')->pluck('id');
        $activeOnes = $rows->where('status', 'active')->pluck('id');

        if ($rows->isEmpty()) {
            return back()->withErrors(['ids' => __('client.activate_none')]);
        }

        $payload = array_filter([
            'zone_id' => $data['zone_id'] ?? null,
            'rep_id' => $data['rep_id'] ?? null,
            'manager_id' => $data['manager_id'] ?? null,
            'price_list' => $data['price_list'] ?? null,
            'category' => $data['category'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        // ⚠️ **العميل من غير منطقة مايبانش لأي مندوب.** بنرفض التفعيل
        // بدل ما يقعد شهر محدش زاره — الشرط على اللي بيتفعّل بس.
        $noZone = $rows->where('status', '!=', 'active')
            ->whereNull('zone_id')
            ->when(isset($payload['zone_id']), fn ($c) => $c->take(0))
            ->count();

        if ($noZone > 0) {
            return back()->withErrors([
                'zone_id' => __('client.activate_needs_zone', ['count' => $noZone]),
            ])->withInput();
        }

        // التوزيع على الشغّالين من غير قيم مختارة مالوش معنى
        if ($toActivate->isEmpty() && $payload === []) {
            return back()->withErrors(['ids' => __('client.nothing_to_apply')]);
        }

        DB::transaction(function () use ($toActivate, $activeOnes, $payload) {
            if ($toActivate->isNotEmpty()) {
                Client::whereIn('id', $toActivate)->update($payload + [
                    'status' => 'active',
                    'first_activity_at' => now(),
                ]);
            }

            if ($activeOnes->isNotEmpty() && $payload !== []) {
                Client::whereIn('id', $activeOnes)->update($payload);
            }

            // ═══ التفعيل بيجرّ التغطية وراه (٢١/٨) ═══
            // العميل اللي اتفعّل واتسكّن لازم منطقته تبقى شغّالة
            // ومتعلّمة لمندوبه ولفريق مديره — وإلا بيفضل مخفي.
            \App\Services\Coverage::syncMany(
                Client::whereIn('id', $toActivate->merge($activeOnes))->get()
            );
        });

        $msg = collect([
            $toActivate->isNotEmpty() ? __('client.activated', ['count' => $toActivate->count()]) : null,
            $activeOnes->isNotEmpty() && $payload !== []
                ? __('client.distributed', ['count' => $activeOnes->count()]) : null,
        ])->filter()->implode(' ');

        return back()->with('ok', $msg);
    }

    /** إيقاف عميل — الرجوع من التفعيل */
    public function deactivate(Request $request, Client $client)
    {
        // ⚠️ **العميل اللي عليه رصيد مايتوقفش.** إيقافه بيخفيه من كل
        // شاشة، والمديونية بتفضل في الإجماليات من غير ما حد يعرف
        // مصدرها.
        if (abs((float) $client->balance) > 0.01) {
            return back()->withErrors([
                'status' => __('client.cannot_stop_with_balance', [
                    'balance' => number_format((float) $client->balance, 2),
                ]),
            ]);
        }

        $client->update(['status' => 'pending']);

        return back()->with('ok', __('client.deactivated', ['name' => $client->displayName()]));
    }

    /**
     * ═══ مسح نهائي — للعميل «البِكر» بس (١٨ أغسطس ٢٠٢٦) ═══
     *
     * طلب المالك: «عندي عملاء نزلوا غلط ومحصلش عليهم أي أكشن عاوز
     * أمسحهم». المسح مسموح **بس** لما مفيش ولا صف واحد في أي جدول
     * حركة — قيود، فواتير، أوامر توريد، مرتجعات، زيارات، رفوف،
     * طلبات بضاعة، هدايا، تتبع. أي حركة = المسح مرفوض برسالة بتقول
     * فيه إيه بالظبط، والبديل الإيقاف (`deactivate` فوق).
     *
     * ⚠️ **الفحص بالجداول مش بالـbalance** — عميل عليه فاتورة
     * ومرتجع بنفس القيمة رصيده صفر، ومسحه بيضيّع تاريخ حقيقي.
     *
     * ⚠️ اللي بيتمسح معاه بالـFK: عقده (cascade — إعداد مش حركة)،
     * وطلب اعتماده لو كان جاي من الأبلكيشن (cascade على
     * `client_requests`). خطط الزيارات والليدز والتارجيتات
     * `nullOnDelete` فبتفضل بس من غير عميل.
     */
    /**
     * ═══ مسح الزيارات القديمة (٢٠ أغسطس ٢٠٢٦) — أدمن بس ═══
     *
     * طلب المالك: مسح فواتير عميل غلط وبعدها مسح العميل — حارس
     * المسح بيقف على «زيارات (1)». الزرار ده بينضّف الزيارات
     * **الفاضية بس**: اللي مالهاش فاتورة ولا مرتجع ولا قيد تحصيل
     * ولا هدية ولا طلب بضاعة مربوط بيها.
     *
     * ⚠️ **الزيارة اللي عليها فلوس أو مستند مابتتمسحش أبداً** —
     * قيد التحصيل الميداني له صورة إثبات ومرساته الزيارة
     * (`source_type=Visit`)؛ مسح الزيارة يسيب الفلوس معلّقة في
     * الهوا. بنقول كام اتمسح وكام اتساب وليه.
     */
    public function purgeVisits(Request $request, Client $client)
    {
        abort_unless($request->user()->role === 'admin', 403);

        $has = fn (string $table, int $visitId) => \Illuminate\Support\Facades\Schema::hasTable($table)
            && \Illuminate\Support\Facades\Schema::hasColumn($table, 'visit_id')
            && DB::table($table)->where('visit_id', $visitId)->exists();

        $deleted = 0;
        $kept = 0;

        DB::transaction(function () use ($client, $has, &$deleted, &$kept) {
            $visits = \App\Models\Visit::where('client_id', $client->id)->get();

            foreach ($visits as $v) {
                $hasMoney = \App\Models\Transaction::where('source_type', \App\Models\Visit::class)
                    ->where('source_id', $v->id)->exists();

                if ($hasMoney
                    || $has('invoices', $v->id)
                    || $has('returns', $v->id)
                    || $has('gift_handouts', $v->id)
                    || $has('replenishment_requests', $v->id)) {
                    $kept++;

                    continue;
                }

                // صور الرف المربوطة بتتمسح بالكاسكيد — زيارة فاضية
                // آثارها التشغيلية بتروح معاها
                $v->delete();
                $deleted++;
            }
        });

        return back()->with('ok', __('client.visits_purged', [
            'deleted' => $deleted,
            'kept' => $kept,
        ]));
    }

    public function destroy(Request $request, Client $client)
    {
        abort_unless($request->user()->role === 'admin', 403);

        $tables = [
            'transactions' => __('client.del_transactions'),
            'invoices' => __('client.del_invoices'),
            'purchase_orders' => __('client.del_pos'),
            'returns' => __('client.del_returns'),
            'visits' => __('client.del_visits'),
            'merch_visits' => __('client.del_merch'),
            'replenishment_requests' => __('client.del_repl'),
            'shelf_refills' => __('client.del_refills'),
            'gift_handouts' => __('client.del_gifts'),
            'track_events' => __('client.del_track'),
            // بضاعة أمانة على رفه = حركة حقيقية
            'custody_items' => __('client.del_custody'),
        ];

        $found = [];

        foreach ($tables as $table => $label) {
            // ⚠️ محروس بـhasTable/hasColumn — جداول زي gift_handouts
            // اتضافت في مايجريشنز لاحقة، والسيرفر بيترفع بإيد.
            if (! \Illuminate\Support\Facades\Schema::hasTable($table)
                || ! \Illuminate\Support\Facades\Schema::hasColumn($table, 'client_id')) {
                continue;
            }

            $n = DB::table($table)->where('client_id', $client->id)->count();

            if ($n > 0) {
                $found[] = $label.' ('.$n.')';
            }
        }

        if ($found !== []) {
            return back()->withErrors([
                'status' => __('client.cannot_delete_active', [
                    'name' => $client->displayName(),
                    'things' => implode(' · ', $found),
                ]),
            ]);
        }

        $name = $client->displayName();

        DB::transaction(function () use ($client) {
            // صور ومستندات العميل الغلط مالهاش لازمة تفضل على الديسك
            foreach ([$client->photo_path, $client->docs_path] as $path) {
                if ($path) {
                    \Illuminate\Support\Facades\Storage::disk('public')->delete($path);
                }
            }

            $client->delete();
        });

        return redirect()->route('erp.clients')
            ->with('ok', __('client.deleted_forever', ['name' => $name]));
    }

    /**
     * قوايم الأسعار المتاحة.
     *
     * ⚠️ مؤقتة لحد ما جدول `price_lists` ينزل — بترجّع الاتنين
     * الموجودين في الدوكترين الحالي.
     */
    private function priceLists(): array
    {
        return ['old' => __('stock.price_old'), 'new' => __('stock.price_new')];
    }
}
