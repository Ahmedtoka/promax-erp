<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\ClientReturn;
use App\Models\Invoice;
use App\Models\RepSettlement;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Reprice\FullResync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * ═══════════════════════════════════════════════════════════════
 * تحصيل الكاش / رد المرتجع الكاش ≠ إجمالي المستند — إصلاح الداتا
 * (قرار أحمد ١٣/١١/٢٠٢٦: «أكيد مبلغ التحصيل اللي نزل على السعر القديم غلط»)
 * ═══════════════════════════════════════════════════════════════
 *
 * تصليح الأسعار (١٠/١١) عاد تسعير المستندات في المكان وساب التحصيل/الرد
 * زي ما هو، فالفاتورة الكاش بقى تحصيلها ≠ إجماليها. من دلوقتي الكود بيحرّكهم
 * (`InvoiceRepricer::cashCollectionFollows`، `FullResync::clientReturn`) —
 * والأمر ده بيصلّح اللي اتسجّل قبل كده.
 *
 *   php artisan prices:repair-collections              ← dry-run: القايمة بس
 *   php artisan prices:repair-collections --apply      ← تطبيق + snapshot
 *   php artisan prices:repair-collections --rollback=storage/app/price-audit/collections-snapshot-….json --apply
 *
 * لكل مستند: التحصيل/الرد القديم ← الإجمالي، العميل، المندوب، و⚠️ لو المستند
 * جوه فترة **تصفية مندوب** اتعملت (المندوب سلّم الفلوس القديمة فعلاً) — التصفية
 * نفسها مابتتعدّلش، والفرق لازم أحمد يقرر فيه مع المندوب.
 */
class RepairCashCollections extends Command
{
    protected $signature = 'prices:repair-collections
        {--apply : اكتب فعلاً (من غيره dry-run)}
        {--rollback= : رجّع snapshot اتعمل قبل كده}
        {--csv= : مسار نسخة إضافية من الـCSV}';

    protected $description = 'تحصيل الفاتورة الكاش ورد المرتجع الكاش = إجمالي المستند (dry-run افتراضياً)';

    private const EPS = 0.005;

