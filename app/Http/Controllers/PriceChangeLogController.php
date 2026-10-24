<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\PriceChangeDoc;
use App\Models\PriceChangeRun;
use App\Models\PriceList;
use App\Support\Csv;
use App\Support\DateRange;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * سجل تعديلات الأسعار (Lane 13) — قراءة للأدمن والمحاسب.
 *
 * القايمة بفلتر فترة على تاريخ إنشاء التعديل + مصدر/حالة/قايمة/سلسلة/
 * عميل/رقم فاتورة. الكروت والتصدير على نفس الكويري المفلترة.
 * صفحة التعديل وصفحة المستند للقراءة بس — مفيش كتابة هنا خالص.
 */
class PriceChangeLogController extends Controller
{
    /** أقصى عدد مستندات بنعرضها في صفحة التعديل لكل جدول */
    private const DOC_CAP = 500;

    private const TRIGGERS = ['list', 'chain', 'branch', 'client', 'invoice'];

    private const STATUSES = ['draft', 'applied', 'master_only', 'cancelled'];

    public function index(Request $request)
    {
        $range = DateRange::fromRequest($request);
        $filters = [
            'trigger' => $this->pick($request->query('trigger'), self::TRIGGERS),
            'status' => $this->pick($request->query('status'), self::STATUSES),
            'price_list_id' => $this->intOrNull($request->query('price_list_id')),
            'group_id' => $this->intOrNull($request->query('group_id')),
            'client_id' => $this->intOrNull($request->query('client_id')),
            'invoice' => is_string($request->query('invoice')) ? trim($request->query('invoice')) : '',
        ];

        if ($request->boolean('export')) {
            return $this->export($this->filtered($range, $filters), $range);
        }

        $kpi = $this->filtered($range, $filters)->selectRaw(
            'COUNT(*) as runs, COALESCE(SUM(invoices_changed),0) as invs, COALESCE(SUM(pos_changed),0) as pos, COALESCE(SUM(ledger_diff),0) as ledger'
        )->first();

        $runs = $this->filtered($range, $filters)
            ->with(['user', 'priceList', 'group', 'client', 'invoice'])
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return view('erp.price_changes', [
            'runs' => $runs,
            'range' => $range,
            'filters' => $filters,
            'kpi' => $kpi,
            'triggers' => self::TRIGGERS,
            'statuses' => self::STATUSES,
            'lists' => PriceList::orderBy('name')->get(),
            'groups' => ClientGroup::orderBy('name')->get(),
            'clientFilter' => $filters['client_id'] ? Client::find($filters['client_id']) : null,
            'targets' => $runs->getCollection()->mapWithKeys(fn ($r) => [$r->id => $this->target($r)])->all(),
        ]);
    }

    public function show(PriceChangeRun $run)
    {
        $run->load(['user', 'priceList', 'group', 'client', 'invoice']);

        $changedTotal = $run->docs()->where('outcome', 'changed')->count();
        $skippedTotal = $run->docs()->where('outcome', 'skipped')->count();
        $changedRows = $run->docs()->where('outcome', 'changed')->orderBy('id')->limit(self::DOC_CAP)->get();
        $skippedRows = $run->docs()->where('outcome', 'skipped')->orderBy('id')->limit(self::DOC_CAP)->get();

        $clientIds = $changedRows->pluck('client_id')->merge($skippedRows->pluck('client_id'))->filter()->unique();
        $names = Client::whereIn('id', $clientIds)->get()->mapWithKeys(fn ($c) => [$c->id => $c->displayName()])->all();

        $zero = ['before_net' => 0, 'before_tax' => 0, 'before_grand' => 0, 'after_net' => 0, 'after_tax' => 0, 'after_grand' => 0, 'diff_net' => 0, 'diff_tax' => 0, 'diff_grand' => 0];
        $totals = $run->summary['totals'] ?? [];

        return view('erp.price_change_run', [
            'run' => $run,
            'target' => $this->target($run),
            'totals' => [
                'invoice' => $totals['invoice'] ?? $zero,
                'po' => $totals['po'] ?? $zero,
                'all' => $totals['all'] ?? $zero,
            ],
            'items' => $run->item_changes ?? ($run->summary['item_changes'] ?? []),
            'changed' => $changedRows,
            'skipped' => $skippedRows,
            'changedTotal' => $changedTotal,
            'skippedTotal' => $skippedTotal,
            'cap' => self::DOC_CAP,
            'names' => $names,
        ]);
    }

