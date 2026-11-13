<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\ClientReturn;
use App\Models\DocReviewEvent;
use App\Models\Invoice;
use App\Models\PurchaseOrder;
use App\Models\ReviewCheck;
use App\Models\Transaction;
use App\Services\Reprice\InvoiceGl;
use App\Services\Review\DocReview;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * ═══════════════════════════════════════════════════════════════
 * فحص المراجعة مستند مستند على الداتا الحقيقية (١٣/١١/٢٠٢٦ — طلب أحمد)
 * ═══════════════════════════════════════════════════════════════
 *
 * «عملت مراجعات كتير — بص عليها كلها واتأكد إننا ماشيين صح: مفيش حاجة
 * بتتكرر، مفيش حاجة اتسجلت غلط، اللي اتعدّل اتعدّل صح».
 *
 * **قراءة بس بالافتراضي** — بيطبع ملخص ويحفظ التقرير كامل JSON + CSV في
 * `storage/app/private/review-audit/` (قرص `local`). التصليح (`--fix`) للتكرار بس، وبرضه تجربة
 * من غير كتابة لحد ما تزوّد `--apply` (وبيحفظ لقطة قبل أي مسح):
 *
 *   php artisan review:audit                    ← التقرير
 *   php artisan review:audit --doc=INV-1017     ← + قيود مستند واحد بالتفصيل
 *   php artisan review:audit --fix              ← إيه اللي هيتصلّح (من غير كتابة)
 *   php artisan review:audit --fix --apply      ← صلّح فعلاً (بلقطة)
 *
 * التصليح بيعمل حاجتين بس، الاتنين محددين بالظبط:
 *   ١. قيد مكرر بالحرف (نفس المستند + النوع + المدين + الدائن) ← يتشال
 *      الزيادة ويفضل الأقدم.
 *   ٢. فاتورة آجل اتحولت لكاش من شاشة الفاتورة بعد ما اتحصّلت بتحصيل ميداني
 *      (رصيد العميل بقى دائن قد الفاتورة أو أكتر) ← ترجع آجل: قيد «تحصيل نقدي
 *      مع فاتورة» يتشال — نفس زرار «آجل» بالظبط (باج INV-1017 / ورقي 560).
 */
class ReviewAudit extends Command
{
    protected $signature = 'review:audit
        {--doc= : رقم مستند (INV-/PO-/RET-) — اطبع قيوده كلها}
        {--fix : اعرض تصليح التكرار (من غير كتابة)}
        {--apply : مع --fix: اكتب التصليح فعلاً (بلقطة قبلها)}';

    protected $description = 'Read-only audit of doc-by-doc review results (duplicates, ledger vs documents, balances); --fix --apply repairs duplicates only';

    private const EPS = 0.011;

    /** @var array<string, list<array<string, mixed>>> */
    private array $report = [];

