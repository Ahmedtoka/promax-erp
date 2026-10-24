<?php

namespace App\Http\Controllers;

use App\Exceptions\Rejected;
use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\PriceChangeRun;
use App\Services\Reprice\PriceChange;
use App\Support\Access;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ═══════════════════════════════════════════════════════════════
 * صفحة معاينة / تأكيد تعديل الأسعار (Lane 13)
 * ═══════════════════════════════════════════════════════════════
 *
 * كل مصدر تعديل سعر (بنود قايمة، قايمة سلسلة، قايمة فرع/عميل) بيعمل
 * مسودة في `PriceChange::draft` ويحوّل هنا. من هنا:
 *   • «✅ تأكيد» = الأسعار + كل المستندات المؤهلة (أدمن بس —
 *     `act.prices.propagate`، والراوت عليه `role:admin`).
 *   • «💾 للبيع الجديد بس» = سلوك النهاردة (صاحب المسودة أو الأدمن).
 *   • «✖ إلغاء».
 *
 * ⚠️ المسودة بتاعة اللي عملها — مدير تاني مايشوفهاش ولا يأكّدها
 * (المعاينة بتتحسب بسكوب صاحبها، فأرقامها مش أرقامه).
 * ⚠️ أي `Rejected` من المحرك (مش مسودة / الأرقام اتغيرت / كتير) =
 * رسالة على الصفحة، مش 500.
 */
class PriceChangeController extends Controller
{
    public function show(Request $request, PriceChangeRun $run)
    {
        $this->guardOwner($request, $run);

        $run->load(['user', 'priceList', 'group', 'client']);
        $user = $request->user();

        // بعد التطبيق المستندات من صفوف السجل نفسها؛ في المسودة من المعاينة المتخزنة
        $result = $run->isDraft() ? ($run->preview ?? []) : ($run->summary ?? $run->preview ?? []);

        if ($run->isDraft()) {
            $docs = collect($result['docs'] ?? []);
            $docsTotal = (int) ($result['docs_total'] ?? $docs->count());
        } else {
            $docs = $run->docs()->orderBy('id')->limit(PriceChange::PREVIEW_DOC_ROWS)->get()
                ->map(fn ($d) => $d->toArray() + ['doc_date' => $d->doc_date?->toDateString()]);
            $docsTotal = $run->docs()->count();
        }

        // ⚠️ الفرع: لو القايمة الجديدة غير قايمة السلسلة، بنسأله فرع بس ولا كل الفروع
        $chain = null;
        if ($run->trigger === 'branch' && $run->group !== null) {
            $chain = PriceChange::chainListOf($run->group);
        }

        $clientIds = $docs->pluck('client_id')->filter()->unique()->values();
        $names = Client::whereIn('id', $clientIds)->get()->mapWithKeys(fn ($c) => [$c->id => $c->displayName()]);

        return view('erp.price_change', [
            'run' => $run,
            'result' => $result,
            'changed' => $docs->where('outcome', 'changed')->values(),
            'skipped' => $docs->where('outcome', 'skipped')->values(),
            'docsTotal' => $docsTotal,
            'shownDocs' => $docs->count(),
            'names' => $names,
            'chain' => $chain,
            'canApply' => Access::allows($user, 'erp.prices.change.apply'),
            'canLog' => Access::allows($user, 'erp.price_changes'),
            'target' => $this->targetName($run),
        ]);
    }

    public function refresh(Request $request, PriceChangeRun $run)
    {
        $this->guardOwner($request, $run);

        $data = $request->validate([
            'scope' => ['nullable', Rule::in(['branch', 'chain'])],
            'since' => ['nullable', 'date'],
        ]);

        try {
            PriceChange::retarget($run, $data['scope'] ?? null, $data['since'] ?? null);
        } catch (Rejected $e) {
            return back()->withErrors(['reprice' => $e->getMessage()]);
        }

        return redirect()->route('erp.prices.change', $run)->with('ok', __('reprice.preview_refreshed'));
    }

    /** ✅ التأكيد — الأسعار + المستندات (أدمن بس، الراوت عليه `role:admin`) */
    public function apply(Request $request, PriceChangeRun $run)
    {
        $this->guardOwner($request, $run);

        try {
            $r = PriceChange::apply($run, $request->user());
        } catch (Rejected $e) {
            return back()->withErrors(['reprice' => $e->getMessage()]);
        }

        return redirect()->route('erp.prices.change', $run)->with('ok', __('reprice.applied_ok', [
            'invoices' => $r['invoices_changed'],
            'pos' => $r['pos_changed'],
            'skipped' => $r['skipped'],
        ]));
    }

    /** 💾 احفظ الأسعار للبيع الجديد بس — سلوك النهاردة */
    public function master(Request $request, PriceChangeRun $run)
    {
        $this->guardOwner($request, $run);

        try {
            PriceChange::applyMasterOnly($run, $request->user());
        } catch (Rejected $e) {
            return back()->withErrors(['reprice' => $e->getMessage()]);
        }

        return redirect()->to($this->homeOf($run))->with('ok', __('reprice.master_ok'));
    }

    public function cancel(Request $request, PriceChangeRun $run)
    {
        $this->guardOwner($request, $run);

        try {
            PriceChange::cancel($run);
        } catch (Rejected $e) {
            return back()->withErrors(['reprice' => $e->getMessage()]);
        }

        return redirect()->to($this->homeOf($run))->with('ok', __('reprice.cancelled_ok'));
    }

    /**
     * «طبّق التسعيرة الحالية على المستندات» — مسودة من غير أي تغيير
     * في القايمة (`price_list_id` = null). أدمن بس.
     */
    public function resync(Request $request)
    {
        $data = $request->validate([
            'trigger' => ['required', Rule::in(['chain', 'client'])],
            'id' => ['required', 'integer'],
            'since' => ['nullable', 'date'],
        ]);

        try {
            if ($data['trigger'] === 'chain') {
                $group = ClientGroup::findOrFail($data['id']);
                $run = PriceChange::draft($request->user(), 'chain', ['group_id' => $group->id, 'price_list_id' => null], $data['since'] ?? null);
            } else {
                $client = Client::findOrFail($data['id']);
                abort_unless($client->visibleBy($request->user()), 403);
                $run = PriceChange::draft($request->user(), 'client', ['client_id' => $client->id, 'price_list_id' => null], $data['since'] ?? null);
            }
        } catch (Rejected $e) {
            return back()->withErrors(['reprice' => $e->getMessage()]);
        }

        return redirect()->route('erp.prices.change', $run);
    }

    // ═══════════════════════ مساعدات ═══════════════════════

    /** الأدمن أو صاحب المسودة — غير كده 403 */
    private function guardOwner(Request $request, PriceChangeRun $run): void
    {
        $user = $request->user();

        abort_unless($user->isAdmin() || (int) $run->user_id === (int) $user->id, 403);
    }

    /** بعد الحفظ/الإلغاء نرجع للشاشة اللي التعديل جه منها */
    private function homeOf(PriceChangeRun $run): string
    {
        return match ($run->trigger) {
            'list' => $run->price_list_id ? route('erp.prices.show', $run->price_list_id) : route('erp.prices'),
            'chain' => $run->group_id ? route('erp.groups.show', $run->group_id) : route('erp.groups'),
            default => $run->client_id ? route('erp.clients.show', $run->client_id) : route('erp.prices'),
        };
    }

    private function targetName(PriceChangeRun $run): ?string
    {
        return match ($run->trigger) {
            'list' => $run->priceList?->displayName(),
            'chain' => $run->group?->displayName(),
            default => $run->client?->displayName(),
        };
    }
}