    public function doc(PriceChangeRun $run, PriceChangeDoc $doc)
    {
        abort_unless((int) $doc->run_id === (int) $run->id, 404);

        $original = $doc->document();

        return view('erp.price_change_doc', [
            'run' => $run,
            'doc' => $doc,
            'clientName' => $doc->client?->displayName(),
            'docUrl' => $original === null ? null : ($doc->doc_type === 'invoice'
                ? route('ops.invoice', $doc->doc_id)
                : route('ops.pos.show', $doc->doc_id)),
        ]);
    }

    // ═══════════════ داخلي ═══════════════

    private function filtered(DateRange $range, array $f): Builder
    {
        $q = PriceChangeRun::query();
        $range->apply($q, 'created_at');

        return $q
            ->when($f['trigger'], fn ($q, $v) => $q->where('trigger', $v))
            ->when($f['status'], fn ($q, $v) => $q->where('status', $v))
            ->when($f['price_list_id'], fn ($q, $v) => $q->where('price_list_id', $v))
            ->when($f['group_id'], fn ($q, $v) => $q->where('group_id', $v))
            ->when($f['client_id'], fn ($q, $v) => $q->where('client_id', $v))
            ->when($f['invoice'] !== '', fn ($q) => $q->whereHas('docs', fn ($d) => $d->where('number', $f['invoice'])));
    }

    /** اسم الهدف (قايمة/سلسلة/عميل/فاتورة) حسب مصدر التعديل */
    private function target(PriceChangeRun $r): string
    {
        return match ($r->trigger) {
            'list' => $r->priceList?->displayName(),
            'chain' => $r->group?->displayName(),
            'branch', 'client' => $r->client?->displayName(),
            'invoice' => $r->invoice?->number,
            default => null,
        } ?? '—';
    }

    private function export(Builder $q, DateRange $range)
    {
        $rows = (function () use ($q) {
            foreach ($q->with(['user', 'priceList', 'group', 'client', 'invoice'])->orderByDesc('id')->lazyById(500) as $r) {
                yield [
                    $r->number,
                    $r->created_at?->format('Y-m-d H:i'),
                    $r->user?->displayName() ?? '',
                    $r->triggerLabel(),
                    $this->target($r),
                    $r->statusLabel(),
                    (int) $r->invoices_changed,
                    (int) $r->pos_changed,
                    (int) $r->skipped_count,
                    number_format((float) $r->before_grand, 2, '.', ''),
                    number_format((float) $r->after_grand, 2, '.', ''),
                    number_format((float) $r->after_grand - (float) $r->before_grand, 2, '.', ''),
                    number_format((float) $r->ledger_diff, 2, '.', ''),
                ];
            }
        })();

        return Csv::download('price-changes-'.now()->format('Y-m-d-Hi').'.csv', [
            __('reprice.col_run'), __('reprice.col_date'), __('reprice.col_user'), __('reprice.col_trigger'),
            __('reprice.col_target'), __('reprice.col_status'), __('reprice.k_invoices'), __('reprice.k_pos'),
            __('reprice.k_skipped'), __('reprice.log_before'), __('reprice.log_after'), __('reprice.diff'), __('reprice.k_ledger'),
        ], $rows, null, Csv::meta(__('reprice.log_title'), $range->from?->toDateString(), $range->to?->toDateString()));
    }

    private function pick(mixed $v, array $allowed): ?string
    {
        return is_string($v) && in_array($v, $allowed, true) ? $v : null;
    }

    private function intOrNull(mixed $v): ?int
    {
        return is_string($v) && ctype_digit($v) && (int) $v > 0 ? (int) $v : null;
    }
}
