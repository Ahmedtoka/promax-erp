<?php

namespace App\Http\Controllers;

use App\Models\AccountAudit;
use App\Models\Client;
use App\Models\ClientGroup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * ═══════════════════════════════════════════════════════════════
 * مراجعة الحسابات — سلسلة سلسلة وعميل عميل  ·  ٢٨ أغسطس ٢٠٢٦
 * ═══════════════════════════════════════════════════════════════
 *
 * طلب المالك بالحرف: «بقالي سنة ونص شغال ... وعندي مشكلة كبيرة في
 * الحسابات. ليست بالسلاسل وجمبها الزراير: حساب العميل موجود؟ أه/لا
 * — لو أه كام حسابه — وكشف الحساب موجود؟ أه ارفع الكشف إكسيل.
 * وفوق سامري بالإجماليات عشان أبقى عارف راسي من رجلي».
 *
 * ⚠️ **`their_balance` مش رصيد.** ده الرقم اللي العميل قايله،
 * وبيتعرض جنب رصيدنا (المحسوب من القيود) عشان **الفرق** يبان —
 * والفرق ده هو الشغل كله. مفيش سطر واحد في السيستم بيقرا الرقم ده
 * كرصيد، ومفيش قيد بيتولد منه.
 *
 * ⚠️ **صفحة العملاء = الفرادى بس** (طلب المالك): أي عميل جوه سلسلة
 * بيتراجع من صفحة السلاسل — مراجعته مرتين شغل مكرر وأرقام متضاربة.
 */
class AccountAuditController extends Controller
{
    /** صفحة السلاسل — **مرتبة بأكبر عدد فروع** (طلب المالك ٢٨/٨) */
    public function chains(Request $request)
    {
        $groups = ClientGroup::withCount('clients')
            ->where('active', true)
            ->orderByDesc('clients_count')->orderBy('name')->get();

        // رصيدنا للسلسلة = مجموع أرصدة فروعها (سكوب الرؤية محفوظ)
        $ours = Client::visibleTo(Client::query(), $request->user())
            ->whereIn('group_id', $groups->pluck('id'))
            ->where('status', '!=', 'rejected')
            ->selectRaw('group_id, COALESCE(SUM(balance),0) bal')
            ->groupBy('group_id')->pluck('bal', 'group_id');

        $audits = $this->auditsFor('group', $groups->pluck('id'));

        $rows = $groups->map(fn ($g) => [
            'id' => $g->id,
            'title' => $g->displayName(),
            'sub' => __('audit.branches_n', ['n' => $g->clients_count]),
            'branches' => (int) $g->clients_count,
            'ours' => (float) ($ours[$g->id] ?? 0),
            'audit' => $audits[$g->id] ?? null,
        ]);

        return $this->render($request, 'chains', $rows);
    }

    /** صفحة العملاء الفرادى — **بدون** أي عميل في سلسلة */
    public function clients(Request $request)
    {
        // ⚠️ الأكبر رصيداً الأول — نفس منطق «أكبر عدد فروع» في
        // السلاسل: تبدأ باللي فلوسه أكتر، مش بالترتيب الأبجدي
        $clients = Client::visibleTo(Client::query(), $request->user())
            ->with('zone')
            ->whereNull('group_id')
            ->where('status', '!=', 'rejected')
            ->orderByDesc('balance')->orderBy('name')->get();

        $audits = $this->auditsFor('client', $clients->pluck('id'));

        $rows = $clients->map(fn ($c) => [
            'id' => $c->id,
            'title' => $c->displayName(),
            'sub' => trim(($c->code ?: '').' · '.($c->zone?->displayName() ?? '—'), ' ·'),
            'branches' => null,
            'ours' => (float) $c->balance,
            'audit' => $audits[$c->id] ?? null,
        ]);

        return $this->render($request, 'clients', $rows);
    }

    /** صفوف المراجعة الموجودة مفهرسة بالكيان */
    private function auditsFor(string $type, $ids)
    {
        return AccountAudit::where('entity_type', $type)
            ->whereIn('entity_id', $ids)
            ->get()->keyBy('entity_id');
    }

