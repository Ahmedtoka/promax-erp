<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الكاش المتوقع (١٥ سبتمبر ٢٠٢٦) — كالندر استحقاق مديونية العملاء.
 *
 * كل تيست بيحرس قرار: الرصيد بيتوزّع FIFO على أحدث المبيعات (زي
 * `aging()`)، الميعاد من شروط العميل، الكاش مش في الشاشة، اللي بلا شروط
 * له كارت لوحده، والمدير بيشوف فريقه بس.
 */
class CashForecastTest extends TestCase
{
    use RefreshDatabase;

    private function creditClient(array $attrs = []): Client
    {
        return $this->makeClient(array_merge([
            'payment_terms' => Client::PAY_CREDIT,
            'payment_days' => 30,
            'payment_days_from' => Contract::DAYS_FROM_INVOICE,
            'first_activity_at' => today()->subDays(90),
        ], $attrs));
    }

    private function sale(Client $client, float $amount, int $daysAgo, string $memo = 'INV-T'): Transaction
    {
        $t = Transaction::create([
            'client_id' => $client->id,
            'date' => today()->subDays($daysAgo),
            'kind' => 'sale',
            'debit' => $amount,
            'credit' => 0,
            'memo' => $memo,
            'reference' => $memo,
        ]);
        $client->recalculate();

        return $t;
    }

    private function collect(Client $client, float $amount, int $daysAgo): void
    {
        Transaction::create([
            'client_id' => $client->id,
            'date' => today()->subDays($daysAgo),
            'kind' => 'collection',
            'debit' => 0,
            'credit' => $amount,
            'memo' => 'COL',
        ]);
        $client->recalculate();
    }

    public function test_a_credit_sale_lands_on_its_due_date_with_the_open_part_only(): void
    {
        $client = $this->creditClient();
        $this->sale($client, 1000, 10);
        // اتحصّل 400 ⇒ المفتوح 600 على نفس الفاتورة (توزيع من الأحدث)
        $this->collect($client, 400, 2);

        $due = today()->addDays(20)->toDateString();

        $this->actingAs($this->makeAdmin())
            ->get(route('erp.cashflow', ['from' => today()->toDateString(), 'to' => today()->addDays(60)->toDateString()]))
            ->assertOk()
            ->assertSee('data-day="'.$due.'"', false)
            ->assertViewHas('data', function (array $d) use ($due) {
                return $d['rows']->count() === 1
                    && (float) $d['rows'][0]['open'] === 600.0
                    && $d['rows'][0]['due']->toDateString() === $due
                    && (float) $d['days'][$due]['total'] === 600.0
                    && (float) $d['total_open'] === 600.0
                    && (float) $d['overdue']['total'] === 0.0;
            });
    }

    public function test_a_past_due_sale_is_overdue_and_counts_in_every_horizon(): void
    {
        $client = $this->creditClient();
        $this->sale($client, 500, 45);          // مستحق من 15 يوم
        $this->sale($client, 300, 5, 'INV-N');  // مستحق بعد 25 يوم

        $this->actingAs($this->makeAdmin())
            ->get(route('erp.cashflow'))
            ->assertOk()
            ->assertViewHas('data', function (array $d) {
                return (float) $d['overdue']['total'] === 500.0
                    && $d['overdue']['rows'][0]['days_late'] === 15
                    && $d['rows']->count() === 1
                    && (float) $d['horizon'][15] === 500.0          // المتأخر بس
                    && (float) $d['horizon'][30] === 800.0          // + اللي مستحق بعد 25 يوم
                    && (float) $d['horizon'][90] === 800.0;
            });
    }

