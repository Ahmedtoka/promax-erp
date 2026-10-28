<?php

namespace App\Http\Controllers;

use App\Exceptions\Rejected;
use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\PriceChangeRun;
use App\Services\Reprice\PriceChange;
use App\Support\Access;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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

        // ⚠️ القايمة بتتحسب على مستوى الشركة كلها (`company_wide`) — غير
        // الأدمن يشوف الإجماليات وتغييرات الأصناف بس، من غير صفوف مستندات
        // ولا أسماء عملاء (داتا فرق تانية). الصفوف ماتوصلش للفيو أصلاً.
        $aggregateOnly = ! $user->isAdmin()
            && (bool) ($result['company_wide'] ?? $run->trigger === 'list');

        // ⚠️ «للبيع الجديد بس» و«ملغي» مفيش ولا مستند اتلمس — صفوف المعاينة
        // القديمة مابتتعرضش كأنها اتغيرت
        $docsShown = ! $aggregateOnly && in_array($run->status, ['draft', 'applied'], true);

        if (! $docsShown) {
            $docs = collect();
            $docsTotal = 0;
        } elseif ($run->isDraft()) {
            $docs = collect($result['docs'] ?? []);
            $docsTotal = (int) ($result['docs_total'] ?? $docs->count());
        } else {
            $docs = $run->docs()->orderBy('id')->limit(PriceChange::PREVIEW_DOC_ROWS)->get()
                // ⚠️ الأول التاريخ المحلي: `+` بيسيب مفتاح الشمال، و`toArray()` بيطلّع doc_date ISO بتوقيت UTC (يوم بدري)
                ->map(fn ($d) => ['doc_date' => $d->doc_date?->toDateString()] + $d->toArray());
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
            'aggregateOnly' => $aggregateOnly,
            'docsShown' => $docsShown,
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

        if ($fail = $this->attempt(fn () => PriceChange::retarget($run, $data['scope'] ?? null, $data['since'] ?? null))) {
            return $fail;
        }

        return redirect()->route('erp.prices.change', $run)->with('ok', __('reprice.preview_refreshed'));
    }

    /** ✅ التأكيد — الأسعار + المستندات (أدمن بس، الراوت عليه `role:admin`) */
    public function apply(Request $request, PriceChangeRun $run)
    {
        $this->guardOwner($request, $run);

        $r = null;
        if ($fail = $this->attempt(function () use ($run, $request, &$r) {
            $r = PriceChange::apply($run, $request->user());
        })) {
            return $fail;
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

        if ($fail = $this->attempt(fn () => PriceChange::applyMasterOnly($run, $request->user()))) {
            return $fail;
        }

        return redirect()->to($this->homeOf($run))->with('ok', __('reprice.master_ok'));
    }

    public function cancel(Request $request, PriceChangeRun $run)
    {
        $this->guardOwner($request, $run);

        if ($fail = $this->attempt(fn () => PriceChange::cancel($run))) {
            return $fail;
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

    /**
     * تشغيل أكشن من المحرك — null لو نجح، أو رد «ارجع ومعاك الرسالة».
     *
     *   • `Rejected` (مش مسودة / الأرقام اتغيرت / الشجرة رفضت …) → رسالته.
     *   • `ValidationException` (شهر مقفول في مود الويب مثلاً) → أخطاؤه.
     *   • ديد لوك / انتظار لوك (1213 / 1205 / SQLSTATE 40001) → «جرّب تاني»
     *     — التعديل كله رجع فمفيش حاجة نص نص.
     *   • أي حاجة تانية → تكمّل لـ500 وتتسجل (⚠️ ممنوع نبلع أخطاء SQL).
     */
    private function attempt(callable $fn)
    {
        try {
            $fn();
        } catch (Rejected $e) {
            return back()->withErrors(['reprice' => $e->getMessage()]);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        } catch (QueryException|DeadlockException $e) {
            if (self::isLockError($e)) {
                return back()->withErrors(['reprice' => __('reprice.retry_locked')]);
            }

            throw $e;
        }

        return null;
    }

    private static function isLockError(QueryException|DeadlockException $e): bool
    {
        // ⚠️ جوه ترانزاكشن متداخلة لارافيل بيلف الديد لوك في DeadlockException
        // ويطلّعه لبره — ده نفسه علامة اللوك
        if ($e instanceof DeadlockException) {
            return true;
        }

        $info = $e->errorInfo ?? [];

        return in_array((int) ($info[1] ?? 0), [1213, 1205], true)
            || (string) ($info[0] ?? '') === '40001'
            || (string) $e->getCode() === '40001';
    }

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
