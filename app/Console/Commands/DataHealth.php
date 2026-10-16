<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ═══ صحة الداتا — قراءة بس (١٧/١٠/٢٠٢٦، الخط ٧) ═══
 *
 * كل فحوصات `PROMAX_DATA_CHECKS_2026-10-17.sql` + الفواتير المكررة،
 * العهد المزدوجة، الريفيل من غير أمر توريد، والتجهيز `ready` القديم.
 * ⚠️ مفيش ولا كتابة: السيشن بتتقفل READ ONLY على MySQL قبل أول كويري،
 * فينفع يشتغل على `promax`.
 *
 *   php artisan promax:data-health
 *   php artisan promax:data-health --csv=storage/app/health.csv --sheets=storage/app/health
 */
class DataHealth extends Command
{
    protected $signature = 'promax:data-health
        {--csv= : اكتب الجدول CSV في المسار ده}
        {--sheets= : فولدر لشيتات التنضيف (عملاء ناقصين + أصناف تكلفتها صفر)}';

    protected $description = 'فحص صحة الداتا — قراءة بس، جدول + CSV + شيتات تنضيف';

    public function handle(): int
    {
        $mysql = DB::connection()->getDriverName() === 'mysql';
        if ($mysql) {
            DB::statement('SET SESSION TRANSACTION READ ONLY');
        }

        try {
            $rows = $this->checks();
            $this->table(['check', 'n', 'detail'], $rows);

            if ($path = $this->option('csv')) {
                $this->writeCsv($path, ['check', 'n', 'detail'], $rows);
                $this->info("CSV: {$path}");
            }
            if ($dir = $this->option('sheets')) {
                $this->writeSheets($dir);
            }
        } finally {
            if ($mysql) {
                DB::statement('SET SESSION TRANSACTION READ WRITE');
            }
        }

        return self::SUCCESS;
    }

    /** @return list<array{0:string,1:int|string,2:string}> */
    private function checks(): array
    {
        $n = fn (string $sql, array $b = []) => (int) (DB::selectOne("SELECT COUNT(*) n FROM ($sql) x", $b)->n ?? 0);
        $d7 = now()->subDays(7);
        $d14 = now()->subDays(14);
        $d1 = now()->subDay();
        $open = "(status IS NULL OR status<>'closed')";
        $rows = [];
        $add = function (string $k, $v, string $d = '') use (&$rows) { $rows[] = [$k, $v, $d]; };

        $add('clients: balance != ledger', $n('SELECT c.id FROM clients c LEFT JOIN (SELECT client_id, SUM(debit)-SUM(credit) b FROM transactions GROUP BY client_id) t ON t.client_id=c.id WHERE ABS(c.balance-IFNULL(t.b,0))>0.01'));
        $add('transactions: orphan client', $n('SELECT t.id FROM transactions t LEFT JOIN clients c ON c.id=t.client_id WHERE c.id IS NULL'));
        $add('transactions: duplicate idem_key', $n('SELECT idem_key FROM transactions WHERE idem_key IS NOT NULL GROUP BY idem_key HAVING COUNT(*)>1'));
        $add('invoices: no sale entry', $n("SELECT i.id FROM invoices i WHERE NOT EXISTS (SELECT 1 FROM transactions t WHERE t.source_type LIKE '%Invoice' AND t.source_id=i.id)"));
        $add('invoices: grand_total < total', $n('SELECT id FROM invoices WHERE grand_total+0.01 < total'));

        $dup = DB::select('SELECT a.number x, b.number y FROM invoices a JOIN invoices b ON a.id<b.id AND a.user_id=b.user_id AND a.client_id=b.client_id AND a.grand_total=b.grand_total AND ABS(TIMESTAMPDIFF(SECOND,a.created_at,b.created_at))<600');
        $add('invoices: duplicates (rep+client+grand_total, 10 min)', count($dup), collect($dup)->map(fn ($r) => "{$r->x}/{$r->y}")->implode(', '));
        $paper = DB::select("SELECT a.number x, b.number y, a.client_id=b.client_id same FROM invoices a JOIN invoices b ON a.id<b.id AND a.paper_ref IS NOT NULL AND a.paper_ref<>'' AND a.paper_ref=b.paper_ref AND a.user_id=b.user_id");
        $add('invoices: same paper_ref twice (same rep)', count($paper), collect($paper)->map(fn ($r) => "{$r->x}/{$r->y}".($r->same ? '*' : ''))->implode(', ').(count($paper) ? '  (*=same client)' : ''));
        $add('invoices: zero total', $n('SELECT id FROM invoices WHERE grand_total<=0'));

        $add('custody: negative remaining lines', $n('SELECT id FROM custody_items WHERE assigned - sold - returned - IFNULL(transferred_out,0) - IFNULL(gift_given,0) < -0.001'));
        $dbl = DB::select("SELECT u.code, GROUP_CONCAT(c.id ORDER BY c.date) ids FROM custodies c JOIN users u ON u.id=c.user_id WHERE (c.status IS NULL OR c.status<>'closed') GROUP BY u.id, u.code HAVING COUNT(*)>1");
        $add('custody: users with >1 open custody', count($dbl), collect($dbl)->map(fn ($r) => "{$r->code}: #{$r->ids}")->implode(', '));

        $add('picks: ready without started_at', $n("SELECT id FROM pick_orders WHERE status='ready' AND started_at IS NULL"));
        $stale = DB::select("SELECT purpose, COUNT(*) n FROM pick_orders WHERE status='ready' AND ready_at < ? GROUP BY purpose", [$d7]);
        $add('picks: ready > 7 days', collect($stale)->sum('n'), collect($stale)->map(fn ($r) => "{$r->purpose}={$r->n}")->implode(', '));

        $rpl = DB::select("SELECT number FROM replenishment_requests WHERE status IN ('assigned','delivered','ready') AND purchase_order_id IS NULL");
        $add('refills: assigned/delivered without PO', count($rpl), collect($rpl)->pluck('number')->implode(', '));

        $add('POs: delivered without delivered_at', $n("SELECT id FROM purchase_orders WHERE status='delivered' AND delivered_at IS NULL"));
        $po = DB::select("SELECT status, COUNT(*) n FROM purchase_orders WHERE status NOT IN ('delivered','cancelled','canceled') AND created_at < ? GROUP BY status", [$d14]);
        $add('POs: open > 14 days', collect($po)->sum('n'), collect($po)->map(fn ($r) => "{$r->status}={$r->n}")->implode(', '));

        foreach (['zone_id' => 'no zone', 'channel_id' => 'no channel', 'price_list_id' => 'no price list', 'manager_id' => 'no manager', 'rep_id' => 'no rep'] as $col => $label) {
            $add("clients active: {$label}", $n("SELECT id FROM clients WHERE status='active' AND {$col} IS NULL"));
        }
        $add('clients active: zone inactive', $n("SELECT c.id FROM clients c JOIN zones z ON z.id=c.zone_id WHERE c.status='active' AND z.active=0"));
        $add('clients active: zone not assigned to anyone', $n("SELECT c.id FROM clients c WHERE c.status='active' AND c.zone_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM zone_user zu WHERE zu.zone_id=c.zone_id)"));
        $add('products active: zero cost', $n('SELECT id FROM products WHERE active=1 AND (cost IS NULL OR cost<=0)'));
        $add('products active: zero price_new', $n('SELECT id FROM products WHERE active=1 AND (price_new IS NULL OR price_new<=0)'));
        $add('users active field: no manager', $n("SELECT id FROM users WHERE active=1 AND role IN ('sales_agent','driver','promoter') AND manager_id IS NULL"));
        $add('visits: open > 1 day', $n('SELECT id FROM visits WHERE checked_out_at IS NULL AND checked_in_at < ?', [$d1]));
        $add('collections non-cash without proof', $n("SELECT id FROM transactions WHERE kind='collection' AND method IS NOT NULL AND method<>'cash' AND (proof_path IS NULL OR proof_path='') AND source_type LIKE '%Visit'"));
        $add('setting gl_enabled', (string) (DB::table('settings')->where('key', 'gl_enabled')->value('value') ?? 'unset'));

        return $rows;
    }