    /**
     * السامري + الفلتر + الرسم.
     *
     * ⚠️ **السامري من الصفوف كلها قبل الفلتر** — الأرقام فوق بتقول
     * وضع الملف كله، والفلتر بيقصّ اللي تحت بس. لو الاتنين اتفلتروا
     * الشاشة بتقول «١٢ مالهمش حساب» وانت شايف ١٢ صف، ومحدش يعرف
     * إن ده الفلتر مش الحقيقة.
     */
    /**
     * السامري — بيجاوب على أسئلة المالك بالحرف:
     *
     *   «معايا كام عميل؟ · كام واحد حسابه مظبوط؟ · كام واحد حسابه
     *    مش موجود؟ · اللي حسابه موجود ليه كشف تفصيلي ولا لأ؟ · واللي
     *    ليه كشف معاه هارد كوبي كمان ولا كشف وخلاص؟ · واتعملّه فاتورة
     *    ضريبية ولا لأ؟»
     *
     * كل رقم هنا هو إجابة سؤال من دول — والوصف تحت المربع في الشاشة
     * بيقول السؤال نفسه، عشان الرقم مايتقريش غلط.
     *
     * ⚠️ **الأرصدة مش في السامري** (قرار المالك ٢٨/٨): «رصيد السيستم
     * ريفرنس بس، مش يزوّد حاجة ولا يفرّق حاجة». فمفيش مجاميع ولا
     * فروق — الصفحة بتعدّ حالات مش بتحسب فلوس.
     */
    public static function summarize($rows): array
    {
        $st = fn ($r) => $r['audit']?->state() ?? 'pending';
        $cnt = fn (callable $f) => $rows->filter($f)->count();

        return [
            'total' => $rows->count(),
            'pending' => $cnt(fn ($r) => $st($r) === 'pending'),
            'reviewed' => $cnt(fn ($r) => $st($r) !== 'pending'),

            // ١) حسابه موجود ولا لأ
            'has_account' => $cnt(fn ($r) => $r['audit']?->has_account === true),
            'no_account' => $cnt(fn ($r) => $r['audit']?->has_account === false),

            // ٢) اللي له حساب — معاه كشف ولا لأ
            'has_statement' => $cnt(fn ($r) => $r['audit']?->has_statement === true),
            'no_statement' => $cnt(fn ($r) => $st($r) === 'no_statement'),
            'files' => $cnt(fn ($r) => $r['audit']?->statement_path !== null),

            // ٣) اللي معاه كشف — معاه إذن استلام ولا لأ
            'has_receipt' => $cnt(fn ($r) => $r['audit']?->has_receipt === true),
            'no_receipt' => $cnt(fn ($r) => $st($r) === 'no_receipt'),

            // ٤) المظبوط تماماً: حساب + كشف + إذن
            'full' => $cnt(fn ($r) => $st($r) === 'full'),

            // ٥) الفاتورة الضريبية — محور مستقل
            'billed' => $cnt(fn ($r) => $r['audit']?->tax_invoice === true),
            'unbilled' => $cnt(fn ($r) => $r['audit']?->tax_invoice === false),
            'billing_pending' => $cnt(fn ($r) => $r['audit']?->tax_invoice === null),
            // الجاهز للفوترة: مظبوط تماماً ولسه متعملّهوش فاتورة
            'ready_to_bill' => $cnt(fn ($r) => $st($r) === 'full' && $r['audit']?->tax_invoice !== true),

            // ٦) تأكيد مدير القناة من عند العميل
            'confirmed' => $cnt(fn ($r) => $r['audit']?->confirmed_at !== null),
        ];
    }

