<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\Invoice;
use App\Models\Transaction;
use Illuminate\Http\Request;

/**
 * السلاسل والمجموعات — Circle K وجورميه وغيرهم
 */
class GroupController extends Controller
{
    public function index(Request $request)
    {
        $q = ClientGroup::query()->with('channel')->withCount('clients');

        if ($s = $request->string('q')->trim()->value()) {
            $q->where('name', 'like', "%$s%");
        }
        if ($ch = $request->integer('channel')) {
            $q->where('channel_id', $ch);
        }

        $groups = $q->orderBy('name')->get();

        // أرقام كل سلسلة في استعلام واحد
        $stats = Client::query()
            ->whereNotNull('group_id')
            ->selectRaw('group_id,
                SUM(purchases) as purchases,
                SUM(collections) as collections,
                SUM(balance) as balance')
            ->groupBy('group_id')
            ->get()->keyBy('group_id');

        return view('erp.groups', [
            'groups' => $groups,
            'stats' => $stats,
            'channels' => Channel::orderBy('id')->get(),
            'filters' => $request->only(['q', 'channel']),
            'ungrouped' => Client::whereNull('group_id')
                ->where('category', '!=', 'internal')->count(),
        ]);
    }

    public function show(ClientGroup $group, Request $request)
    {
        $branches = $group->clients()
            ->with(['zone', 'contract', 'group.contract'])
            ->orderByDesc('purchases')
            ->get();

        $ids = $branches->pluck('id');

        $monthly = Transaction::whereIn('client_id', $ids)
            ->selectRaw("DATE_FORMAT(date, '%Y-%m') as m,
                         SUM(CASE WHEN kind = 'sale' THEN debit ELSE 0 END) as sales,
                         SUM(CASE WHEN kind = 'collection' THEN credit ELSE 0 END) as coll")
            ->groupBy('m')->orderBy('m')->get();

        return view('erp.group', [
            'g' => $group->load('channel'),
            'branches' => $branches,
            'monthly' => $monthly,
            'todaySales' => (float) Invoice::whereIn('client_id', $ids)
                ->whereDate('created_at', today())->sum('total'),
            'contracts' => $branches->filter(fn ($b) => $b->contract !== null),
            'zones' => \App\Models\Zone::orderBy('code')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request, creating: true);

        // الكود ثابت من الاسم — لو اتكرر بنزوّد رقم عشان الـ unique ما يقعش
        $base = ClientGroup::nextCode($data['name']);
        $code = $base;
        $n = 2;
        while (ClientGroup::where('code', $code)->exists()) {
            $code = substr($base, 0, 36).'-'.$n++;
        }
        $data['code'] = $code;

        $group = ClientGroup::create($data);

        return redirect()->route('erp.groups.show', $group)->with('ok', __('flash.chain_created'));
    }

    public function update(Request $request, ClientGroup $group)
    {
        $group->update($this->validated($request));

        return back()->with('ok', __('flash.chain_saved'));
    }

    public function destroy(ClientGroup $group)
    {
        if ($group->clients()->exists()) {
            // مش بنمسح سلسلة فيها فروع — بنوقفها
            $group->update(['active' => false]);

            return back()->with('ok', __('flash.chain_suspended'));
        }

        $group->delete();

        return redirect()->route('erp.groups')->with('ok', __('flash.chain_deleted'));
    }

    /** ضم فروع للسلسلة أو فكّها */
    public function attach(Request $request, ClientGroup $group)
    {
        $data = $request->validate([
            'client_ids' => ['required', 'array'],
            'client_ids.*' => ['exists:clients,id'],
            'action' => ['required', 'in:attach,detach'],
        ]);

        Client::whereIn('id', $data['client_ids'])->update([
            'group_id' => $data['action'] === 'attach' ? $group->id : null,
        ]);

        $n = count($data['client_ids']);

        return back()->with('ok', $data['action'] === 'attach'
            ? __('flash.branches_attached', ['count' => $n])
            : __('flash.branches_detached', ['count' => $n]));
    }

    private function validated(Request $request, bool $creating = false): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            // ⚠️ الاسم الإنجليزي إجباري الوجود في الفورم — العميل
            // بيتعرض في الفاتورة والتصدير للمصلحة وكشوف السلسلة،
            // والاسم العربي في شاشة إنجليزية بيكسّر الصفحة بصرياً.
            'name_en' => ['nullable', 'string', 'max:120'],
            'channel_id' => ['nullable', 'exists:channels,id'],
            'sub_channel' => ['nullable', 'in:chain,convenience'],
            // ⚠️ **مفيش خصم ولا مسؤول على السلسلة.** قرار 2026-08-01:
            // السلسلة تجميعة عرض بس. كل فرع عميل مستقل بعقده وخصمه
            // ومسؤوله — والخصم على مستوى السلسلة كان بيتجاهل اتفاق
            // الفرع اللي اتفاوض لوحده.
            'notes' => ['nullable', 'string'],
        ]);
        // في الإنشاء السلسلة شغّالة افتراضيًا، في التعديل الشيك بوكس هو الحكم
        // (لو مش متبعت يبقى المستخدم شالها = موقوفة).
        $data['active'] = $creating ? $request->boolean('active', true) : $request->boolean('active');

        return $data;
    }
}
