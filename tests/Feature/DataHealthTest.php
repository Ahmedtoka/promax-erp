<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * الخط ٧ — `promax:data-health` (قراءة بس) و`promax:fix-double-custody` (محروس).
 *
 * الفيكستشر بيزرع حالة «وحشة» واحدة لكل فحص + حالة سليمة جنبها، عشان
 * العدّاد يثبت إنه بيمسك الغلط **ومابيمسكش** الصح.
 */
class DataHealthTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('framework/testing/data-health-'.uniqid());
        @mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    /** @return array<string, array{0:string,1:string}> check → [n, detail] */
    private function health(): array
    {
        $csv = $this->dir.'/health.csv';
        $this->assertSame(0, Artisan::call('promax:data-health', ['--csv' => $csv, '--sheets' => $this->dir]));

        return collect($this->readCsv($csv))->mapWithKeys(fn ($r) => [$r[0] => [$r[1], $r[2]]])->all();
    }

    private function readCsv(string $path): array
    {
        $lines = array_map('str_getcsv', file($path, FILE_IGNORE_NEW_LINES));
        $lines[0][0] = ltrim($lines[0][0], "\xEF\xBB\xBF");
        array_shift($lines);

        return $lines;
    }

    private function custody(User $u, string $status, string $date, int $assigned = 0): int
    {
        $id = DB::table('custodies')->insertGetId(['user_id' => $u->id, 'date' => $date, 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
        if ($assigned) {
            DB::table('custody_items')->insert(['custody_id' => $id, 'product_id' => $this->makeProduct()->id, 'assigned' => $assigned, 'sold' => 0, 'returned' => 0, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $id;
    }

    private function world(): array
    {
        $manager = $this->makeAdmin(['role' => 'manager', 'code' => 'MGR-T1']);
        $rep = $this->makeRep(['code' => 'SLS-T1', 'manager_id' => $manager->id]);
        $rep2 = $this->makeRep(['code' => 'SLS-T2', 'manager_id' => $manager->id]);
        $zone = $this->makeZone();
        $channel = $this->makeChannel();
        $list = $this->makePriceList();

        $full = ['zone_id' => $zone->id, 'channel_id' => $channel->id, 'price_list_id' => $list->id, 'manager_id' => $manager->id, 'rep_id' => $rep->id];
        $ok = $this->makeClient(['code' => 'CL-OK']);
        $ok->forceFill($full)->save();
        $noZone = $this->makeClient(['code' => 'CL-NZ', 'name' => 'بلا زون']);
        $noZone->forceFill(array_merge($full, ['zone_id' => null, 'manager_id' => null]))->save();
        $noChan = $this->makeClient(['code' => 'CL-NC']);
        $noChan->forceFill(array_merge($full, ['channel_id' => null, 'price_list_id' => null]))->save();
        // المعلّق مايتعدّش حتى لو ناقصه كل حاجة
        $pending = $this->makeClient(['code' => 'CL-PD', 'status' => 'pending']);
        $pending->forceFill(['zone_id' => null, 'channel_id' => null, 'price_list_id' => null, 'manager_id' => null])->save();

        $this->makeProduct(['code' => 'P-ZERO', 'cost' => 0]);
        $this->makeProduct(['code' => 'P-OFF', 'cost' => 0, 'active' => false]);

        // فواتير: زوج مكرر (٣٧ ثانية، نفس الورقية) + ورقية متكررة لعميل تاني بعد ساعتين
        $inv = fn (string $no, Client $c, float $g, string $at, ?string $paper) => DB::table('invoices')->insert([
            'number' => $no, 'paper_ref' => $paper, 'client_id' => $c->id, 'user_id' => $rep->id, 'payment' => 'credit',
            'subtotal' => $g, 'total' => $g, 'tax_total' => 0, 'grand_total' => $g, 'created_at' => $at, 'updated_at' => $at,
        ]);
        $inv('INV-T1', $ok, 5171.04, '2026-09-08 10:00:00', '1161');
        $inv('INV-T2', $ok, 5171.04, '2026-09-08 10:00:37', '1161');
        $inv('INV-T3', $noZone, 300, '2026-09-08 12:00:00', '0633');
        $inv('INV-T4', $noChan, 900, '2026-09-08 14:00:00', '0633');
        $inv('INV-T5', $ok, 5171.04, '2026-09-09 10:00:00', null);   // نفس المبلغ بس يوم تاني

        // عهد: العمود NOT NULL في السكيما الحالية، فالـNULL القديمة مش قابلة للزرع؛ الكود لسه بيعدّها مفتوحة
        $old = $this->custody($rep, 'open', '2026-08-09', 5);
        $new = $this->custody($rep, 'open', '2026-08-12');
        $this->custody($rep, 'closed', '2026-08-01');
        $this->custody($rep2, 'open', '2026-09-01');
        $this->custody($rep2, 'closed', '2026-08-01');

        $wh = $this->makeWarehouse();
        $pick = fn (string $no, string $purpose, int $daysAgo) => DB::table('pick_orders')->insert([
            'number' => $no, 'warehouse_id' => $wh->id, 'assigned_to' => $rep->id, 'purpose' => $purpose, 'status' => 'ready',
            'started_at' => now()->subDays($daysAgo + 1), 'ready_at' => now()->subDays($daysAgo), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $pick('PK-T1', 'online', 10);
        $pick('PK-T2', 'online', 9);
        $pick('PK-T3', 'van_load', 8);
        $pick('PK-T4', 'online', 2);

        $po = fn (string $no, string $status, int $daysAgo) => DB::table('purchase_orders')->insertGetId([
            'number' => $no, 'client_id' => $ok->id, 'status' => $status, 'delivered_at' => $status === 'delivered' ? now() : null,
            'created_at' => now()->subDays($daysAgo), 'updated_at' => now(),
        ]);
        $po('PO-T1', 'pending', 20);
        $po('PO-T2', 'arrived', 20);
        $po('PO-T3', 'delivered', 20);
        $po('PO-T4', 'cancelled', 20);
        $linked = $po('PO-T5', 'pending', 3);

        $rpl = fn (string $no, string $status, ?int $poId) => DB::table('replenishment_requests')->insert([
            'number' => $no, 'client_id' => $ok->id, 'requested_by' => $rep->id, 'status' => $status,
            'purchase_order_id' => $poId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $rpl('RPL-T1', 'assigned', null);
        $rpl('RPL-T2', 'delivered', null);
        $rpl('RPL-T3', 'pending', null);
        $rpl('RPL-T4', 'assigned', $linked);

        return compact('rep', 'old', 'new');
    }

    public function test_health_counts_each_seeded_problem_and_ignores_the_healthy_rows(): void
    {
        $this->world();
        $h = $this->health();

        $this->assertSame(['1', 'INV-T1/INV-T2'], $h['invoices: duplicates (rep+client+grand_total, 10 min)']);
        $this->assertSame('2', $h['invoices: same paper_ref twice (same rep)'][0]);
        $this->assertStringContainsString('INV-T1/INV-T2*', $h['invoices: same paper_ref twice (same rep)'][1]);
        $this->assertSame(['1', 'SLS-T1: #'.DB::table('custodies')->where('date', '2026-08-09')->value('id').','.DB::table('custodies')->where('date', '2026-08-12')->value('id')],
            $h['custody: users with >1 open custody']);
        $this->assertSame(['3', 'online=2, van_load=1'], $h['picks: ready > 7 days']);
        $this->assertSame(['2', 'RPL-T1, RPL-T2'], $h['refills: assigned/delivered without PO']);
        $this->assertSame(['2', 'arrived=1, pending=1'], $h['POs: open > 14 days']);
        $this->assertSame('1', $h['clients active: no zone'][0]);
        $this->assertSame('1', $h['clients active: no channel'][0]);
        $this->assertSame('1', $h['clients active: no price list'][0]);
        $this->assertSame('1', $h['clients active: no manager'][0]);
        $this->assertSame('1', $h['products active: zero cost'][0]);
    }

    public function test_sheets_list_offending_clients_and_products_for_the_owner(): void
    {
        $this->world();
        $this->health();

        $clients = collect($this->readCsv($this->dir.'/clients_missing_fields.csv'))->keyBy(1);
        $this->assertSame(['CL-NC', 'CL-NZ'], $clients->keys()->sort()->values()->all());
        $this->assertSame('zone + manager', $clients['CL-NZ'][8]);
        $this->assertSame('SLS-T1', $clients['CL-NZ'][3]);
        $this->assertSame('channel + price_list', $clients['CL-NC'][8]);
        $this->assertSame('MGR-T1', $clients['CL-NC'][5]);

        $products = $this->readCsv($this->dir.'/products_zero_cost.csv');
        $this->assertSame(['P-ZERO'], array_column($products, 1));
    }

    public function test_health_writes_nothing_and_leaves_the_session_writable(): void
    {
        $this->world();
        $before = DB::table('custodies')->orderBy('id')->get()->toJson().DB::table('clients')->count();

        $this->health();

        $this->assertSame($before, DB::table('custodies')->orderBy('id')->get()->toJson().DB::table('clients')->count());
        // READ ONLY اتشالت في الآخر — الكتابة اللي بعدها لازم تعدّي
        DB::table('custodies')->where('id', 1)->update(['updated_at' => now()]);
        $this->assertTrue(true);
    }

    public function test_fix_preview_and_guards_never_write(): void
    {
        $w = $this->world();

        $this->artisan('promax:fix-double-custody')->assertSuccessful();
        $this->artisan('promax:fix-double-custody', ['--close' => $w['old']])->assertFailed();            // فيها بضاعة
        $this->artisan('promax:fix-double-custody', ['--close' => $w['old'], '--remaining-ok' => true])->assertSuccessful(); // dry run
        $this->artisan('promax:fix-double-custody', ['--close' => 999999, '--apply' => true])->assertFailed();

        $this->assertSame('open', DB::table('custodies')->where('id', $w['old'])->value('status'));
        $this->assertSame('open', DB::table('custodies')->where('id', $w['new'])->value('status'));
    }

    public function test_fix_refuses_to_write_on_promax(): void
    {
        $w = $this->world();
        config(['database.connections.'.config('database.default').'.database' => 'promax']);

        $this->artisan('promax:fix-double-custody', ['--close' => $w['new'], '--apply' => true])
            ->expectsOutputToContain('Refused on promax')->assertFailed();

        $this->assertSame('open', DB::table('custodies')->where('id', $w['new'])->value('status'));
    }

    public function test_fix_apply_closes_only_the_named_custody(): void
    {
        $w = $this->world();

        $this->artisan('promax:fix-double-custody', ['--close' => $w['old'], '--apply' => true, '--remaining-ok' => true])->assertSuccessful();

        $this->assertSame('closed', DB::table('custodies')->where('id', $w['old'])->value('status'));
        $this->assertNotNull(DB::table('custodies')->where('id', $w['old'])->value('closed_at'));
        $this->assertSame('open', DB::table('custodies')->where('id', $w['new'])->value('status'));
        $this->assertSame((int) $w['new'], (int) $w['rep']->fresh()->currentCustody()?->id);

        // المرة التانية: مفيش عهدة مزدوجة خلاص، فمفيش حاجة تتقفل
        $this->artisan('promax:fix-double-custody', ['--close' => $w['new'], '--apply' => true])
            ->expectsOutputToContain('No user has more than one open custody')->assertSuccessful();
        $this->assertSame('open', DB::table('custodies')->where('id', $w['new'])->value('status'));
    }
}
