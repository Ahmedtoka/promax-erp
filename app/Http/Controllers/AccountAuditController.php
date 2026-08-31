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
    /** صفحة السلاسل */
    public function chains(Request $request)
    {
        $groups = ClientGroup::withCount('clients')
            ->where('active', true)->orderBy('name')->get();

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
            'ours' => (float) ($ours[$g->id] ?? 0),
            'audit' => $audits[$g->id] ?? null,
        ]);

        return $this->render($request, 'chains', $rows);
    }

    /** صفحة العملاء الفرادى — **بدون** أي عميل في سلسلة */
    public function clients(Request $request)
    {
        $clients = Client::visibleTo(Client::query(), $request->user())
            ->with('zone')
            ->whereNull('group_id')
            ->where('status', '!=', 'rejected')
            ->orderBy('name')->get();

        $audits = $this->auditsFor('client', $clients->pluck('id'));

        $rows = $clients->map(fn ($c) => [
            'id' => $c->id,
            'title' => $c->displayName(),
            'sub' => trim(($c->code ?: '').' · '.($c->zone?->displayName() ?? '—'), ' ·'),
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
    private function render(Request $request, string $mode, $rows)
    {
        $state = fn ($r) => $r['audit']?->state() ?? 'pending';

        $summary = [
            'total' => $rows->count(),
            'pending' => $rows->filter(fn ($r) => $state($r) === 'pending')->count(),
            'has_account' => $rows->filter(fn ($r) => $r['audit']?->has_account === true)->count(),
            'no_account' => $rows->filter(fn ($r) => $r['audit']?->has_account === false)->count(),
            'has_statement' => $rows->filter(fn ($r) => $r['audit']?->has_statement === true)->count(),
            'no_statement' => $rows->filter(fn ($r) => $state($r) === 'no_statement')->count(),
            'files' => $rows->filter(fn ($r) => $r['audit']?->statement_path)->count(),
            // إجمالي الفرق بين رصيدنا ورصيدهم — لللي اتراجعوا بس
            'gap' => round($rows->sum(function ($r) {
                $a = $r['audit'];

                return $a?->their_balance === null ? 0 : (float) $a->their_balance - $r['ours'];
            }), 2),
            'ours' => round($rows->sum('ours'), 2),
        ];

        $show = $request->string('show')->value() ?: 'all';

        $filtered = match ($show) {
            'pending' => $rows->filter(fn ($r) => $state($r) === 'pending'),
            'no_account' => $rows->filter(fn ($r) => $state($r) === 'no_account'),
            'no_statement' => $rows->filter(fn ($r) => $state($r) === 'no_statement'),
            'done' => $rows->filter(fn ($r) => $state($r) === 'done'),
            // فرق بين رصيدنا ورصيدهم — أهم فلتر في الشاشة
            'gap' => $rows->filter(function ($r) {
                $a = $r['audit'];

                return $a?->their_balance !== null
                    && abs((float) $a->their_balance - $r['ours']) >= 0.01;
            }),
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
            'rows.*.note' => ['nullable', 'string', 'max:300'],
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

                // ⚠️ الرصيد بيتمسح لو الحساب مش موجود — رقم متعلّق
                // بحساب مش موجود بيفضل يلخبط السامري بعد كده
                $audit->their_balance = $audit->has_account === false
                    ? null
                    : (($row['their_balance'] ?? '') === '' ? null : (float) $row['their_balance']);

                $audit->note = trim((string) ($row['note'] ?? '')) ?: null;

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
