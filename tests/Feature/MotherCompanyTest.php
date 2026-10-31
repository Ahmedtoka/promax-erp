<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\MotherCompany;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الشركة الأم (٣١/١٠/٢٠٢٦): Mother Company ← السلسلة ← العميل.
 */
class MotherCompanyTest extends TestCase
{
    use RefreshDatabase;

    private function chain(string $name, ?MotherCompany $m = null): ClientGroup
    {
        return ClientGroup::create([
            'code' => 'G-'.strtoupper(uniqid()), 'name' => $name, 'name_en' => $name,
            'mother_company_id' => $m?->id, 'active' => true,
        ]);
    }

    private function mother(string $name = 'Circle K'): MotherCompany
    {
        return MotherCompany::create(['code' => 'M-'.strtoupper(uniqid()), 'name' => $name, 'name_en' => $name, 'active' => true]);
    }

    public function test_admin_creates_a_mother_company(): void
    {
        $this->actingAs($this->makeAdmin())
            ->post(route('erp.mother_companies.store'), ['name' => 'سيركل كيه', 'name_en' => 'Circle K'])
            ->assertRedirect();

        $m = MotherCompany::firstWhere('name_en', 'Circle K');
        $this->assertSame('CIRCLE-K', $m->code);
        $this->assertTrue($m->active);
    }

    public function test_page_shows_each_chain_with_its_clients_and_totals(): void
    {
        $m = $this->mother();
        $tmt = $this->chain('TMT', $m);
        $this->chain('Trade Top', $m);
        $this->chain('Other Chain');

        $this->makeClient(['name' => 'TMT Branch', 'name_en' => 'TMT Branch', 'group_id' => $tmt->id, 'purchases' => 1000, 'balance' => 400]);
        $this->makeClient(['name' => 'TMT Second', 'name_en' => 'TMT Second', 'group_id' => $tmt->id, 'purchases' => 500, 'balance' => 100]);

        $this->actingAs($this->makeAdmin())
            ->get(route('erp.mother_companies.show', $m))
            ->assertOk()
            ->assertSee('TMT')->assertSee('Trade Top')
            ->assertSee('TMT Branch')->assertSee('TMT Second')
            ->assertSee('1,500')->assertSee('500');

        $this->actingAs($this->makeAdmin())
            ->get(route('erp.mother_companies'))
            ->assertOk()->assertSee('Circle K')->assertSee('1,500');
    }

    public function test_chains_attach_move_and_detach(): void
    {
        $admin = $this->makeAdmin();
        $a = $this->mother('A');
        $b = $this->mother('B');
        $g1 = $this->chain('One');
        $g2 = $this->chain('Two', $b);

        $this->actingAs($admin)->post(route('erp.mother_companies.attach', $a), [
            'action' => 'attach', 'group_ids' => [$g1->id, $g2->id],
        ])->assertRedirect();

        $this->assertSame($a->id, $g1->fresh()->mother_company_id);
        $this->assertSame($a->id, $g2->fresh()->mother_company_id, 'الضم بينقل السلسلة من شركتها القديمة');

        // فك سلسلة مش تابعة للشركة دي مالوش أثر
        $this->actingAs($admin)->post(route('erp.mother_companies.attach', $b), [
            'action' => 'detach', 'group_ids' => [$g1->id],
        ]);
        $this->assertSame($a->id, $g1->fresh()->mother_company_id);

        $this->actingAs($admin)->post(route('erp.mother_companies.attach', $a), [
            'action' => 'detach', 'group_ids' => [$g1->id],
        ]);
        $this->assertNull($g1->fresh()->mother_company_id);
    }

    public function test_new_chain_from_the_mother_page_returns_there(): void
    {
        $m = $this->mother();

        $this->actingAs($this->makeAdmin())->post(route('erp.groups.store'), [
            'name' => 'New Chain', 'mother_company_id' => $m->id,
        ])->assertRedirect(route('erp.mother_companies.show', $m));

        $this->assertSame($m->id, ClientGroup::firstWhere('name', 'New Chain')->mother_company_id);
    }

    public function test_company_with_chains_is_suspended_not_deleted(): void
    {
        $admin = $this->makeAdmin();
        $m = $this->mother();
        $g = $this->chain('Stay', $m);

        $this->actingAs($admin)->delete(route('erp.mother_companies.destroy', $m));
        $this->assertFalse($m->fresh()->active);
        $this->assertSame($m->id, $g->fresh()->mother_company_id);

        $empty = $this->mother('Empty');
        $this->actingAs($admin)->delete(route('erp.mother_companies.destroy', $empty));
        $this->assertNull(MotherCompany::find($empty->id));
    }

