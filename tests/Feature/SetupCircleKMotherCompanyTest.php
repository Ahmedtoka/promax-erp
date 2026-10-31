<?php

namespace Tests\Feature;

use App\Models\ClientGroup;
use App\Models\Contract;
use App\Models\ContractClause;
use App\Models\MotherCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SetupCircleKMotherCompanyTest extends TestCase
{
    use RefreshDatabase;

    private function source(): ClientGroup
    {
        $g = ClientGroup::create([
            'code' => 'CRK', 'name' => 'سيركل كيه', 'name_en' => 'Circle K',
            'channel_id' => $this->makeChannel()->id, 'sub_channel' => 'chain',
            'notes' => 'ملاحظة', 'active' => true, 'reviewed_at' => '2026-10-12 13:24:43',
        ]);
        $ct = Contract::create([
            'group_id' => $g->id, 'number' => Contract::nextNumber(), 'chain' => 'سيركل كيه',
            'discount' => 0.3, 'withholding_pct' => 0.25, 'payment_days' => 45, 'active' => true,
            'ends_at' => '2026-12-31',
        ]);
        ContractClause::create(['contract_id' => $ct->id, 'kind' => 'rebate', 'label' => 'بند', 'pct' => 0.05, 'basis' => 'annual']);

        return $g;
    }

    public function test_preview_writes_nothing(): void
    {
        $this->source();

        $this->artisan('promax:circle-k-mother')->assertSuccessful();

        $this->assertSame(0, MotherCompany::count());
        $this->assertSame(1, ClientGroup::count());
    }

    public function test_apply_copies_details_and_contract_and_links_only_new_chains(): void
    {
        $source = $this->source();
        $before = $source->fresh()->updated_at;
        $clientsChainBranch = $this->makeClient(['group_id' => $source->id]);

        $this->artisan('promax:circle-k-mother --apply')->assertSuccessful();

        $mother = MotherCompany::where('name', 'Circle K')->sole();
        $this->assertSame(34, $mother->groups()->count());

        $tmt = ClientGroup::where('name', 'Circle K TMT')->sole();
        $this->assertSame($mother->id, $tmt->mother_company_id);
        $this->assertSame($source->channel_id, $tmt->channel_id);
        $this->assertSame('chain', $tmt->sub_channel);
        $this->assertSame('ملاحظة', $tmt->notes);
        $this->assertEquals($source->reviewed_at, $tmt->reviewed_at);

        $copy = $tmt->contract;
        $this->assertSame('Circle K TMT', $copy->chain);
        $this->assertNotSame($source->contract->number, $copy->number);
        $this->assertSame('0.3000', $copy->discount);
        $this->assertSame(45, (int) $copy->payment_days);
        $this->assertSame(1, $copy->contractClauses()->count());

        $this->assertNull($source->fresh()->mother_company_id);
        $this->assertEquals($before, $source->fresh()->updated_at);
        $this->assertSame($source->id, $clientsChainBranch->fresh()->group_id);
        $this->assertSame(35, Contract::whereNotNull('group_id')->distinct()->count('number'));
    }

    public function test_running_twice_adds_nothing_and_existing_chain_keeps_its_details(): void
    {
        $this->source();
        $existing = ClientGroup::create(['code' => 'OLD', 'name' => 'Circle K GT', 'notes' => 'قديمة', 'active' => true]);

        $this->artisan('promax:circle-k-mother --apply')->assertSuccessful();
        $this->artisan('promax:circle-k-mother --apply')->assertSuccessful();

        $this->assertSame(35, ClientGroup::count());
        $this->assertSame(1, MotherCompany::count());
        $this->assertSame('قديمة', $existing->fresh()->notes);
        $this->assertNull($existing->fresh()->contract);
        $this->assertNotNull($existing->fresh()->mother_company_id);
    }

    public function test_refuses_when_source_chain_is_ambiguous(): void
    {
        $this->source();
        ClientGroup::create(['code' => 'CRK2', 'name' => 'Circle K', 'active' => true]);

        $this->artisan('promax:circle-k-mother --apply')->assertFailed();

        $this->assertSame(0, MotherCompany::count());
    }
}
