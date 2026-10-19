<?php

namespace Tests\Feature\Hr;

use App\Services\Hr\HrSettings;
use App\Services\Hr\PayrollMath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * حسابات المرتبات (Lane 7 — المهمة ٤): التأمينات وضريبة كسب العمل والـ gross-up.
 * الأرقام محسوبة باليد على الديفولتات (لازم المالك يراجعها).
 */
class PayrollMathTest extends TestCase
{
    use RefreshDatabase;

    private function brackets(): array
    {
        return HrSettings::json('hr_tax_brackets');
    }

    private function elimination(): array
    {
        return HrSettings::json('hr_tax_elimination');
    }

    public function test_insurance_clamps_to_min_and_max(): void
    {
        $low = PayrollMath::insurance(1000, 0.11, 0.1875, 2700, 16700);
        $this->assertSame(2700.0, $low['wage']);
        $this->assertSame(297.0, $low['employee']);
        $this->assertSame(506.25, $low['employer']);

        $high = PayrollMath::insurance(20000, 0.11, 0.1875, 2700, 16700);
        $this->assertSame(16700.0, $high['wage']);
        $this->assertSame(1837.0, $high['employee']);
        $this->assertSame(3131.25, $high['employer']);

        // الديفولتات نفسها
        $this->assertSame(0.11, HrSettings::num('hr_si_employee_rate'));
        $this->assertSame(0.1875, HrSettings::num('hr_si_employer_rate'));
        $this->assertSame(2700.0, HrSettings::num('hr_si_min_wage'));
        $this->assertSame(16700.0, HrSettings::num('hr_si_max_wage'));
    }

    public function test_annual_tax_progressive(): void
    {
        $this->assertSame(9750.0, PayrollMath::annualTax(100000, $this->brackets(), $this->elimination()));
    }

    public function test_annual_tax_elimination_over_600k(): void
    {
        // > 600000 → كل اللي لحد آخر الشريحة ١ (55000) بـ 10%
        $this->assertSame(141250.0, PayrollMath::annualTax(650000, $this->brackets(), $this->elimination()));
    }

    public function test_monthly_tax_uses_exemption(): void
    {
        $this->assertSame(812.5, PayrollMath::monthlyTax(10000, $this->brackets(), $this->elimination(), HrSettings::num('hr_tax_exemption')));
    }

    public function test_zero_income_zero_tax(): void
    {
        $this->assertSame(0.0, PayrollMath::annualTax(0, $this->brackets(), $this->elimination()));
        $this->assertSame(0.0, PayrollMath::annualTax(-500, $this->brackets(), $this->elimination()));
        $this->assertSame(0.0, PayrollMath::monthlyTax(0, $this->brackets(), $this->elimination(), 20000));
        // تحت الإعفاء + الشريحة الصفرية
        $this->assertSame(0.0, PayrollMath::monthlyTax(5000, $this->brackets(), $this->elimination(), 20000));
    }

    public function test_gross_up_hits_the_net(): void
    {
        $b = $this->brackets();
        $el = $this->elimination();
        $ex = HrSettings::num('hr_tax_exemption');
        $taxOf = fn (float $taxable) => PayrollMath::monthlyTax($taxable, $b, $el, $ex);

        $g = PayrollMath::grossUp(10000, 1100, $taxOf);

        $this->assertGreaterThan(11100, $g);
        $net = $g - 1100 - $taxOf($g - 1100);
        $this->assertEqualsWithDelta(10000.0, $net, 0.01);
        $this->assertGreaterThanOrEqual(10000.0, round($net, 2));
        // أصغر قيمة: قرش أقل بيوقّع الصافي تحت المطلوب
        $less = $g - 0.01;
        $this->assertLessThan(10000.0, round($less - 1100 - $taxOf($less - 1100), 2));
    }

    public function test_settings_banner_until_saved(): void
    {
        $banner = __('hrm.defaults_verify');
        $admin = $this->makeAdmin();
        $hr = $this->makeAdmin(['role' => 'hr', 'email' => 'hr'.uniqid().'@promax.test']);

        $this->actingAs($hr)->get(route('hr.settings'))->assertOk()->assertSee($banner);
        $this->actingAs($admin)->get(route('hr.settings'))->assertOk()->assertSee($banner);

        $payload = [
            'hr_si_employee_rate' => '0.11', 'hr_si_employer_rate' => '0.1875',
            'hr_si_min_wage' => '2700', 'hr_si_max_wage' => '16700',
            'hr_tax_exemption' => '20000',
            'brackets' => [
                ['from' => '0', 'to' => '40000', 'rate' => '0'],
                ['from' => '40000', 'to' => '55000', 'rate' => '0.10'],
                ['from' => '55000', 'to' => '', 'rate' => '0.15'],
            ],
            'elimination' => [
                ['over' => '600000', 'upto' => '1'],
            ],
            'hr_month_days' => '30', 'hr_day_minutes' => '480', 'hr_shift_start' => '09:00',
            'hr_late_grace_minutes' => '15', 'hr_overtime_rate' => '1.35',
        ];

        $this->actingAs($hr)->post(route('hr.settings.save'), $payload)->assertForbidden();
        $this->assertSame('', HrSettings::get('hr_rates_verified_at'));

        $this->actingAs($admin)->post(route('hr.settings.save'), $payload)->assertRedirect();
        $this->assertNotSame('', HrSettings::get('hr_rates_verified_at'));
        $this->assertEquals([[0, 40000, 0], [40000, 55000, 0.1], [55000, null, 0.15]], HrSettings::json('hr_tax_brackets'));
        $this->assertEquals([[600000, 1]], HrSettings::json('hr_tax_elimination'));

        $this->actingAs($admin)->get(route('hr.settings'))->assertOk()->assertDontSee($banner);
        $this->actingAs($hr)->get(route('hr.settings'))->assertOk()->assertDontSee($banner);

        // ترتيب غلط → مرفوض
        $bad = $payload;
        $bad['brackets'][1]['from'] = '30000';
        $this->actingAs($admin)->post(route('hr.settings.save'), $bad)->assertSessionHasErrors();
        $this->assertEquals(55000, HrSettings::json('hr_tax_brackets')[2][0]);
    }
}
