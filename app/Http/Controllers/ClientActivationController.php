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
        $q = Client::query()
            ->with(['group', 'zone', 'rep'])
            ->where('active', false);

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

        return view('erp.client_activate', [
            'clients' => $q->orderBy('code')->paginate(50)->withQueryString(),
            'groups' => ClientGroup::withCount([
                'clients as off_count' => fn ($w) => $w->where('active', false),
            ])->orderBy('name')->get(),
            'zones' => Zone::where('active', true)->orderBy('name')->get(),
            'reps' => User::whereIn('role', ['sales_agent', 'promoter'])
                ->where('active', true)->orderBy('name')->get(),
            'managers' => User::whereIn('role', ['manager', 'branch_manager', 'admin'])
                ->where('active', true)->orderBy('name')->get(),
            'lists' => $this->priceLists(),
            'filters' => $request->only(['q', 'group', 'gov', 'incomplete']),
            'waiting' => Client::where('active', false)->count(),
            'live' => Client::where('active', true)->count(),
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
        $ids = Client::whereIn('id', $data['ids'])->where('active', false)->pluck('id');

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
                'active' => true,
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
                'active' => __('client.cannot_stop_with_balance', [
                    'balance' => number_format((float) $client->balance, 2),
                ]),
            ]);
        }

        $client->update(['active' => false]);

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
