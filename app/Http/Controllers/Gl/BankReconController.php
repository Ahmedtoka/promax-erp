<?php

namespace App\Http\Controllers\Gl;

use App\Http\Controllers\Controller;
use App\Models\BankStatement;
use App\Models\BankStatementMark;
use App\Models\Safe;
use App\Services\Cash\BankRecon;
use App\Support\Csv;
use App\Support\DateRange;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * تسوية البنك (Lane 4، L4-D6): كشف حساب بنك برصيد أول وآخر، والمحاسب
 * بيعلّم على سطور حساب البنك في الدفتر اللي ظهرت في الكشف. الفرق =
 * رصيد آخر الكشف − (رصيد أوله + المتعلّم عليه) — صفر = الكشف يتقفل.
 * ⚠️ مفيش كتابة في `gl_*` هنا خالص — العلامات بس.
 */
class BankReconController extends Controller
{
    public function __construct(private BankRecon $recon) {}

    public function index(Request $request)
    {
        $range = DateRange::fromRequest($request, 'month');
        $q = BankStatement::with('safe')->withSum('marks', 'amount')
            ->tap(fn ($q) => $range->apply($q, 'date'))
            ->when($request->filled('safe_id'), fn ($q) => $q->where('safe_id', (int) $request->query('safe_id')))
            ->when(in_array($request->query('status'), BankStatement::STATUSES, true), fn ($q) => $q->where('status', $request->query('status')))
            ->orderByDesc('date')->orderByDesc('id');

        // المسوّى والفرق من مجموع العلامات (withSum) — من غير استعلام لكل صف
        $calc = function (BankStatement $s) {
            $s->cleared = round((float) $s->opening_balance + (float) $s->marks_sum_amount, 2);
            $s->difference = round((float) $s->closing_balance - $s->cleared, 2);

            return $s;
        };

        if ($request->boolean('export')) {
            $all = (clone $q)->limit(5000)->get()->map($calc);

            return Csv::download('bank-recon-'.now()->format('Y-m-d-Hi').'.csv',
                [__('cash.recon_number'), __('cash.bank'), __('common.date'), __('cash.recon_period'), __('cash.recon_opening'),
                    __('cash.recon_closing'), __('cash.recon_cleared'), __('cash.difference'), __('cash.count_status')],
                $all->map(fn (BankStatement $s) => [
                    $s->number, $s->safe?->displayName() ?? '', $s->date?->format('Y-m-d'),
                    $s->date_from?->format('Y-m-d').' → '.$s->date_to?->format('Y-m-d'),
                    Csv::money($s->opening_balance), Csv::money($s->closing_balance), Csv::money($s->cleared),
                    Csv::money($s->difference), __('cash.recon_status_'.$s->status),
                ]),
                null,
                Csv::meta(__('cash.recon'), $range->fromValue() ?: null, $range->toValue() ?: null));
        }

        $rows = (clone $q)->paginate(50)->withQueryString();
        $rows->getCollection()->transform($calc);

        return view('gl.recon', [
            'range' => $range,
            'rows' => $rows,
            'kpis' => [
                'open' => (clone $q)->where('status', 'open')->count(),
                'closed' => (clone $q)->where('status', 'closed')->count(),
            ],
            'banks' => Safe::where('kind', 'bank')->orderBy('code')->get(['id', 'code', 'name', 'name_en', 'active']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'safe_id' => ['required', 'integer', Rule::exists('safes', 'id')->where('active', 1)->where('kind', 'bank')],
            'date' => ['required', 'date'],
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'opening_balance' => ['required', 'numeric', 'min:-999999999', 'max:999999999'],
            'closing_balance' => ['required', 'numeric', 'min:-999999999', 'max:999999999'],
            'note' => ['nullable', 'string', 'max:250'],
        ], [
            'safe_id.exists' => __('cash.recon_bank_only'),
        ]);

        $s = DB::transaction(fn () => BankStatement::create([
            'number' => BankStatement::nextNumber(),
            'safe_id' => (int) $data['safe_id'],
            'date' => $data['date'],
            'date_from' => $data['date_from'],
            'date_to' => $data['date_to'],
            'opening_balance' => round((float) $data['opening_balance'], 2),
            'closing_balance' => round((float) $data['closing_balance'], 2),
            'note' => $data['note'] ?? null,
            'status' => 'open',
            'created_by' => $request->user()->id,
        ]));

        return redirect()->route('gl.recon.show', $s)->with('ok', __('cash.recon_saved'));
    }

    public function show(Request $request, BankStatement $statement)
    {
        $statement->load(['safe', 'closer', 'creator']);
        $lines = $this->visibleLines($statement);
        $summary = $this->recon->summary($statement);

        if ($request->boolean('export')) {
            return Csv::download($statement->number.'-'.now()->format('Y-m-d-Hi').'.csv',
                [__('common.date'), __('cash.recon_entry'), __('cash.recon_memo'), __('cash.recon_debit'), __('cash.recon_credit'),
                    __('cash.recon_amount'), __('cash.recon_marked')],
                $lines->map(fn ($l) => [
                    $l->entry_date->format('Y-m-d'), $l->entry_number, (string) ($l->memo ?: $l->entry_memo),
                    Csv::money($l->debit), Csv::money($l->credit), Csv::money($l->amount),
                    $l->statement_id === $statement->id ? __('cash.yes') : ($l->statement_id ? __('cash.recon_other') : __('cash.no')),
                ]),
                ['', '', __('cash.recon_cleared'), '', '', Csv::money($summary['cleared']), ''],
                Csv::meta(__('cash.recon').' '.$statement->number, $statement->date_from?->format('Y-m-d'), $statement->date_to?->format('Y-m-d')));
        }

        $others = $lines->pluck('statement_id')->filter(fn ($id) => $id && $id !== $statement->id)->unique();

        return view('gl.recon_show', [
            's' => $statement,
            'lines' => $lines,
            'summary' => $summary,
            'otherNumbers' => $others->isEmpty() ? collect() : BankStatement::whereIn('id', $others->all())->pluck('number', 'id'),
        ]);
    }

    public function mark(Request $request, BankStatement $statement)
    {
        $data = $request->validate([
            'line_keys' => ['nullable', 'array'],
            'line_keys.*' => ['string', 'max:80'],
        ]);
        if ($statement->status === 'closed') {
            return back()->withErrors(['line_keys' => __('cash.recon_closed')]);
        }

        $wanted = collect($data['line_keys'] ?? [])->unique()->values();
        $userId = $request->user()->id;

        try {
            DB::transaction(function () use ($statement, $wanted, $userId) {
                // قفل صف الكشف — علامتين في نفس اللحظة أو قفل في النص
                $s = BankStatement::whereKey($statement->id)->lockForUpdate()->firstOrFail();
                if ($s->status === 'closed') {
                    throw ValidationException::withMessages(['line_keys' => __('cash.recon_closed')]);
                }
                $lines = $this->visibleLines($s)->keyBy('line_key');

                $unknown = $wanted->reject(fn ($k) => $lines->has($k));
                if ($unknown->isNotEmpty()) {
                    throw ValidationException::withMessages(['line_keys' => __('cash.recon_unknown_line')]);
                }
                $taken = $wanted->filter(fn ($k) => $lines[$k]->statement_id && $lines[$k]->statement_id !== $s->id);
                if ($taken->isNotEmpty()) {
                    $l = $lines[$taken->first()];
                    throw ValidationException::withMessages(['line_keys' => __('cash.recon_marked_elsewhere', [
                        'line' => $l->entry_number, 'statement' => BankStatement::whereKey($l->statement_id)->value('number'),
                    ])]);
                }

                BankStatementMark::where('statement_id', $s->id)->whereNotIn('line_key', $wanted->all())->delete();
                $have = BankStatementMark::where('statement_id', $s->id)->pluck('line_key')->flip();
                foreach ($wanted as $k) {
                    if ($have->has($k)) {
                        continue;
                    }
                    $l = $lines[$k];
                    BankStatementMark::create([
                        'statement_id' => $s->id,
                        'line_key' => $k,
                        'gl_line_id' => $l->id,
                        'amount' => $l->amount,
                        'line_date' => $l->entry_date->toDateString(),
                        'marked_by' => $userId,
                        'marked_at' => now(),
                    ]);
                }
            });
        } catch (UniqueConstraintViolationException) {
            // سباق مع كشف تاني علّم على نفس السطر — الـunique index هو الحَكَم
            return back()->withErrors(['line_keys' => __('cash.recon_marked_elsewhere_short')]);
        }

        return redirect()->route('gl.recon.show', $statement)->with('ok', __('cash.recon_marks_saved'));
    }

    public function close(Request $request, BankStatement $statement)
    {
        $closed = DB::transaction(function () use ($statement, $request) {
            $s = BankStatement::whereKey($statement->id)->lockForUpdate()->firstOrFail();
            if ($s->status === 'closed') {
                return true;
            }
            if ($this->recon->summary($s)['difference'] != 0.0) {
                return false;
            }
            $s->update(['status' => 'closed', 'closed_at' => now(), 'closed_by' => $request->user()->id]);

            return true;
        });
        if (! $closed) {
            return back()->withErrors(['close' => __('cash.recon_not_balanced')]);
        }

        return redirect()->route('gl.recon.show', $statement)->with('ok', __('cash.recon_closed_ok'));
    }

    /**
     * سطور الكشف: سطور البنك في فترته + أي سطر قبلها **مش متعلّم عليه**
     * (بنود معلّقة بتترحّل للكشف اللي بعده) أو متعلّم عليه في الكشف ده.
     */
    private function visibleLines(BankStatement $s): Collection
    {
        $bank = $s->relationLoaded('safe') ? $s->safe : $s->safe()->first();
        if (! $bank) {
            return collect();
        }
        $earlier = $this->recon->lines($bank, null, $s->date_from->copy()->subDay())
            ->filter(fn ($l) => $l->statement_id === null || $l->statement_id === $s->id);

        return $earlier->concat($this->recon->lines($bank, $s->date_from, $s->date_to))->values();
    }
}
