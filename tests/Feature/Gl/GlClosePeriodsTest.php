<?php

namespace Tests\Feature\Gl;

use App\Models\Gl\GlPeriod;
use App\Models\Gl\GlRebuildRun;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** سياسة القفل التلقائي للشهور (الخط ٣ — D11) */
class GlClosePeriodsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function settings(string $policy, string $start = '2026-07-15', ?string $after = null, bool $live = true): void
    {
        if ($live) {
            Setting::write('gl_enabled', '1');
            if (! GlRebuildRun::where('mode', 'execute')->exists()) {
                GlRebuildRun::create(['mode' => 'execute', 'from_date' => $start, 'status' => 'ok']);
            }
        }
        Setting::write('gl_close_policy', $policy);
        Setting::write('gl_start_date', $start);
        if ($after !== null) {
            Setting::write('gl_close_after_days', $after);
        }
        Setting::flushCache();
    }

    public function test_manual_policy_closes_nothing(): void
    {
        Carbon::setTestNow('2026-10-19 02:20:00');
        $this->settings('manual');

        $this->artisan('promax:gl-close-periods')->assertSuccessful();

        $this->assertSame(0, GlPeriod::count());
    }

    public function test_auto_policy_closes_due_months_but_never_before_start(): void
    {
        Carbon::setTestNow('2026-10-19 02:20:00');
        $this->settings('auto');
        // يوليو اتقفل وبعدين اتفتح بالإيد — مايتقفلش تاني
        GlPeriod::create(['key' => '2026-06', 'status' => 'open']);

        $this->artisan('promax:gl-close-periods')->assertSuccessful();

        // سبتمبر: ٣٠/٩ + ١٠ = ١٠/١٠ < ١٩/١٠ → يتقفل · أكتوبر لأ
        $this->assertSame(['2026-07', '2026-08', '2026-09'],
            GlPeriod::where('status', 'closed')->orderBy('key')->pluck('key')->all());
        $this->assertSame('open', GlPeriod::where('key', '2026-06')->value('status'));

        // شهر اتفتح بالإيد بعد القفل مايرجعش يتقفل
        GlPeriod::where('key', '2026-08')->update(['status' => 'open', 'reopened_at' => now()]);
        $this->artisan('promax:gl-close-periods')->assertSuccessful();
        $this->assertSame('open', GlPeriod::where('key', '2026-08')->value('status'));

        // after-days أكبر = سبتمبر لسه مش مستحق
        GlPeriod::query()->delete();
        $this->settings('auto', '2026-07-15', '20');
        $this->artisan('promax:gl-close-periods')->assertSuccessful();
        $this->assertSame(['2026-07', '2026-08'], GlPeriod::where('status', 'closed')->orderBy('key')->pluck('key')->all());
    }

    public function test_auto_policy_waits_for_go_live(): void
    {
        Carbon::setTestNow('2026-10-19 02:20:00');
        $this->settings('auto', live: false);

        $this->artisan('promax:gl-close-periods')->assertSuccessful();
        $this->assertSame(0, GlPeriod::count());

        // الدفتر شغال بس لسه مفيش تنفيذ إعادة بناء = برضه ولا حاجة
        Setting::write('gl_enabled', '1');
        Setting::flushCache();
        $this->artisan('promax:gl-close-periods')->assertSuccessful();
        $this->assertSame(0, GlPeriod::count());
    }

    public function test_dry_run_writes_nothing(): void
    {
        Carbon::setTestNow('2026-10-19 02:20:00');
        $this->settings('auto');

        $this->artisan('promax:gl-close-periods', ['--dry-run' => true])
            ->expectsOutputToContain('2026-09')
            ->assertSuccessful();

        $this->assertSame(0, GlPeriod::count());
    }
}
