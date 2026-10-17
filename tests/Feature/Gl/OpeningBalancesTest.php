<?php

namespace Tests\Feature\Gl;

use App\Models\Gl\GlAccount;
use App\Models\Gl\GlEntry;
use App\Models\Setting;
use App\Models\Transaction;
use App\Services\Gl\Ledger;
use App\Services\Gl\OpeningBalances;
use Database\Seeders\GlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OpeningBalancesTest extends TestCase
{
    use RefreshDatabase;

    private function csv(string $body): string
    {
        $path = tempnam(sys_get_temp_dir(), 'opening').'.csv';
        file_put_contents($path, "\xEF\xBB\xBF".$body);

        return $path;
    }

    private function books(): void
    {
        $this->seed(GlSeeder::class);
        Setting::write('gl_enabled', '1');
        Setting::write('gl_start_date', '2026-08-01');
        Setting::flushCache();
    }

    public function test_preview_flags_every_bad_row_and_writes_nothing(): void
    {
        $this->books();
        $a = $this->makeClient(['code' => 'CL-A1']);
        $this->makeClient(['code' => 'CL-B2']);
        $file = $this->csv("code,amount,date\nCL-A1,\"12,500.50\",2026-08-01\nCL-XX,100,2026-08-01\nCL-B2,abc,\n,50,\nCL-A1,10,\nCL-B2,30,31/13/2026\n");

        $p = app(OpeningBalances::class)->preview($file);

        $this->assertSame(['ok', 'unknown_client', 'bad_amount', 'missing_code', 'duplicate_code', 'bad_date'], array_column($p['rows'], 'status'));
        $this->assertSame(1, $p['ok']);
        $this->assertSame(12500.5, $p['total']);
        $this->assertSame($a->id, $p['rows'][0]['client_id']);
        $this->assertSame(0, Transaction::count());
        $this->assertSame(0, GlEntry::count());
    }

    public function test_post_writes_opening_debt_and_the_gl_customers_account_still_equals_client_balances(): void
    {
        $this->books();
        $admin = $this->makeAdmin();
        $a = $this->makeClient(['code' => 'CL-A1']);
        $b = $this->makeClient(['code' => 'CL-B2']);
        // التاريخ الفاضي = gl_start_date
        $file = $this->csv("CL-A1,1500,2026-08-01\nCL-B2,-200,\n");

        $n = app(OpeningBalances::class)->post($file, $admin);

        $this->assertSame(2, $n);
        $txA = Transaction::where('client_id', $a->id)->sole();
        $this->assertSame('opening', $txA->kind);
        $this->assertEquals(1500, $txA->debit);
        $this->assertSame('2026-08-01', $txA->date->toDateString());
        $txB = Transaction::where('client_id', $b->id)->sole();
        $this->assertEquals(200, $txB->credit);
        $this->assertSame('2026-08-01', $txB->date->toDateString());

        $this->assertEquals(1500, $a->fresh()->balance);
        $this->assertEquals(-200, $b->fresh()->balance);

        $this->assertSame(2, GlEntry::where('rule_key', 'tx.opening')->count());
        $recv = GlAccount::findKey('receivables')->balanceBetween(null, null);
        $this->assertSame(round((float) DB::table('clients')->sum('balance'), 2), $recv);
        $this->assertTrue(app(Ledger::class)->invariants()['receivables']['ok']);
        $this->assertSame(1300.0, GlAccount::findKey('opening_equity')->balanceBetween(null, null));
    }

    public function test_one_bad_row_rejects_the_whole_file(): void
    {
        $this->books();
        $admin = $this->makeAdmin();
        $this->makeClient(['code' => 'CL-A1']);
        $file = $this->csv("CL-A1,1500,2026-08-01\nCL-NOPE,10,2026-08-01\n");

        try {
            app(OpeningBalances::class)->post($file, $admin);
            $this->fail('expected the file to be rejected');
        } catch (\InvalidArgumentException) {
        }

        $this->assertSame(0, Transaction::count());
    }

    public function test_the_same_file_twice_posts_once(): void
    {
        $this->books();
        $admin = $this->makeAdmin();
        $a = $this->makeClient(['code' => 'CL-A1']);
        $file = $this->csv("CL-A1,1500,2026-08-01\n");
        $svc = app(OpeningBalances::class);

        $this->assertSame(1, $svc->post($file, $admin));
        $this->assertSame('already_posted', $svc->preview($file)['rows'][0]['status']);
        $this->assertSame(0, $svc->post($file, $admin));

        $this->assertSame(1, Transaction::count());
        $this->assertEquals(1500, $a->fresh()->balance);
    }

    public function test_preview_counts_rows_dated_before_the_gl_start(): void
    {
        $this->books();
        $this->makeClient(['code' => 'CL-A1']);
        $file = $this->csv("CL-A1,1500,2026-07-31\n");

        $this->assertSame(1, app(OpeningBalances::class)->preview($file)['before_gl_start']);
    }

    public function test_command_previews_by_default_and_posts_with_confirmation(): void
    {
        $this->books();
        $admin = $this->makeAdmin();
        $a = $this->makeClient(['code' => 'CL-A1']);
        $file = $this->csv("CL-A1,1500,2026-08-01\n");

        $this->artisan('promax:gl-opening', ['file' => $file])->assertSuccessful();
        $this->assertSame(0, Transaction::count());

        $this->artisan('promax:gl-opening', ['file' => $file, '--post' => true, '--by' => 'nobody'])->assertFailed();
        $this->assertSame(0, Transaction::count());

        $this->artisan('promax:gl-opening', ['file' => $file, '--post' => true, '--by' => $admin->code])
            ->expectsConfirmation('Post 1 opening entries totalling 1,500.00 on '.config('database.connections.mysql.database').'?', 'yes')
            ->assertSuccessful();
        $this->assertEquals(1500, $a->fresh()->balance);
    }
}