    private function render(Request $request, string $mode, $rows)
    {
        $state = fn ($r) => $r['audit']?->state() ?? 'pending';
        $summary = self::summarize($rows);

        $show = $request->string('show')->value() ?: 'all';

        $filtered = match ($show) {
            'pending' => $rows->filter(fn ($r) => $state($r) === 'pending'),
            'no_account' => $rows->filter(fn ($r) => $state($r) === 'no_account'),
            'no_statement' => $rows->filter(fn ($r) => $state($r) === 'no_statement'),
            'no_receipt' => $rows->filter(fn ($r) => $state($r) === 'no_receipt'),
            'full' => $rows->filter(fn ($r) => $state($r) === 'full'),
            'ready_to_bill' => $rows->filter(fn ($r) => $state($r) === 'full'
                && $r['audit']?->tax_invoice !== true),
            'unbilled' => $rows->filter(fn ($r) => $r['audit']?->tax_invoice === false),
            default => $rows,
        };

        if ($s = trim((string) $request->string('q')->value())) {
            $filtered = $filtered->filter(fn ($r) => mb_stripos($r['title'], $s) !== false
                || mb_stripos((string) $r['sub'], $s) !== false);
        }

        return view('erp.account_audit', [
            'mode' => $mode,
            'rows' => $filtered->values(),
            'summary' => $summary,
            'show' => $show,
            'q' => $s ?? '',
        ]);
    }

    /**
     * حفظ — الكل أو صف واحد (`only`).
     *
     * ⚠️ نفس نمط شاشات الإعداد: فورم واحد وزرار لكل صف بيبعت `only`.
     * الفرق إن الفورم هنا **multipart** — كشف الحساب بيترفع مع الصف.
     */
    public function save(Request $request, string $mode)
    {
        abort_unless(in_array($mode, ['chains', 'clients'], true), 404);

        $type = $mode === 'chains' ? 'group' : 'client';

        $data = $request->validate([
            'rows' => ['required', 'array'],
            'rows.*.has_account' => ['nullable', 'in:1,0'],
            'rows.*.their_balance' => ['nullable', 'numeric', 'between:-99999999,99999999'],
            'rows.*.has_statement' => ['nullable', 'in:1,0'],
            'rows.*.has_receipt' => ['nullable', 'in:1,0'],
            'rows.*.tax_invoice' => ['nullable', 'in:1,0'],
            // ⚠️ **مصفوفة مش سترينج بالبايبات** — درس ٢٦/٨ (الشرطة
            // جوه مصفوفة قواعد بتتقري كقاعدة واحدة وترمي استثناء)
            'files' => ['nullable', 'array'],
            'files.*' => ['nullable', 'file', 'max:10240',
                'mimes:xlsx,xls,csv,pdf,jpg,jpeg,png,webp'],
        ]);

        $only = $request->integer('only');
        $saved = 0;

        DB::transaction(function () use ($data, $request, $type, $only, &$saved) {
            foreach ($data['rows'] as $id => $row) {
                $id = (int) $id;

                if ($only && $id !== $only) {
                    continue;
                }

                // الكيان لازم يكون موجود وفي نطاق رؤيته
                if (! $this->entityVisible($type, $id, $request)) {
                    continue;
                }

                $audit = AccountAudit::firstOrNew([
                    'entity_type' => $type,
                    'entity_id' => $id,
                ]);

                // الفاضي = «لسه ماتحددش» مش false — الفرق بين
                // «قال لأ» و«ماردش» هو نص فايدة الشاشة
                $audit->has_account = ($row['has_account'] ?? '') === ''
                    ? null : (bool) (int) $row['has_account'];
                $audit->has_statement = ($row['has_statement'] ?? '') === ''
                    ? null : (bool) (int) $row['has_statement'];
                $audit->has_receipt = ($row['has_receipt'] ?? '') === ''
                    ? null : (bool) (int) $row['has_receipt'];
                $audit->tax_invoice = ($row['tax_invoice'] ?? '') === ''
                    ? null : (bool) (int) $row['tax_invoice'];

                // ⚠️ الرصيد بيتمسح لو الحساب مش موجود — رقم متعلّق
                // بحساب مش موجود بيفضل يلخبط السامري بعد كده
                $audit->their_balance = $audit->has_account === false
                    ? null
                    : (($row['their_balance'] ?? '') === '' ? null : (float) $row['their_balance']);

                // ⚠️ **السلسلة بتقف عند أول «لا»** — «مالوش حساب»
                // معناها مفيش كشف ولا إذن، وإجابة قديمة فاضلة كانت
                // هتخلي السامري يقول «معاه كشف» لعميل مالوش حساب
                if ($audit->has_account === false) {
                    $audit->has_statement = null;
                    $audit->has_receipt = null;
                }

                if ($audit->has_statement !== true) {
                    $audit->has_receipt = null;
                }

                $file = $request->file("files.$id");

                if ($file !== null) {
                    // الملف القديم بيتشال — نسخة واحدة لكل كيان
                    if ($audit->statement_path) {
                        Storage::disk('public')->delete($audit->statement_path);
                    }

                    $audit->statement_path = $file->store('statements/'.$type, 'public');
                    $audit->statement_name = $file->getClientOriginalName();
                    // رفع كشف = الكشف موجود، مهما كان الراديو
                    $audit->has_statement = true;
                }

                $audit->reviewed_by = $request->user()->id;
                $audit->reviewed_at = now();
                $audit->save();

                $saved++;
            }
        });

        return back()->with('ok', __('audit.saved', ['n' => $saved]));
    }