    public function handle(): int
    {
        $this->report = [
            'dup_ledger' => $this->dupLedger(),
            'double_collected' => $this->doubleCollected(),
            'doc_vs_ledger' => $this->docVsLedger(),
            'totals_vs_lines' => $this->totalsVsLines(),
            'balance_vs_ledger' => $this->balanceVsLedger(),
            'dup_serials' => $this->dupSerials(),
            'changed_after_review' => $this->changedAfterReview(),
            'review_edits' => $this->reviewEdits(),
        ];

        $this->newLine();
        $this->info('فحص المراجعة مستند مستند — '.now()->toDateTimeString());
        $this->table(['الفحص', 'العدد'], collect($this->report)->map(fn ($rows, $k) => [$k, count($rows)])->values()->all());

        foreach (['dup_ledger', 'double_collected', 'doc_vs_ledger', 'balance_vs_ledger'] as $k) {
            if ($this->report[$k] !== []) {
                $this->warn("── {$k} (أول ٢٠)");
                $rows = array_slice($this->report[$k], 0, 20);
                $this->table(array_keys($rows[0]), array_map(fn ($r) => array_map(fn ($v) => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v, $r), $rows));
            }
        }

        if ($doc = $this->option('doc')) {
            $this->dumpDoc($doc);
        }

        $dir = 'review-audit';
        $stamp = now()->format('Ymd-His');
        Storage::disk('local')->put("{$dir}/audit-{$stamp}.json", json_encode(['at' => now()->toDateTimeString(), 'report' => $this->report], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        foreach ($this->report as $k => $rows) {
            if ($rows !== []) {
                Storage::disk('local')->put("{$dir}/audit-{$stamp}-{$k}.csv", $this->csv($rows));
            }
        }
        $this->info('اتحفظ التقرير: '.Storage::disk('local')->path("{$dir}/audit-{$stamp}.json").' (+ CSV لكل فحص فيه نتايج)');

        if ($this->option('fix')) {
            return $this->fix((bool) $this->option('apply'), $dir, $stamp);
        }

        return self::SUCCESS;
    }

    // ═══════════════════════ الفحوصات (قراءة بس) ═══════════════════════

    /** قيدين أو أكتر لنفس المستند ونفس النوع (بيع/تحصيل مربوط/مرتجع/رد) */
    private function dupLedger(): array
    {
        $out = [];
        $groups = DB::table('transactions')
            ->whereIn('source_type', [Invoice::class, PurchaseOrder::class, ClientReturn::class])
            ->whereIn('kind', ['sale', 'collection', 'return', 'refund', 'consignment'])
            ->groupBy('source_type', 'source_id', 'kind')->havingRaw('COUNT(*) > 1')
            ->selectRaw('source_type, source_id, kind, COUNT(*) AS n, GROUP_CONCAT(id ORDER BY id) AS ids, SUM(debit) AS debit, SUM(credit) AS credit')
            ->get();
        foreach ($groups as $g) {
            $rows = DB::table('transactions')->whereIn('id', explode(',', $g->ids))->orderBy('id')->get(['id', 'debit', 'credit', 'client_id']);
            $first = $rows->first();
            $exact = $rows->every(fn ($r) => abs($r->debit - $first->debit) < self::EPS && abs($r->credit - $first->credit) < self::EPS);
            $out[] = [
                'doc' => $this->docLabel($g->source_type, (int) $g->source_id), 'kind' => $g->kind, 'rows' => (int) $g->n,
                'ids' => $g->ids, 'debit' => round((float) $g->debit, 2), 'credit' => round((float) $g->credit, 2),
                'client_id' => (int) $first->client_id, 'exact_copy' => $exact ? 'yes' : 'no',
            ];
        }

        return $out;
    }

    /**
     * فواتير اتحولت كاش من زرار الفاتورة (سجل النشاط: payment credit → cash) —
     * ولو رصيد العميل دلوقتي دائن قد الفاتورة يبقى اتحصّلت مرتين على الأرجح.
     */
    private function doubleCollected(): array
    {
        $out = [];
        foreach ($this->toggledToCash() as $inv) {
            $coll = Transaction::where('source_type', Invoice::class)->where('source_id', $inv->id)->where('kind', 'collection')->get();
            $balance = $this->ledgerBalance($inv->client_id);
            $likely = $inv->payment === 'cash' && $coll->isNotEmpty() && $balance <= -((float) $inv->grand_total - 1.0);
            $out[] = [
                'doc' => $inv->number, 'paper_ref' => $inv->paper_ref, 'client' => $inv->client?->code.' '.$inv->client?->displayName(),
                'payment' => $inv->payment, 'grand' => (float) $inv->grand_total, 'linked_collection' => round((float) $coll->sum('credit'), 2),
                'other_collections_after_sale' => round((float) Transaction::where('client_id', $inv->client_id)->where('kind', 'collection')
                    ->where(fn ($q) => $q->where('source_type', '!=', Invoice::class)->orWhere('source_id', '!=', $inv->id))
                    ->where('date', '>=', $inv->created_at->toDateString())->sum('credit'), 2),
                'client_balance' => round($balance, 2), 'likely_double' => $likely ? 'YES' : 'no',
            ];
        }

        return $out;
    }

    /** المستند قدام قيوده: البيع = الإجمالي، الكاش له تحصيل بنفس المبلغ، المرتجع = الدائن (والرد) */
    private function docVsLedger(): array
    {
        $out = [];
        $tx = DB::table('transactions')->whereIn('source_type', [Invoice::class, PurchaseOrder::class, ClientReturn::class])
            ->groupBy('source_type', 'source_id', 'kind')
            ->selectRaw('source_type, source_id, kind, SUM(debit) AS d, SUM(credit) AS c')->get()
            ->groupBy(fn ($r) => $r->source_type.'#'.$r->source_id);
        $sum = fn ($key, $kind, $col) => (float) ($tx[$key] ?? collect())->where('kind', $kind)->sum($col === 'debit' ? 'd' : 'c');
        $has = fn ($key, $kind) => ($tx[$key] ?? collect())->where('kind', $kind)->isNotEmpty();

        foreach (Invoice::query()->get(['id', 'number', 'paper_ref', 'client_id', 'payment', 'grand_total']) as $i) {
            $k = Invoice::class.'#'.$i->id;
            if ($has($k, 'consignment')) {
                continue;
            }
            $sale = $sum($k, 'sale', 'debit');
            $coll = $sum($k, 'collection', 'credit');
            $issues = array_filter([
                abs($sale - (float) $i->grand_total) >= self::EPS ? 'sale '.round($sale, 2).' ≠ grand '.$i->grand_total : null,
                $i->payment === 'cash' && ! $has($k, 'collection') ? 'cash without linked collection' : null,
                $i->payment === 'cash' && $has($k, 'collection') && abs($coll - (float) $i->grand_total) >= self::EPS ? 'collection '.round($coll, 2).' ≠ grand' : null,
                $i->payment !== 'cash' && $has($k, 'collection') ? 'credit invoice has a linked cash collection '.round($coll, 2) : null,
            ]);
            if ($issues) {
                $out[] = ['doc' => $i->number, 'paper_ref' => $i->paper_ref, 'client_id' => $i->client_id, 'grand' => (float) $i->grand_total, 'issues' => implode(' · ', $issues)];
            }
        }

        foreach (PurchaseOrder::where('status', 'delivered')->with('items')->get() as $po) {
            $k = PurchaseOrder::class.'#'.$po->id;
            $sale = $sum($k, 'sale', 'debit');
            $full = $po->items->every(fn ($it) => $it->delivered_qty === null || (int) $it->delivered_qty === (int) $it->qty);
            $issues = array_filter([
                ! $has($k, 'sale') ? 'delivered PO without sale entry' : null,
                $has($k, 'sale') && $full && abs($sale - (float) $po->grand_total) >= self::EPS ? 'sale '.round($sale, 2).' ≠ grand '.$po->grand_total : null,
            ]);
            if ($issues) {
                $out[] = ['doc' => $po->number, 'paper_ref' => $po->paper_ref, 'client_id' => $po->client_id, 'grand' => (float) $po->grand_total, 'issues' => implode(' · ', $issues)];
            }
        }

        foreach (ClientReturn::query()->get() as $r) {
            $k = ClientReturn::class.'#'.$r->id;
            $credit = $sum($k, 'return', 'credit');
            $refund = (float) Transaction::whereKey($r->refund_transaction_id)->value('debit');
            $issues = array_filter([
                (float) $r->grand_total > 0 && abs($credit - (float) $r->grand_total) >= self::EPS ? 'credit '.round($credit, 2).' ≠ grand '.$r->grand_total : null,
                $r->policy === ClientReturn::POLICY_CASH && $r->refund_transaction_id && abs($refund - (float) $r->grand_total) >= self::EPS ? 'cash refund '.round($refund, 2).' ≠ grand' : null,
            ]);
            if ($issues) {
                $out[] = ['doc' => $r->number, 'paper_ref' => $r->paper_ref, 'client_id' => $r->client_id, 'grand' => (float) $r->grand_total, 'issues' => implode(' · ', $issues)];
            }
        }

        return $out;
    }

    /** إجمالي المستند = مجموع بنوده (الصافي والضريبة) */
    private function totalsVsLines(): array
    {
        $out = [];
        $check = function (string $label, string $table, string $items, string $fk, ?string $where = null) use (&$out) {
            $rows = DB::table($table.' AS d')
                ->leftJoinSub(DB::table($items)->groupBy($fk)->selectRaw("{$fk} AS id, SUM(total) AS net, SUM(tax) AS tax"), 'l', 'l.id', '=', 'd.id')
                ->when($where, fn ($q) => $q->whereRaw($where))
                ->whereRaw('(ABS(COALESCE(l.net, 0) - d.total) >= 0.011 OR ABS(COALESCE(l.tax, 0) - d.tax_total) >= 0.011)')
                ->get(['d.number', 'd.client_id', 'd.total', 'd.tax_total', 'l.net', 'l.tax']);
            foreach ($rows as $r) {
                $out[] = ['type' => $label, 'doc' => $r->number, 'client_id' => $r->client_id, 'doc_net' => (float) $r->total, 'lines_net' => round((float) $r->net, 2),
                    'doc_tax' => (float) $r->tax_total, 'lines_tax' => round((float) $r->tax, 2)];
            }
        };
        $check('invoice', 'invoices', 'invoice_items', 'invoice_id');
        $check('po', 'purchase_orders', 'purchase_order_items', 'purchase_order_id', "d.status != 'cancelled'");
        $check('return', 'returns', 'return_items', 'return_id');

        return $out;
    }

    /** رصيد العميل المتخزّن قدام مجموع دفتره */
    private function balanceVsLedger(): array
    {
        $ledger = DB::table('transactions')->groupBy('client_id')->selectRaw('client_id, SUM(debit) - SUM(credit) AS b')->pluck('b', 'client_id');
        $out = [];
        foreach (Client::query()->get(['id', 'code', 'name', 'name_en', 'balance']) as $c) {
            $b = (float) ($ledger[$c->id] ?? 0);
            if (abs($b - (float) $c->balance) >= self::EPS) {
                $out[] = ['client' => $c->code.' '.$c->displayName(), 'client_id' => $c->id, 'stored_balance' => (float) $c->balance, 'ledger_balance' => round($b, 2), 'diff' => round((float) $c->balance - $b, 2)];
            }
        }

        return $out;
    }

    private function dupSerials(): array
    {
        $out = [];
        foreach (DocReview::serialIndex() as $serial => $docs) {
            if (count($docs) > 1) {
                $out[] = ['serial' => $serial, 'docs' => implode(' · ', array_column($docs, 'number'))];
            }
        }

        return $out;
    }

    /** ✓ اتعلّم وبعدها إجمالي المستند اتغير — مش محسوب متراجع */
    private function changedAfterReview(): array
    {
        $out = [];
        foreach (ReviewCheck::with('user:id,name,name_en')->whereIn('entity_type', DocReviewEvent::TYPES)->where('item', DocReview::ITEM)->where('status', ReviewCheck::OK)->get() as $c) {
            $grand = match ($c->entity_type) {
                'invoice' => DB::table('invoices')->where('id', $c->entity_id)->value('grand_total'),
                'po' => DB::table('purchase_orders')->where('id', $c->entity_id)->value('grand_total'),
                default => DB::table('returns')->where('id', $c->entity_id)->value('grand_total'),
            };
            if ($grand === null || abs((float) $grand - (float) $c->system_balance) >= 0.005) {
                $out[] = ['doc' => $this->docLabel($c->entity_type, (int) $c->entity_id), 'reviewed_grand' => (float) $c->system_balance,
                    'now_grand' => $grand === null ? 'DELETED' : (float) $grand, 'by' => $c->user?->displayName(), 'at' => $c->updated_at?->toDateTimeString()];
            }
        }

        return $out;
    }

    /** كل تعديل من شاشة المراجعة (بنود/تاريخ/مندوب) بقبل ← بعد، وحالة المستند دلوقتي */
    private function reviewEdits(): array
    {
        $out = [];
        foreach (DocReviewEvent::with('user:id,name,name_en')->whereIn('action', ['lines', 'date', 'rep'])->orderBy('id')->get() as $e) {
            $now = match ($e->doc_type) {
                'invoice' => DB::table('invoices')->where('id', $e->doc_id)->first(['number', 'grand_total']),
                'po' => DB::table('purchase_orders')->where('id', $e->doc_id)->first(['number', 'grand_total']),
                default => DB::table('returns')->where('id', $e->doc_id)->first(['number', 'grand_total']),
            };
            $out[] = [
                'at' => $e->created_at?->toDateTimeString(), 'by' => $e->user?->displayName(), 'doc' => $now?->number ?? $e->doc_type.'#'.$e->doc_id,
                'action' => $e->action,
                'before' => $e->action === 'lines' ? (float) ($e->before['grand'] ?? 0) : json_encode(array_diff_key($e->before ?? [], ['lines' => 1]), JSON_UNESCAPED_UNICODE),
                'after' => $e->action === 'lines' ? (float) ($e->after['grand'] ?? 0) : json_encode(array_diff_key($e->after ?? [], ['lines' => 1, 'review_cleared' => 1]), JSON_UNESCAPED_UNICODE),
                'grand_delta' => (float) $e->grand_delta, 'now_grand' => $now ? (float) $now->grand_total : 'DELETED',
                'still_as_saved' => $e->action === 'lines' && $now ? (abs((float) $now->grand_total - (float) ($e->after['grand'] ?? 0)) < self::EPS ? 'yes' : 'changed since') : '',
            ];
        }

        return $out;
    }

    // ═══════════════════════ التصليح (التكرار بس) ═══════════════════════

    private function fix(bool $apply, string $dir, string $stamp): int
    {
        $this->newLine();
        $this->info($apply ? '── التصليح (هيتكتب) ──' : '── التصليح — تجربة من غير كتابة (زوّد --apply عشان يتكتب) ──');

        // ١. قيود مكررة بالحرف — الزيادة تتشال
        $dupRows = [];
        foreach ($this->report['dup_ledger'] as $g) {
            if ($g['exact_copy'] !== 'yes' || $g['kind'] === 'consignment') {
                continue;
            }
            $ids = array_map('intval', explode(',', $g['ids']));
            array_shift($ids);
            foreach ($ids as $id) {
                $dupRows[] = $id;
            }
            $this->line("  مكرر: {$g['doc']} {$g['kind']} — هيتشال ".implode(',', $ids));
        }

        // ٢. فواتير اتحصّلت مرتين بتحويلها كاش ← ترجع آجل
        $revert = array_values(array_filter($this->report['double_collected'], fn ($r) => $r['likely_double'] === 'YES'));
        foreach ($revert as $r) {
            $this->line("  اتحصّلت مرتين: {$r['doc']} (ورقي {$r['paper_ref']}) — ترجع آجل، ويتشال تحصيل {$r['linked_collection']} · رصيد العميل {$r['client_balance']} ← ".round($r['client_balance'] + $r['linked_collection'], 2));
        }

        if ($dupRows === [] && $revert === []) {
            $this->info('  مفيش حاجة تتصلّح.');

            return self::SUCCESS;
        }
        if (! $apply) {
            return self::SUCCESS;
        }

        // لقطة قبل أي كتابة
        $snapshot = [
            'at' => now()->toDateTimeString(),
            'duplicate_rows' => DB::table('transactions')->whereIn('id', $dupRows ?: [0])->get(),
            'reverted_invoices' => array_map(fn ($r) => [
                'invoice' => DB::table('invoices')->where('number', $r['doc'])->first(),
                'collections' => DB::table('transactions')->where('source_type', Invoice::class)
                    ->where('source_id', DB::table('invoices')->where('number', $r['doc'])->value('id'))->where('kind', 'collection')->get(),
            ], $revert),
        ];
        $snap = "{$dir}/fix-snapshot-{$stamp}.json";
        Storage::disk('local')->put($snap, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info('  اللقطة: '.Storage::disk('local')->path($snap));

        $clients = [];
        $done = 0;
        foreach ($dupRows as $id) {
            try {
                DB::transaction(function () use ($id, &$clients) {
                    $tx = Transaction::find($id);
                    if ($tx === null) {
                        return;
                    }
                    $clients[$tx->client_id] = true;
                    $tx->delete();   // صف صف — أوبزرفر الدفتر بيشيل قيده
                });
                $done++;
            } catch (\Throwable $e) {
                $this->error("  قيد {$id}: ".$e->getMessage());
            }
        }
        foreach ($revert as $r) {
            try {
                DB::transaction(function () use ($r, &$clients) {
                    $inv = Invoice::where('number', $r['doc'])->firstOrFail();
                    // نفس زرار «آجل» في شاشة الفاتورة بالحرف
                    Transaction::where('source_type', Invoice::class)->where('source_id', $inv->id)->where('kind', 'collection')->get()->each->delete();
                    $inv->update(['payment' => 'credit']);
                    $clients[$inv->client_id] = true;
                    InvoiceGl::repost($inv, 'reviewAuditFix');
                });
                $done++;
            } catch (\Throwable $e) {
                $this->error("  {$r['doc']}: ".$e->getMessage());
            }
        }
        foreach (array_keys($clients) as $cid) {
            Client::find($cid)?->recalculate();
        }
        $this->info("  اتصلّح {$done} — ورصيد ".count($clients).' عميل اتحسب تاني.');

        return self::SUCCESS;
    }

    // ═══════════════════════ مساعدات ═══════════════════════

    /** @return \Illuminate\Support\Collection<int, Invoice> */
    private function toggledToCash()
    {
        $ids = DB::table('activity_logs')->where('subject_type', 'Invoice')->where('event', 'updated')
            ->where('route', 'ops.invoices.payment')->where('changes', 'like', '%"payment"%')
            ->orderBy('id')->get(['subject_id', 'changes'])
            ->filter(fn ($r) => (json_decode((string) $r->changes, true)['payment'][1] ?? null) === 'cash')
            ->pluck('subject_id')->unique()->all();

        return Invoice::with('client:id,code,name,name_en')->whereIn('id', $ids)->get();
    }

    private function ledgerBalance(int $clientId): float
    {
        return (float) DB::table('transactions')->where('client_id', $clientId)->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) AS b')->value('b');
    }

    private function docLabel(string $type, int $id): string
    {
        [$table, $t] = match ($type) {
            Invoice::class, 'invoice' => ['invoices', 'invoice'],
            PurchaseOrder::class, 'po' => ['purchase_orders', 'po'],
            default => ['returns', 'return'],
        };

        return (string) (DB::table($table)->where('id', $id)->value('number') ?? $t.'#'.$id);
    }

    private function dumpDoc(string $number): void
    {
        $number = mb_strtoupper(trim($number));
        foreach ([[Invoice::class, 'invoices'], [PurchaseOrder::class, 'purchase_orders'], [ClientReturn::class, 'returns']] as [$cls, $table]) {
            $d = DB::table($table)->where('number', $number)->first();
            if ($d === null) {
                continue;
            }
            $this->newLine();
            $this->info("── {$number} (ورقي ".($d->paper_ref ?? '—').') · العميل #'.$d->client_id.' · الإجمالي '.$d->grand_total.(isset($d->payment) ? ' · '.$d->payment : ''));
            $this->table(['id', 'date', 'kind', 'source', 'debit', 'credit', 'memo'],
                Transaction::where('client_id', $d->client_id)->where(fn ($q) => $q->where(fn ($w) => $w->where('source_type', $cls)->where('source_id', $d->id))
                    ->orWhere('memo', 'like', '%'.$number.'%'))->orderBy('date')->orderBy('id')->get()
                    ->map(fn ($t) => [$t->id, substr((string) $t->date, 0, 10), $t->kind, class_basename((string) $t->source_type).'#'.$t->source_id, $t->debit, $t->credit, mb_substr((string) $t->memo, 0, 60)])->all());
            $this->line('  رصيد العميل من الدفتر: '.round($this->ledgerBalance((int) $d->client_id), 2));

            return;
        }
        $this->warn("مالقيتش {$number}");
    }

    private function csv(array $rows): string
    {
        $h = fopen('php://temp', 'r+');
        fwrite($h, "\xEF\xBB\xBF");
        fputcsv($h, array_keys($rows[0]));
        foreach ($rows as $r) {
            fputcsv($h, array_map(fn ($v) => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v, $r));
        }
        rewind($h);

        return (string) stream_get_contents($h);
    }
}
