<?php

namespace App\Http\Controllers\Gl;

use App\Http\Controllers\Controller;
use App\Models\Gl\GlAccount;
use App\Models\Gl\GlEntry;
use App\Models\Gl\GlLine;
use App\Services\Gl\ClosedPeriod;
use App\Services\Gl\Ledger;
use App\Services\Gl\UnbalancedEntry;
use App\Support\DateRange;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * ═══════════════════════════════════════════════════════════════
 * اليومية — كل قيد بسطوره ومصدره
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **القيد الآلي مابيتعدلش هنا.** اللي بيتعدّل هو **حساب السطر** بس
 * (زرار ✎) — بسجل تدقيق كامل في `gl_line_overrides`، والمبلغ والمصدر
 * والتاريخ بيفضلوا زي ما هم. تعديل المبلغ بيبقى من المستند نفسه
 * (فاتورة/تحصيل)، عشان الشجرة تفضل مطابقة لكشف حساب العميل.
 */
class EntryController extends Controller
{
    public function __construct(private Ledger $ledger)
    {
    }

    /** المستند اللي القيد اتولد منه ⇐ [اسم الراوت, البارامتر] */
    private const SOURCE_LINKS = [
        \App\Models\Transaction::class => ['erp.clients.show', 'client_id'],
        \App\Models\RepSettlement::class => ['erp.repclose.show', 'user_id'],
        \App\Models\SupplierTransaction::class => ['erp.suppliers.show', 'supplier_id'],
        \App\Models\Expense::class => ['gl.expenses', null],
        \App\Models\CashMovement::class => ['gl.cash', null],
    ];

    public function index(Request $request)
    {
        $range = DateRange::fromRequest($request, 'month');

        $q = GlEntry::with(['lines.account', 'creator'])
            ->tap(fn ($q) => $range->apply($q, 'date'))
            ->when($request->filled('origin'), fn ($q) => $q->where('origin', $request->string('origin')->value()))
            ->when($request->filled('account'), fn ($q) => $q->whereHas('lines', fn ($l) => $l->where('account_id', $request->integer('account'))))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.$request->string('q')->value().'%';
                $q->where(fn ($w) => $w->where('number', 'like', $term)->orWhere('memo', 'like', $term));
            });

        $rows = (clone $q)->orderByDesc('date')->orderByDesc('id')->paginate(50)->withQueryString();

        return view('gl.entries', [
            'range' => $range,
            'rows' => $rows,
            'links' => $this->linksFor($rows->getCollection()),
            'accounts' => $this->postableAccounts(),
            'filterAccounts' => GlAccount::where('is_postable', true)->orderBy('code')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'memo' => ['required', 'string', 'max:250'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => ['required', 'exists:gl_accounts,id'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
        ]);

        $lines = array_map(fn ($l) => [
            'account_id' => (int) $l['account_id'],
            'debit' => round((float) ($l['debit'] ?? 0), 2),
            'credit' => round((float) ($l['credit'] ?? 0), 2),
        ], array_values($data['lines']));

        try {
            $this->ledger->manual(Carbon::parse($data['date']), $data['memo'], $lines, $request->user());
        } catch (UnbalancedEntry|ClosedPeriod|\InvalidArgumentException $e) {
            // ⚠️ التلاتة بيرجعوا على نفس الخانة (`lines`) عن قصد — الشاشة
            // بتعرضهم فوق جدول السطور، وده المكان اللي المحاسب بيبص فيه.
            // الرسالة نفسها هي اللي بتفرّق (مش متوازن · فترة مقفولة ·
            // حساب عملاء/موردين).
            return back()->withErrors(['lines' => $e->getMessage()])->withInput();
        }

        return redirect()->route('gl.entries')->with('ok', __('gl.manual_entry_saved'));
    }

    public function override(Request $request, GlLine $line)
    {
        $data = $request->validate([
            'account_id' => ['required', 'exists:gl_accounts,id'],
            'note' => ['nullable', 'string', 'max:250'],
        ]);

        try {
            $this->ledger->overrideAccount(
                $line->load('entry.lines'),
                GlAccount::findOrFail($data['account_id']),
                $request->user(),
                $data['note'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['account_id' => $e->getMessage()])->withInput();
        } catch (ClosedPeriod $e) {
            return back()->withErrors(['account_id' => $e->getMessage()])->withInput();
        }

        return back()->with('ok', __('gl.override_done'));
    }

    /**
     * حسابات القيد اليدوي: فرعية شغّالة **من غير** حسابات التحكم —
     * `Ledger::manual()` بترفضهم أصلاً، فعرضهم في القايمة كان معناه
     * إن المحاسب يملا القيد كله ويترمي برسالة في الآخر.
     */
    private function postableAccounts()
    {
        return GlAccount::where('is_postable', true)->where('active', true)
            ->where(fn ($q) => $q->whereNull('system_key')->orWhereNotIn('system_key', ['receivables', 'payables']))
            ->orderBy('code')->get();
    }

    /**
     * لينك المستند لكل قيد — بيتحسب في الكنترولر مش في الفيو.
     *
     * @return array<int, array{url: string, label: string}>
     */
    private function linksFor($entries): array
    {
        $out = [];
        $ids = [];

        foreach ($entries as $e) {
            if ($e->source_type === null || ! isset(self::SOURCE_LINKS[$e->source_type])) {
                continue;
            }
            $ids[$e->source_type][] = $e->source_id;
        }

        // المفتاح الأجنبي للمستند (عميل/مندوب/مورد) — استعلام واحد لكل نوع
        $keys = [];
        foreach ($ids as $class => $sourceIds) {
            [, $column] = self::SOURCE_LINKS[$class];
            if ($column === null) {
                continue;
            }
            $keys[$class] = $class::whereIn('id', array_unique($sourceIds))->pluck($column, 'id')->all();
        }

        foreach ($entries as $e) {
            if ($e->source_type === null || ! isset(self::SOURCE_LINKS[$e->source_type])) {
                continue;
            }
            [$route, $column] = self::SOURCE_LINKS[$e->source_type];
            $label = __('gl.source_'.class_basename($e->source_type));

            if ($column === null) {
                $out[$e->id] = ['url' => route($route), 'label' => $label];

                continue;
            }

            $key = $keys[$e->source_type][$e->source_id] ?? null;
            if ($key !== null) {
                $out[$e->id] = ['url' => route($route, $key), 'label' => $label];
            }
        }

        return $out;
    }
}