    public function test_cash_clients_are_excluded_and_credit_without_terms_is_parked(): void
    {
        $cash = $this->makeClient(['payment_terms' => Client::PAY_CASH]);
        $this->sale($cash, 900, 3);

        $noTerms = $this->makeClient(['payment_terms' => Client::PAY_CREDIT, 'payment_days' => null]);
        $this->sale($noTerms, 250, 3);

        $this->actingAs($this->makeAdmin())
            ->get(route('erp.cashflow'))
            ->assertOk()
            ->assertViewHas('data', function (array $d) {
                return $d['rows']->count() === 0
                    && (float) $d['no_terms']['total'] === 250.0
                    && $d['no_terms']['count'] === 1
                    && (float) $d['total_open'] === 250.0;
            });
    }

    public function test_the_first_supply_basis_uses_the_cycle_boundary(): void
    {
        // أول توريد من 40 يوم (فاتورة اتحصّلت بالكامل — `recalculate()` بيكتب
        // `first_activity_at` من أقدم حركة)، دورة 30 يوم ⇒ فاتورة من 5 أيام
        // تستحق في حد الدورة التانية (+60 من أول توريد = بعد 20 يوم)
        $client = $this->creditClient(['payment_days_from' => Contract::DAYS_FROM_FIRST_SUPPLY]);
        $this->sale($client, 500, 40, 'INV-OLD');
        $this->collect($client, 500, 12);
        $this->sale($client, 100, 5);

        $this->actingAs($this->makeAdmin())
            ->get(route('erp.cashflow'))
            ->assertOk()
            ->assertViewHas('data', fn (array $d) => $d['rows']->count() === 1
                && $d['rows'][0]['due']->toDateString() === today()->addDays(20)->toDateString());
    }

    public function test_a_manager_sees_only_their_own_clients_and_field_roles_are_refused(): void
    {
        $manager = $this->makeAdmin(['role' => 'manager']);
        $other = $this->makeAdmin(['role' => 'manager']);

        $mine = $this->creditClient(['manager_id' => $manager->id]);
        $this->sale($mine, 100, 1);
        $theirs = $this->creditClient(['manager_id' => $other->id]);
        $this->sale($theirs, 999, 1);

        $this->actingAs($manager)
            ->get(route('erp.cashflow'))
            ->assertOk()
            ->assertViewHas('data', fn (array $d) => (float) $d['total_open'] === 100.0);

        $this->actingAs($this->makeAdmin(['role' => 'accountant']))
            ->get(route('erp.cashflow'))
            ->assertOk()
            ->assertViewHas('data', fn (array $d) => (float) $d['total_open'] === 1099.0);

        $this->actingAs($this->makeRep())->get(route('erp.cashflow'))->assertForbidden();
    }

    public function test_the_export_mirrors_the_screen(): void
    {
        $client = $this->creditClient();
        $this->sale($client, 1000, 10);

        $res = $this->actingAs($this->makeAdmin())
            ->get(route('erp.cashflow', ['export' => 1]))
            ->assertOk();

        $this->assertStringContainsString('text/csv', $res->headers->get('content-type'));
        $csv = $res->streamedContent();
        $this->assertStringContainsString(today()->addDays(20)->toDateString(), $csv);
        $this->assertStringContainsString('1000.00', $csv);
    }

    public function test_the_client_card_numbers_still_agree_with_the_forecast(): void
    {
        // الشاشة والكارت بيقروا من نفس التوزيع — `aging()` و`overdue()` ماتغيّروش بالريفاكتور
        $client = $this->creditClient();
        $this->sale($client, 500, 45);
        $this->sale($client, 300, 5, 'INV-N');
        $this->collect($client, 200, 1);

        $client->refresh();
        $aging = $client->aging();
        $overdue = $client->overdue();

        $this->assertSame(600.0, round(array_sum($aging), 2));
        // التوزيع من الأحدث: 300 على فاتورة 5 أيام (a30) و300 على فاتورة 45 يوم (a60)
        $this->assertSame(300.0, round($aging['a30'], 2));
        $this->assertSame(300.0, round($aging['a60'], 2));
        $this->assertTrue($overdue['has_terms']);
        $this->assertSame(300.0, $overdue['amount']);
        $this->assertSame(15, $overdue['days']);
    }
}