    public function test_export_has_a_row_per_client_like_the_sheet(): void
    {
        $m = $this->mother();
        $g = $this->chain('TMT', $m);
        $this->makeClient(['name' => 'TMT Branch', 'name_en' => 'TMT Branch', 'group_id' => $g->id, 'purchases' => 1000]);

        $csv = $this->actingAs($this->makeAdmin())
            ->get(route('erp.mother_companies.export'))
            ->assertOk()->streamedContent();

        $this->assertStringContainsString('Circle K', $csv);
        $this->assertStringContainsString('TMT Branch', $csv);
    }

    public function test_channel_manager_sees_only_own_clients_and_rep_is_blocked(): void
    {
        $m = $this->mother();
        $g = $this->chain('TMT', $m);
        $mgr = $this->makeAdmin(['role' => 'manager']);

        $this->makeClient(['name' => 'Mine', 'name_en' => 'Mine', 'group_id' => $g->id, 'manager_id' => $mgr->id, 'purchases' => 100]);
        $this->makeClient(['name' => 'Theirs', 'name_en' => 'Theirs', 'group_id' => $g->id, 'purchases' => 9000]);

        $this->actingAs($mgr)->get(route('erp.mother_companies.show', $m))
            ->assertOk()->assertSee('Mine')->assertDontSee('Theirs');

        /** @var User $rep */
        $rep = $this->makeAdmin(['role' => 'rep']);
        $this->actingAs($rep)->post(route('erp.mother_companies.store'), ['name' => 'X'])->assertForbidden();
        $this->assertSame(0, MotherCompany::where('name', 'X')->count());
    }

    public function test_branch_moves_from_main_chain_and_leaves_the_unlinked_screen(): void
    {
        $admin = $this->makeAdmin();
        $m = $this->mother();
        $main = ClientGroup::create(['code' => 'CRK', 'name' => 'سيركل كيه', 'name_en' => 'Circle K', 'active' => true]);
        $tmt = $this->chain('Circle K TMT', $m);
        $other = $this->chain('Other Chain');

        $moving = $this->makeClient(['name' => 'Moving Branch', 'name_en' => 'Moving Branch', 'group_id' => $main->id]);
        $this->makeClient(['name' => 'Solo Client', 'name_en' => 'Solo Client']);
        $this->makeClient(['name' => 'Other Branch', 'name_en' => 'Other Branch', 'group_id' => $other->id]);

        // ديالوج الضم بيعرض فروع السلسلة الأساسية والمستقلين، مش فروع سلسلة تانية
        $this->actingAs($admin)->get(route('erp.groups.show', $tmt))
            ->assertOk()->assertSee('Moving Branch')->assertSee('Solo Client')->assertDontSee('Other Branch');

        $this->actingAs($admin)->get(route('erp.groups.unlinked'))
            ->assertOk()->assertSee('Moving Branch')->assertSee('Solo Client')->assertDontSee('Other Branch');
        $this->actingAs($admin)->get(route('erp.groups.unlinked', ['show' => 'none']))
            ->assertOk()->assertDontSee('Moving Branch')->assertSee('Solo Client');

        $this->actingAs($admin)->post(route('erp.groups.attach', $tmt), [
            'action' => 'attach', 'client_ids' => [$moving->id],
        ])->assertRedirect();

        $this->assertSame($tmt->id, $moving->fresh()->group_id);
        $this->assertSame(0, $main->clients()->count());
        $this->actingAs($admin)->get(route('erp.groups.unlinked'))->assertDontSee('Moving Branch');
    }

    public function test_chains_screen_and_export_filter_by_mother_company(): void
    {
        $admin = $this->makeAdmin();
        $m = $this->mother();
        $this->chain('Inside Chain', $m);
        $this->chain('Outside Chain');

        $this->actingAs($admin)->get(route('erp.groups', ['mother' => $m->id]))
            ->assertOk()->assertSee('Inside Chain')->assertDontSee('Outside Chain');

        $this->actingAs($admin)->get(route('erp.groups'))
            ->assertOk()->assertSee('Inside Chain')->assertSee('Outside Chain');

        $csv = $this->actingAs($admin)->get(route('erp.groups.export', ['mother' => $m->id]))->streamedContent();
        $this->assertStringContainsString('Inside Chain', $csv);
        $this->assertStringNotContainsString('Outside Chain', $csv);
    }

    public function test_mother_company_does_not_change_pricing_or_client_chain(): void
    {
        $g = $this->chain('TMT');
        $c = $this->makeClient(['group_id' => $g->id, 'discount' => 0.2]);

        $this->actingAs($this->makeAdmin())->post(route('erp.mother_companies.attach', $this->mother()), [
            'action' => 'attach', 'group_ids' => [$g->id],
        ]);

        $fresh = Client::find($c->id);
        $this->assertSame($g->id, $fresh->group_id);
        $this->assertSame(0.2, $fresh->effectiveDiscount());
    }
}
