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
                $s = $request->string('q')->trim()->value();
                $q->where(fn ($w) => $w->where('name', 'like', "%$s%")
                    ->orWhere('name_en', 'like', "%$s%")
                    ->orWhere('code', 'like', "%$s%")
                    ->orWhereHas('group', fn ($g) => $g->where('name', 'like', "%$s%")
                        ->orWhere('name_en', 'like', "%$s%")));
            })
            ->orderBy('name')
            ->limit(300)
            ->get();

        return view('erp.manager_clients', [
            'managers' => $managers,
            'manager' => $manager,
            'mine' => $mine,
            'pool' => $pool,
            'channels' => \App\Models\Channel::orderBy('id')->get(['id', 'name', 'name_en']),
        ]);
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
        });

        return back()->with('ok', __('perm.clients_assigned', ['count' => $count, 'name' => $manager->name]));
    }

    public function unassign(Client $client)
    {
        $client->update(['manager_id' => null]);

        return back()->with('ok', __('perm.client_unassigned', ['name' => $client->fullName()]));
    }
}