    public function handle(): int
    {
        if ($rb = $this->option('rollback')) {
            return $this->rollback((string) $rb);
        }

        $rows = $this->scan();
        $this->info(($this->option('apply') ? '══ APPLY ══' : '══ DRY-RUN (مفيش كتابة) ══').' · DB: '.DB::connection()->getDatabaseName());

        if ($rows === []) {
            $this->info('مفيش تحصيل ولا رد مختلف عن إجمالي مستنده.');

            return self::SUCCESS;
        }

        $this->table(['المستند', 'ورقي', 'التاريخ', 'العميل', 'المندوب', 'النوع', 'قبل', 'بعد', 'الفرق', 'تصفية المندوب'],
            array_map(fn ($r) => [$r['doc'], $r['paper_ref'] ?? '', $r['date'], $r['client'], $r['rep'], $r['kind'],
                number_format($r['old'], 2), number_format($r['new'], 2), number_format($r['diff'], 2), $r['settled'] ?? 'لسه — في عهدته'], $rows));
        $this->line(sprintf('الإجمالي: %d مستند · صافي الفرق %s · منهم %d جوه تصفية اتعملت',
            count($rows), number_format(array_sum(array_column($rows, 'diff')), 2), count(array_filter($rows, fn ($r) => $r['settled'] !== null))));

        $csv = $this->writeCsv($rows);
        $this->info('CSV: '.$csv);

        if (! $this->option('apply')) {
            $this->warn('dry-run: مفيش حاجة اتكتبت. شغّل بـ --apply للتنفيذ.');

            return self::SUCCESS;
        }

        $snap = ['at' => now()->toIso8601String(), 'db' => DB::connection()->getDatabaseName(), 'tx' => []];
        DB::transaction(function () use ($rows, &$snap) {
            $clients = [];
            foreach ($rows as $r) {
                $tx = Transaction::lockForUpdate()->find($r['tx_id']);
                $col = $r['kind'] === 'refund' ? 'debit' : 'credit';
                $snap['tx'][] = ['id' => $tx->id, $col => (float) $tx->{$col}, 'tax' => (float) $tx->tax];
                $tx->forceFill([$col => $r['new'], 'tax' => $r['tax']])->saveQuietly();
                FullResync::repostGl([$tx], 'repair-collections '.$r['doc']);
                $clients[$r['client_id']] = true;
            }
            foreach (Client::whereIn('id', array_keys($clients))->get() as $c) {
                $c->recalculate();
            }
        });
        $path = 'price-audit/collections-snapshot-'.now()->format('Ymd-His').'.json';
        Storage::put($path, json_encode($snap, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $this->info('اتطبّق. snapshot للرجوع: storage/app/'.$path);

        return self::SUCCESS;
    }

    /** @return list<array<string, mixed>> */
    private function scan(): array
    {
        $out = [];
        $settlements = RepSettlement::query()->get(['id', 'number', 'user_id', 'from_at', 'to_at'])->groupBy('user_id');
        $settledIn = function (?int $repId, $at) use ($settlements) {
            foreach ($settlements[$repId] ?? [] as $s) {
                if ($at && $s->from_at && $s->to_at && $at->between($s->from_at, $s->to_at)) {
                    return $s->number ?: '#'.$s->id;
                }
            }

            return null;
        };
        $users = User::pluck('name', 'id');
        $clients = Client::get(['id', 'code', 'name', 'name_en'])->keyBy('id');
        $label = fn ($cid) => ($c = $clients[$cid] ?? null) ? $c->code.' '.$c->name : '#'.$cid;

        // الفاتورة الكاش: قيد تحصيل واحد مربوط (الفهرس الفريد بيمنع اتنين)
        $colls = Transaction::where('source_type', Invoice::class)->where('kind', 'collection')->get()->keyBy('source_id');
        foreach (Invoice::where('payment', 'cash')->get(['id', 'number', 'paper_ref', 'client_id', 'user_id', 'grand_total', 'tax_total', 'created_at']) as $inv) {
            $tx = $colls[$inv->id] ?? null;
            if (! $tx || abs((float) $tx->credit - (float) $inv->grand_total) < self::EPS) {
                continue;
            }
            if (Transaction::where('source_type', Invoice::class)->where('source_id', $inv->id)->where('kind', 'consignment')->exists()) {
                continue;
            }
            $out[] = $this->row('collection', $inv->number, $inv->paper_ref, $inv->created_at, $inv->client_id, $label($inv->client_id),
                $inv->user_id, $users[$inv->user_id] ?? '—', (float) $tx->credit, (float) $inv->grand_total, (float) $inv->tax_total, $tx->id,
                $settledIn($inv->user_id, $inv->created_at));
        }

        // المرتجع الكاش: الرد اللي المندوب دفعه
        foreach (ClientReturn::where('policy', ClientReturn::POLICY_CASH)->whereNotNull('refund_transaction_id')->get() as $r) {
            $tx = Transaction::find($r->refund_transaction_id);
            if (! $tx || abs((float) $tx->debit - (float) $r->grand_total) < self::EPS) {
                continue;
            }
            $out[] = $this->row('refund', $r->number, $r->paper_ref, $r->created_at, $r->client_id, $label($r->client_id),
                $r->user_id, $users[$r->user_id] ?? '—', (float) $tx->debit, (float) $r->grand_total, (float) $r->tax_total, $tx->id,
                $settledIn($r->user_id, $r->created_at));
        }

        return $out;
    }

    private function row(string $kind, $doc, $paper, $at, $clientId, $client, $repId, $rep, float $old, float $new, float $tax, int $txId, ?string $settled): array
    {
        return ['kind' => $kind, 'doc' => $doc, 'paper_ref' => $paper, 'date' => $at?->format('Y-m-d'), 'client_id' => $clientId, 'client' => $client,
            'rep_id' => $repId, 'rep' => $rep, 'old' => round($old, 2), 'new' => round($new, 2), 'diff' => round($new - $old, 2), 'tax' => round($tax, 2),
            'tx_id' => $txId, 'settled' => $settled];
    }

    private function writeCsv(array $rows): string
    {
        $path = 'price-audit/collections-'.now()->format('Ymd-His').'.csv';
        $fh = fopen('php://temp', 'w+');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, ['doc', 'paper_ref', 'date', 'client', 'rep', 'kind', 'old', 'new', 'diff', 'settled_in', 'transaction_id']);
        foreach ($rows as $r) {
            fputcsv($fh, [$r['doc'], $r['paper_ref'], $r['date'], $r['client'], $r['rep'], $r['kind'], $r['old'], $r['new'], $r['diff'], $r['settled'] ?? '', $r['tx_id']]);
        }
        rewind($fh);
        $body = stream_get_contents($fh);
        Storage::put($path, $body);
        if ($extra = $this->option('csv')) {
            file_put_contents((string) $extra, $body);
        }

        return 'storage/app/'.$path;
    }

    private function rollback(string $file): int
    {
        $path = str_starts_with($file, 'storage/app/') ? substr($file, 12) : $file;
        $snap = json_decode((string) Storage::get($path), true);
        if (! is_array($snap)) {
            $this->error('snapshot مش موجود: '.$file);

            return self::FAILURE;
        }
        if (! $this->option('apply')) {
            $this->warn('dry-run للرجوع: '.count($snap['tx'] ?? []).' قيد. شغّل بـ --apply.');

            return self::SUCCESS;
        }
        DB::transaction(function () use ($snap) {
            $clients = [];
            foreach ($snap['tx'] ?? [] as $row) {
                if ($tx = Transaction::find($row['id'])) {
                    $tx->forceFill(collect($row)->except('id')->all())->saveQuietly();
                    FullResync::repostGl([$tx], 'repair-collections rollback');
                    $clients[$tx->client_id] = true;
                }
            }
            foreach (Client::whereIn('id', array_keys($clients))->get() as $c) {
                $c->recalculate();
            }
        });
        $this->info('رجع.');

        return self::SUCCESS;
    }
}