    /**
     * ═══ تقرير السامري (٢٨/٨) — مربعات بس ═══
     *
     * «تقرير فيه مربعات كلها سامريهات بالإجابات على الأسئلة دي».
     * السلاسل والعملاء في صفحة واحدة، كل قسم بأرقامه، وتحت كل رقم
     * السؤال اللي بيجاوب عليه.
     */
    public function report(Request $request)
    {
        $groups = ClientGroup::withCount('clients')->where('active', true)->get();
        $gAudits = $this->auditsFor('group', $groups->pluck('id'));

        $chainRows = $groups->map(fn ($g) => [
            'id' => $g->id,
            'audit' => $gAudits[$g->id] ?? null,
        ]);

        $clients = Client::visibleTo(Client::query(), $request->user())
            ->whereNull('group_id')->where('status', '!=', 'rejected')
            ->get(['id']);
        $cAudits = $this->auditsFor('client', $clients->pluck('id'));

        $clientRows = $clients->map(fn ($c) => [
            'id' => $c->id,
            'audit' => $cAudits[$c->id] ?? null,
        ]);

        return view('erp.account_audit_report', [
            'chains' => self::summarize($chainRows),
            'clients' => self::summarize($clientRows),
        ]);
    }

    /**
     * «تم التأكيد إن الحساب مظبوط» — مدير القناة وهو عند العميل.
     *
     * ⚠️ ختم بشري مش حساب: مفيش رقم بيتغير، بس بنعرف إن حد راح
     * للعميل وشاف الورق بعينه. الضغطة التانية بتشيل التأكيد (تراجُع).
     */
    public function confirm(Request $request, AccountAudit $audit)
    {
        if (! $this->entityVisible($audit->entity_type, (int) $audit->entity_id, $request)) {
            abort(403);
        }

        $on = $audit->confirmed_at === null;

        $audit->update([
            'confirmed_at' => $on ? now() : null,
            'confirmed_by' => $on ? $request->user()->id : null,
        ]);

        return back()->with('ok', __($on ? 'audit.confirmed_ok' : 'audit.unconfirmed_ok'));
    }

    /** مسح كشف مرفوع */
    public function deleteStatement(Request $request, AccountAudit $audit)
    {
        if (! $this->entityVisible($audit->entity_type, (int) $audit->entity_id, $request)) {
            abort(403);
        }

        if ($audit->statement_path) {
            Storage::disk('public')->delete($audit->statement_path);
        }

        $audit->update([
            'statement_path' => null,
            'statement_name' => null,
            'has_statement' => false,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return back()->with('ok', __('audit.statement_removed'));
    }

    /**
     * الكيان موجود وفي نطاق رؤية اليوزر؟
     *
     * ⚠️ مدير القناة بيراجع عملاءه بس — `visibleTo` هي نفس الحارس
     * المستخدم في كل شاشات العملاء (دوكترين السكوب).
     */
    private function entityVisible(string $type, int $id, Request $request): bool
    {
        if ($type === 'client') {
            return Client::visibleTo(Client::query(), $request->user())
                ->whereKey($id)->exists();
        }

        return ClientGroup::whereKey($id)->exists();
    }
}
