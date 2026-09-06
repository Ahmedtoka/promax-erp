<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * تسكين عملاء التشانل مانجر (قرار المالك 2026-08-05)
 * ═══════════════════════════════════════════════════════════════
 *
 * نفس نمط شاشة تسكين المناديب: الأدمن بيختار المدير، بيعلّم على
 * العملاء من قايمة «من غير مدير»، بيدوس أساين — بيختفوا من القايمة
 * ويبقوا معاه. `clients.manager_id` هو نفس عمود «مدير القناة» اللي
 * في فورم العميل — مصدر واحد.
 *
 * ⚠️ **التسكين حصري.** العميل ليه مدير واحد — التسكين لمدير بيشيله
 * من أي حد تاني. وده أساس السكوبينج: المدير مش بيشوف غير عملاءه.
 */
class ManagerClientController extends Controller
{
    public function index(Request $request)
    {
        $managers = User::where('role', 'manager')
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        $manager = $request->filled('manager')
            ? $managers->firstWhere('id', (int) $request->input('manager'))
            : $managers->first();

        // ⚠️ `group` محمّلة — fullName() بيعرض «السلسلة — الفرع»
        $mine = $manager
            ? Client::with(['zone', 'group', 'channel'])
                ->where('manager_id', $manager->id)
                ->orderBy('name')->get()
            : collect();

        // العملاء اللي من غير مدير — البحث في السيرفر والقايمة مقصوصة
        $pool = Client::with(['zone', 'group', 'channel'])
            ->whereNull('manager_id')
            ->where('status', 'active')
            ->when($request->filled('channel'), fn ($q) => $q->where('channel_id', $request->input('channel')))
            ->when($request->filled('q'), function ($q) use ($request) {
                // بحث العميل الموحّد (٦/٩) — Client::search بدل LIKE يدوي
                Client::search($q, $request->string('q')->trim()->value());
            })
            ->orderBy('name')
            ->limit(300)
            ->get();

        // ═══ فريق الميدان (2026-08-05): مناديب وسواقين وبروموترز ═══
        $myTeam = $manager
            ? User::whereIn('role', User::FIELD_ROLES)
                ->where('manager_id', $manager->id)
                ->orderBy('role')->orderBy('name')->get()
            : collect();

        $teamPool = User::whereIn('role', User::FIELD_ROLES)
            ->whereNull('manager_id')
            ->where('active', true)
            ->orderBy('role')->orderBy('name')
            ->get();

        // ═══ فاحص الظهور (٢١/٨) — «العميل عند المدير بس مش طالع للمندوب» ═══
        //
        // بلاغ المالك: عملاء متسكّنين للمدير ومتفعّلين، ومع ذلك المندوب
        // مش شايفهم كلهم. الأبلكيشن بيعرض العملاء **مجمّعين تحت مناطق
        // نشطة** (`zonesPayload`)، فأي حلقة ناقصة في السلسلة بتخفي
        // العميل في صمت. الفاحص ده بيقول السبب بالحرف بدل التخمين:
        //
        //   ١. العميل موقوف/مستني تفعيل   → `status != active`
        //   ٢. العميل من غير زون          → `zone_id = null`
        //   ٣. زون العميل موقوف           → `zones.active = false`
        //   ٤. المدير مالوش فريق ميداني   → مفيش حد يشوفهم أصلاً
        $blockers = [];

        if ($manager !== null) {
            $hasField = $myTeam->whereIn('role', User::FIELD_WORK_ROLES)->isNotEmpty();

            foreach ($mine as $c) {
                $why = null;

                if ($c->status !== 'active') {
                    $why = 'status';
                } elseif ($c->zone_id === null) {
                    $why = 'nozone';
                } elseif ($c->zone !== null && ! $c->zone->active) {
                    $why = 'zoneoff';
                } elseif (! $hasField) {
                    $why = 'noteam';
                }

                if ($why !== null) {
                    $blockers[] = ['client' => $c, 'why' => $why];
                }
            }
        }

        // ═══ «شوف زي المندوب» (٢١/٨) — الحقيقة مش التخمين ═══
        //
        // بيشغّل **نفس كويري الأبلكيشن بالحرف** (`zonesPayload`) لكل
        // مندوب في الفريق ويقول: هيشوف كام عميل فعلاً في الأبلكيشن.
        // لو الرقم أقل من عملاء المدير، يبقى البول المشترك مكسور —
        // وأول شرط بيتكسر هو `users.manager_id` للمندوب نفسه.
        $seeAs = [];

        foreach ($myTeam->whereIn('role', User::FIELD_WORK_ROLES) as $member) {
            $payload = \App\Http\Controllers\Api\FieldApiController::zonesPayload($member);

            $seeAs[] = [
                'user' => $member,
                'clients' => collect($payload)->sum(fn ($z) => count($z['clients'] ?? [])),
                'zones' => count($payload),
                // ⚠️ الشرط اللي بيفتح البول — من غيره بيشوف عملاءه بس
                'linked' => (int) $member->manager_id === (int) $manager?->id,
            ];
        }

        return view('erp.manager_clients', [
            'managers' => $managers,
            'manager' => $manager,
            'mine' => $mine,
            'pool' => $pool,
            'myTeam' => $myTeam,
            'teamPool' => $teamPool,
            'blockers' => $blockers,
            'seeAs' => $seeAs,
            'channels' => \App\Models\Channel::orderBy('id')->get(['id', 'name', 'name_en']),
        ]);
    }

