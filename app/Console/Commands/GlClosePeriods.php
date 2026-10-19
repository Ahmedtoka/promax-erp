<?php

namespace App\Console\Commands;

use App\Models\Gl\GlPeriod;
use App\Models\Setting;
use App\Services\Gl\RebuildDiff;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * ═══ سياسة قفل الشهور (الخط ٣ — D11) ═══
 *
 * مابيعملش حاجة إلا لو `gl_close_policy = auto` و`gl_start_date` متحدد.
 * بيقفل كل شهر من شهر البداية لقدام آخر يوم فيه + `gl_close_after_days`
 * (افتراضي ١٠) أقدم من النهارده. **عمره ما بيقفل شهر قبل شهر البداية.**
 *
 * ⚠️ الشهر اللي اتفتح بالإيد (`reopened_at` مش فاضي) مابيتقفلش تاني
 * أوتوماتيك — وإلا المحاسب يفتحه للتسوية والكرون يقفله بالليل.
 *
 *   php artisan promax:gl-close-periods --dry-run
 */
class GlClosePeriods extends Command
{
    protected $signature = 'promax:gl-close-periods {--dry-run : Show only, write nothing}';

    protected $description = 'Close GL months automatically when the close policy is auto';

    public function handle(): int
    {
        $policy = Setting::read('gl_close_policy') ?: 'manual';
        $start = Setting::read('gl_start_date');
        // ⚠️ قبل التشغيل الفعلي (الدفتر شغال + تنفيذ إعادة بناء ناجح) القفل
        // كان هيجمّد شهور لسه قيودها ماتبنتش
        if ($policy !== 'auto' || ! $start || Setting::read('gl_enabled') !== '1' || ! RebuildDiff::executed()) {
            $this->info('Policy is not auto, no start date, or the ledger has not gone live yet - nothing to do.');

            return self::SUCCESS;
        }

        $after = max(0, (int) (Setting::read('gl_close_after_days') ?: 10));
        $today = today();
        $month = Carbon::parse($start)->startOfMonth();
        $due = [];
        while ($month->copy()->endOfMonth()->startOfDay()->addDays($after)->lt($today)) {
            $due[] = $month->format('Y-m');
            $month->addMonthNoOverflow();
        }

        $existing = GlPeriod::whereIn('key', $due)->get()->keyBy('key');
        $closed = [];
        foreach ($due as $key) {
            $p = $existing[$key] ?? null;
            if ($p && ($p->status === 'closed' || $p->reopened_at !== null)) {
                continue;
            }
            $closed[] = $key;
            if (! $this->option('dry-run')) {
                GlPeriod::updateOrCreate(['key' => $key], ['status' => 'closed', 'closed_at' => now(), 'closed_by' => null]);
            }
        }

        $this->info(($this->option('dry-run') ? 'DRY RUN - would close: ' : 'Closed: ').($closed === [] ? '-' : implode(', ', $closed)));

        return self::SUCCESS;
    }
}
