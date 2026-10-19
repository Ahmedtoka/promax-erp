<?php

namespace App\Services\Gl\GoLive\Steps;

use App\Models\Gl\GlAccount;
use App\Models\Gl\GlPostingRule;
use App\Models\Product;
use App\Models\Setting;
use App\Services\Costing\CostingGlSeed;
use App\Services\Costing\CostingSettings;
use App\Services\Gl\GoLive\Step;
use App\Services\Gl\GoLive\StepState;
use App\Services\Gl\RebuildDiff;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ٧ — إعدادات التكاليف (الخط ٩، المهمة ٩ — §3.1.1): طريقة التقييم الرسمية،
 * سعر الكيلو الافتراضي لأول شهور، أساس توزيع مصاريف الفرع، والـseed بتاع
 * حسابات وقواعد التكاليف.
 *
 * أحمر: الـseed ناقص، أو سعر الكيلو فاضي، أو الإعدادات عمرها ما اتحفظت.
 * أصفر: أصناف تامة ليها وصفة معتمدة ومالهاش سعر تحويل ساري النهارده، أو
 * سويتش الدفتر مقفول (معاينة خطوة ٨ من غيره بتطلع فاضية).
 */
class CostingStep implements Step
{
    public function number(): int
    {
        return 7;
    }

    public function key(): string
    {
        return 'costing';
    }

    public function titleKey(): string
    {
        return 'golive.s7_title';
    }

    /** أصناف تامة نشطة ليها وصفة معتمدة ومن غير سعر تحويل ساري النهارده */
    public static function withoutTransferPrice(): Collection
    {
        $today = today()->toDateString();

        return Product::query()->where('kind', Product::KIND_FINISHED)->where('active', true)
            ->whereExists(fn ($q) => $q->from('boms')->whereColumn('boms.product_id', 'products.id')->where('boms.status', 'approved'))
            ->whereNotExists(fn ($q) => $q->from('product_transfer_prices')
                ->whereColumn('product_transfer_prices.product_id', 'products.id')
                ->whereDate('product_transfer_prices.effective_from', '<=', $today))
            ->orderBy('name')->get();
    }

    public function state(): StepState
    {
        $red = [];
        if (! CostingGlSeed::ready()) {
            $red[] = __('golive.s7_not_seeded');
        }
        if (CostingSettings::defaultKgRate() === null) {
            $red[] = __('golive.s7_no_rate');
        }
        if (Setting::read('cost_settings_saved_at') === null) {
            $red[] = __('golive.s7_not_saved');
        }
        if ($red !== []) {
            return new StepState('red', $red);
        }

        $yellow = [];
        $n = self::withoutTransferPrice()->count();
        if ($n > 0) {
            $yellow[] = __('golive.s7_no_tp', ['n' => $n]);
        }
        if (Setting::read('gl_enabled') !== '1') {
            $yellow[] = __('golive.s7_gl_off');
        }

        return $yellow === [] ? new StepState('green') : new StepState('yellow', $yellow);
    }

    public function view(): string
    {
        return 'gl.golive.steps.costing';
    }

    public function data(): array
    {
        $values = [];
        foreach (CostingSettings::KEYS as $k) {
            $values[$k] = Setting::read($k);
        }
        $values['cost_method'] = CostingSettings::method();
        $values['cost_branch_alloc'] = CostingSettings::allocBasis();
        $values['cost_unit_kg_fallback'] ??= (string) CostingSettings::unitKgFallback();
        $values['cost_markup_pct'] ??= (string) CostingSettings::markupPct();
        $values['cost_alert_pct'] ??= (string) CostingSettings::alertPct();

        return [
            'values' => $values,
            'savedAt' => Setting::read('cost_settings_saved_at'),
            'ready' => CostingGlSeed::ready(),
            'blocker' => CostingGlSeed::blocker(),
            'noTp' => self::withoutTransferPrice(),
        ];
    }

    /**
     * فحص `cost_cogs` (بيتسجّل في `RebuildDiff` من `CostingServiceProvider`):
     * المتوقع = Σ الرسمي (بيع − مرتجع) لقيود التكلفة من تاريخ البداية لو
     * قاعدة `cost.sale` نشطة · الفعلي = رصيد حساب `cogs` من البداية من غير
     * سطور هامش التحويل (خانات `mk_dr`/`mk_cr`). من غير seed الاتنين صفر.
     *
     * @return array{label_key:string, expected:float, actual:float, ok:bool}
     */
    public static function cogsCheck(): array
    {
        $label = 'golive.check_cost_cogs';
        $zero = ['label_key' => $label, 'expected' => 0.0, 'actual' => 0.0, 'ok' => true];
        $start = RebuildDiff::from();
        $cogs = GlAccount::where('system_key', 'cogs')->first();
        if ($start === null || $cogs === null || ! GlPostingRule::where('key', 'cost.sale')->where('active', true)->exists()) {
            return $zero;
        }
        $from = $start->toDateString();
        $col = CostingSettings::method() === 'wavg' ? 'amount_wavg' : 'amount_batch';

        $expected = round((float) DB::table('cost_entries')->whereIn('kind', ['sale', 'return'])->whereDate('date', '>=', $from)
            ->selectRaw("COALESCE(SUM(CASE WHEN kind = 'sale' THEN {$col} ELSE -{$col} END), 0) v")->value('v'), 2);

        $actual = round((float) DB::table('gl_lines')->join('gl_entries', 'gl_entries.id', '=', 'gl_lines.entry_id')
            ->whereIn('gl_lines.account_id', $cogs->subtreeIds())
            ->whereDate('gl_entries.date', '>=', $from)
            ->where(fn ($q) => $q->whereNull('gl_lines.slot')->orWhereNotIn('gl_lines.slot', ['mk_dr', 'mk_cr']))
            ->selectRaw('COALESCE(SUM(gl_lines.debit - gl_lines.credit), 0) v')->value('v'), 2);

        return ['label_key' => $label, 'expected' => $expected, 'actual' => $actual, 'ok' => abs(round($expected - $actual, 2)) <= 0.01];
    }
}
