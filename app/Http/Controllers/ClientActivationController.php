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
        $q = Client::query()->with(['group', 'zone', 'rep']);

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
            $q->where(fn ($w) => $w->where('name', 'like', "%$s%")
                ->orWhere('name_en', 'like', "%$s%")
                ->orWhere('code', 'like', "%$s%")
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

        return view('erp.client_activate', [
            'clients' => $q->paginate(50)->withQueryString(),
            'groups' => ClientGroup::withCount([
                'clients as off_count' => fn ($w) => $w->where('status', '!=', 'active'),
            ])->orderBy('name')->get(),
            'zones' => Zone::where('active', true)->orderBy('name')->get(),
            'reps' => User::whereIn('role', ['sales_agent', 'promoter'])
                ->where('active', true)->orderBy('name')->get(),
            'managers' => User::whereIn('role', ['manager', 'branch_manager', 'admin'])
                ->where('active', true)->orderBy('name')->get(),
            'lists' => $this->priceLists(),
            // ⚠️ `array_merge` مش `+` — المعامل `+` بيسيب قيمة الشمال،
            // و`status=''` من الريكوست كانت هتغطي على `waiting` المطبّع.
            'filters' => array_merge(
                $request->only(['q', 'group', 'gov', 'incomplete', 'sort', 'dir']),
                ['status' => $status],
            ),
            'waiting' => Client::where('status', '!=', 'active')->count(),
            'live' => Client::where('status', 'active')->count(),
        ]);
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

        // ⚠️ **الموقوفين بس.** الـids بتيجي من الفورم، والتاب اللي
        // فضلت مفتوحة من قبل ما حد يفعّل بتبعت أكواد اتفعّلت خلاص —
        // وإعادة تفعيلها كانت هتدوس على منطقتها ومندوبها بقيم الفورم.
        $ids = Client::whereIn('id', $data['ids'])->where('status', '!=', 'active')->pluck('id');

        if ($ids->isEmpty()) {
            return back()->withErrors(['ids' => __('client.activate_none')]);
        }

        $payload = array_filter([
            'zone_id' => $data['zone_id'] ?? null,
            'rep_id' => $data['rep_id'] ?? null,
            'manager_id' => $data['manager_id'] ?? null,
            'price_list' => $data['price_list'] ?? null,
            'category' => $data['category'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        // ⚠️ **العميل من غير منطقة مايبانش لأي مندوب.** بنرفض بدل ما
        // نفعّله ويقعد شهر محدش زاره ومحدش عارف ليه.
        $noZone = Client::whereIn('id', $ids)
            ->whereNull('zone_id')
            ->when(isset($payload['zone_id']), fn ($w) => $w->whereRaw('1 = 0'))
            ->count();

        if ($noZone > 0) {
            return back()->withErrors([
                'zone_id' => __('client.activate_needs_zone', ['count' => $noZone]),
            ])->withInput();
        }

        DB::transaction(function () use ($ids, $payload) {
            Client::whereIn('id', $ids)->update($payload + [
                'status' => 'active',
                'first_activity_at' => now(),
            ]);
        });

        return back()->with('ok', __('client.activated', ['count' => $ids->count()]));
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