    private function writeSheets(string $dir): void
    {
        @mkdir($dir, 0775, true);
        $clients = DB::select("SELECT c.id, c.code, c.name, r.code rep_code, r.name rep, m.code mgr_code, m.name manager, rm.name rep_manager,
                c.zone_id IS NULL nz, c.channel_id IS NULL nc, c.price_list_id IS NULL np, c.manager_id IS NULL nm
            FROM clients c LEFT JOIN users r ON r.id=c.rep_id LEFT JOIN users m ON m.id=c.manager_id LEFT JOIN users rm ON rm.id=r.manager_id
            WHERE c.status='active' AND (c.zone_id IS NULL OR c.channel_id IS NULL OR c.price_list_id IS NULL OR c.manager_id IS NULL) ORDER BY c.code");
        $rows = array_map(fn ($c) => [$c->id, $c->code, $c->name, $c->rep_code, $c->rep, $c->mgr_code, $c->manager, $c->rep_manager,
            implode(' + ', array_keys(array_filter(['zone' => $c->nz, 'channel' => $c->nc, 'price_list' => $c->np, 'manager' => $c->nm])))], $clients);
        $this->writeCsv("{$dir}/clients_missing_fields.csv", ['id', 'code', 'name', 'rep_code', 'rep', 'manager_code', 'manager', 'rep_manager', 'missing'], $rows);

        $products = DB::select('SELECT id, code, name, family, cost, price_new FROM products WHERE active=1 AND (cost IS NULL OR cost<=0) ORDER BY code');
        $this->writeCsv("{$dir}/products_zero_cost.csv", ['id', 'code', 'name', 'family', 'cost', 'price_new', 'missing'],
            array_map(fn ($p) => [$p->id, $p->code, $p->name, $p->family, $p->cost, $p->price_new, 'cost'], $products));

        $this->info('Sheets: '.count($rows)." clients, ".count($products)." products → {$dir}");
    }

    private function writeCsv(string $path, array $head, array $rows): void
    {
        $f = fopen($path, 'w');
        fwrite($f, "\xEF\xBB\xBF");
        fputcsv($f, $head);
        foreach ($rows as $r) {
            fputcsv($f, array_values((array) $r));
        }
        fclose($f);
    }
}