    /** تسكين مناديب/سواقين لمدير — نفس منطق تسكين العملاء بالظبط */
    public function assignTeam(Request $request)
    {
        $data = $request->validate([
            'manager_id' => ['required', 'exists:users,id'],
            'user_ids' => ['required', 'array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $manager = User::findOrFail($data['manager_id']);

        abort_if($manager->role !== 'manager', 422, __('perm.not_a_manager'));

        $count = 0;

        DB::transaction(function () use ($data, $manager, &$count) {
            // ⚠️ رولز الميدان بس — مايتسكّنش محاسب ولا أمين مخزن لمدير
            $count = User::whereIn('id', $data['user_ids'])
                ->whereIn('role', User::FIELD_ROLES)
                ->update(['manager_id' => $manager->id]);

            // ═══ ضم مندوب للفريق بيفتح له بول المدير كله (٢١/٨) ═══
            // ⚠️ **دي أهم نقطة في البلاغ**: المندوب بقى يشوف عملاء
            // المدير بالبول، بس مناطقهم لسه مش متعلّمة عليه — فالشاشة
            // بتفضل فاضية. بنعلّمها كلها دلوقتي.
            \App\Services\Coverage::syncMany(
                Client::where('manager_id', $manager->id)
                    ->where('status', 'active')
                    ->whereNotNull('zone_id')
                    ->get()
            );
        });

        return back()->with('ok', __('perm.team_assigned', ['count' => $count, 'name' => $manager->name]));
    }

    public function unassignTeam(User $user)
    {
        abort_unless(in_array($user->role, User::FIELD_ROLES, true), 422);

        $user->update(['manager_id' => null]);

        return back()->with('ok', __('perm.team_unassigned', ['name' => $user->name]));
    }

    public function assign(Request $request)
    {
        $data = $request->validate([
            'manager_id' => ['required', 'exists:users,id'],
            'client_ids' => ['required', 'array'],
            'client_ids.*' => ['integer', 'exists:clients,id'],
        ]);

        $manager = User::findOrFail($data['manager_id']);

        abort_if($manager->role !== 'manager', 422, __('perm.not_a_manager'));

        $count = 0;

        DB::transaction(function () use ($data, $manager, &$count) {
            $count = Client::whereIn('id', $data['client_ids'])
                ->update(['manager_id' => $manager->id]);

            // ═══ تسكين المدير بيجرّ التغطية وراه (٢١/٨) ═══
            // العميل بقى في بول الفريق — يبقى منطقته لازم تكون
            // متعلّمة لكل مناديب المدير، وإلا مش هتبان في شاشتهم.
            \App\Services\Coverage::syncMany(
                Client::whereIn('id', $data['client_ids'])->get()
            );
        });

        return back()->with('ok', __('perm.clients_assigned', ['count' => $count, 'name' => $manager->name]));
    }

    /**
     * ═══ إصلاح التغطية بأثر رجعي (٢١/٨) ═══
     *
     * زرار «صلّح التغطية كلها»: بيلف على كل عميل شغّال له منطقة،
     * بيفعّل منطقته لو موقوفة وبيعلّمها لمندوبه ولفريق مديره — بيصلّح
     * كل التسكينات القديمة اللي اتعملت قبل ما الفلو يتقفل.
     *
     * ⚠️ آمن تماماً: **إضافة بس** — مافيش أي تعليم بيتشال ولا عميل
     * بيتحرّك من مندوب لمندوب.
     */
    public function repairCoverage(Request $request)
    {
        abort_unless($request->user()?->isAdmin() ?? false, 403);

        $r = \App\Services\Coverage::repairAll();

        return back()->with('ok', __('perm.cov_done', [
            'clients' => number_format($r['clients']),
            'zones' => number_format($r['zones']),
            'links' => number_format($r['links']),
        ]));
    }

    public function unassign(Client $client)
    {
        $client->update(['manager_id' => null]);

        return back()->with('ok', __('perm.client_unassigned', ['name' => $client->fullName()]));
    }
}
