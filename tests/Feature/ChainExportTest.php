<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════
 * تصدير السلاسل وكشوف حساب فروعها (٨ سبتمبر ٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * تلات تصديرات CSV (بالـBOM عشان إكسيل عربي — نفس مسار مركز التقارير):
 *   • قايمة السلاسل بأرقامها (نفس فلاتر الشاشة).
 *   • فروع السلسلة بإجمالي كشف حساب كل فرع + صف إجماليات.
 *   • كشف حساب فرع واحد بالحركة والرصيد بعد كل حركة.
 *
 * ⚠️ الأرقام من `transactions` (مصدر الحقيقة) والأعمدة المجمّعة اللي
 * `recalculate()` بتكتبها — فالختامي في كشف الفرع **لازم** يساوي
 * `clients.balance` بالمليم، وده أهم تأكيد هنا.
 */
class ChainExportTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: ClientGroup, 1: Client, 2: Client} */
    private function chainWithTwoBranches(): array
    {
        $channel = $this->makeChannel();
        $group = ClientGroup::create([
            'code' => 'G-'.strtoupper(uniqid()), 'name' => 'سلسلة التيست', 'name_en' => 'Test chain',
            'channel_id' => $channel->id, 'active' => true,
        ]);

        $a = $this->makeClient(['name' => 'فرع أ', 'name_en' => 'Branch A', 'group_id' => $group->id, 'channel_id' => $channel->id]);
        $b = $this->makeClient(['name' => 'فرع ب', 'name_en' => 'Branch B', 'group_id' => $group->id, 'channel_id' => $channel->id]);

        // فرع أ: بيع 1000 يوم 1، تحصيل 400 يوم 2، مرتجع 100 يوم 3 → الرصيد 500
        Transaction::create(['client_id' => $a->id, 'date' => '2026-09-01', 'memo' => 'فاتورة', 'debit' => 1000, 'credit' => 0, 'kind' => 'sale']);
        Transaction::create(['client_id' => $a->id, 'date' => '2026-09-02', 'memo' => 'تحصيل', 'debit' => 0, 'credit' => 400, 'kind' => 'collection', 'method' => 'cash']);
        Transaction::create(['client_id' => $a->id, 'date' => '2026-09-03', 'memo' => 'مرتجع', 'debit' => 0, 'credit' => 100, 'kind' => 'return']);
        $a->recalculate();

        // فرع ب: بيع 250 بس
        Transaction::create(['client_id' => $b->id, 'date' => '2026-09-05', 'memo' => 'فاتورة', 'debit' => 250, 'credit' => 0, 'kind' => 'sale']);
        $b->recalculate();

        return [$group, $a->fresh(), $b->fresh()];
    }

    /** @return list<list<string>> */
    private function rows(string $csv): array
    {
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv, 'الـBOM ناقص — إكسيل هيفتح العربي طلاسم');

        $lines = array_filter(explode("\n", trim(substr($csv, 3))), fn ($l) => $l !== '');

        return array_map(fn ($l) => str_getcsv($l), array_values($lines));
    }

    private function download($response): string
    {
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));

        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    // ═══════════════ 1. قايمة السلاسل ═══════════════

    public function test_chains_export_lists_every_chain_with_its_numbers_and_a_totals_row(): void
    {
        [$group, $a, $b] = $this->chainWithTwoBranches();
        $admin = $this->makeAdmin();

        $rows = $this->rows($this->download($this->actingAs($admin)->get(route('erp.groups.export'))));

        $header = $rows[0];
        $this->assertContains(__('client.chain'), $header);
        $this->assertContains(__('client.balance'), $header);

        $line = collect($rows)->first(fn ($r) => $r[0] === $group->displayName());
        $this->assertNotNull($line, 'السلسلة مش في التصدير');

        $col = array_flip($header);
        $this->assertSame('2', $line[$col[__('client.branch_count')]]);
        $this->assertSame('1250.00', $line[$col[__('client.purchases')]]);
        $this->assertSame('400.00', $line[$col[__('client.collected')]]);
        $this->assertSame('750.00', $line[$col[__('client.balance')]], 'الرصيد = مجموع رصيد الفرعين (500 + 250)');

        $totals = end($rows);
        $this->assertSame(__('common.total'), $totals[0]);
        $this->assertSame('750.00', $totals[$col[__('client.balance')]]);
    }

    public function test_chains_export_respects_the_channel_filter(): void
    {
        [$group] = $this->chainWithTwoBranches();
        $other = ClientGroup::create([
            'code' => 'G-'.strtoupper(uniqid()), 'name' => 'سلسلة تانية', 'name_en' => 'Other chain',
            'channel_id' => $this->makeChannel()->id, 'active' => true,
        ]);
        $admin = $this->makeAdmin();

        $rows = $this->rows($this->download(
            $this->actingAs($admin)->get(route('erp.groups.export', ['channel' => $group->channel_id]))
        ));

        $names = array_column($rows, 0);
        $this->assertContains($group->displayName(), $names);
        $this->assertNotContains($other->displayName(), $names, 'فلتر القناة اتجاهل في التصدير');
    }

    // ═══════════════ 2. فروع السلسلة ═══════════════

    public function test_branches_export_has_one_row_per_branch_and_totals_that_add_up(): void
    {
        [$group, $a, $b] = $this->chainWithTwoBranches();
        $admin = $this->makeAdmin();

        $rows = $this->rows($this->download($this->actingAs($admin)->get(route('erp.groups.statements', $group))));
        $col = array_flip($rows[0]);

        // الهيدر + فرعين + الإجماليات
        $this->assertCount(4, $rows);

        $byName = collect($rows)->slice(1, 2)->keyBy(fn ($r) => $r[0]);
        $rowA = $byName->first(fn ($r, $k) => str_contains($k, $a->displayName()));
        $rowB = $byName->first(fn ($r, $k) => str_contains($k, $b->displayName()));

        $this->assertNotNull($rowA);
        $this->assertNotNull($rowB);
        $this->assertSame('1000.00', $rowA[$col[__('client.purchases')]]);
        $this->assertSame('400.00', $rowA[$col[__('client.collected')]]);
        $this->assertSame('100.00', $rowA[$col[__('client.returns')]]);
        $this->assertSame('500.00', $rowA[$col[__('client.balance')]]);
        $this->assertSame('250.00', $rowB[$col[__('client.balance')]]);

        $totals = end($rows);
        $this->assertSame(__('common.total'), $totals[0]);
        $this->assertSame('1250.00', $totals[$col[__('client.purchases')]]);
        $this->assertSame('750.00', $totals[$col[__('client.balance')]]);
    }

    /**
     * ⚠️ مدير قناة بيشوف فروع فريقه بس — نفس `Client::visibleTo` بتاعة
     * الشاشة. التصدير من غير السكوب كان هيكشف أرقام فريق تاني.
     */
    public function test_branches_export_is_scoped_like_the_screen(): void
    {
        [$group, $a] = $this->chainWithTwoBranches();
        $manager = $this->makeAdmin(['role' => 'manager', 'email' => 'mgr.chain@test.local']);
        $a->update(['manager_id' => $manager->id]);

        $rows = $this->rows($this->download($this->actingAs($manager)->get(route('erp.groups.statements', $group))));

        // الهيدر + فرع أ بس + الإجماليات
        $this->assertCount(3, $rows);
        $this->assertStringContainsString($a->displayName(), $rows[1][0]);
    }

    // ═══════════════ 3. كشف حساب فرع ═══════════════

    public function test_branch_statement_runs_the_balance_and_closes_on_the_client_balance(): void
    {
        [$group, $a] = $this->chainWithTwoBranches();
        $admin = $this->makeAdmin();

        $rows = $this->rows($this->download(
            $this->actingAs($admin)->get(route('erp.groups.branch_statement', [$group, $a]))
        ));
        $col = array_flip($rows[0]);

        // الهيدر + 3 حركات + الإجماليات
        $this->assertCount(5, $rows);

        $running = $col[__('client.running_balance')];
        $this->assertSame('1000.00', $rows[1][$running]);
        $this->assertSame('600.00', $rows[2][$running]);
        $this->assertSame('500.00', $rows[3][$running]);

        $this->assertSame('2026-09-01', $rows[1][$col[__('common.date')]]);
        $this->assertSame('1000.00', $rows[1][$col[__('client.debit')]]);
        $this->assertSame('400.00', $rows[2][$col[__('client.credit')]]);

        $totals = end($rows);
        $this->assertSame('1000.00', $totals[$col[__('client.debit')]]);
        $this->assertSame('500.00', $totals[$col[__('client.credit')]]);
        $this->assertSame(number_format((float) $a->balance, 2, '.', ''), $totals[$running],
            'الختامي في الكشف لازم يساوي clients.balance بالمليم');
    }

    /** فترة: الحركات قبلها تتلخّص في صف «رصيد سابق» عشان الرصيد يفضل يقفل */
    public function test_a_dated_statement_opens_with_the_balance_brought_forward(): void
    {
        [$group, $a] = $this->chainWithTwoBranches();
        $admin = $this->makeAdmin();

        $rows = $this->rows($this->download(
            $this->actingAs($admin)->get(route('erp.groups.branch_statement', [$group, $a, 'from' => '2026-09-02']))
        ));
        $col = array_flip($rows[0]);
        $running = $col[__('client.running_balance')];

        // الهيدر + رصيد سابق + حركتين + الإجماليات
        $this->assertCount(5, $rows);
        $this->assertSame(__('client.previous_balance'), $rows[1][$col[__('client.memo')]]);
        $this->assertSame('1000.00', $rows[1][$running]);
        $this->assertSame('500.00', $rows[3][$running]);
    }

    public function test_a_branch_from_another_chain_is_not_served_under_this_chain(): void
    {
        [$group] = $this->chainWithTwoBranches();
        $stranger = $this->makeClient(['name' => 'عميل بره السلسلة']);
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get(route('erp.groups.branch_statement', [$group, $stranger]))
            ->assertNotFound();
    }

    /** ⚠️ فلترة القايمة مش حماية — الراوت نفسه لازم يرفض فرع بره السكوب */
    public function test_a_manager_cannot_pull_a_statement_outside_their_scope(): void
    {
        [$group, $a] = $this->chainWithTwoBranches();
        $mgrA = $this->makeAdmin(['role' => 'manager', 'email' => 'mgr.a@test.local']);
        $mgrB = $this->makeAdmin(['role' => 'manager', 'email' => 'mgr.b@test.local']);
        $a->update(['manager_id' => $mgrA->id]);

        $this->actingAs($mgrB)
            ->get(route('erp.groups.branch_statement', [$group, $a]))
            ->assertForbidden();
    }

    // ═══════════════ 4. الزراير على الشاشة ═══════════════

    public function test_the_screens_carry_the_export_buttons(): void
    {
        [$group, $a] = $this->chainWithTwoBranches();
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->get(route('erp.groups'))
            ->assertOk()
            ->assertSee(route('erp.groups.export'), false);

        $this->actingAs($admin)->get(route('erp.groups.show', $group))
            ->assertOk()
            ->assertSee(route('erp.groups.statements', $group), false)
            ->assertSee(route('erp.groups.branch_statement', [$group, $a]), false);
    }
}
