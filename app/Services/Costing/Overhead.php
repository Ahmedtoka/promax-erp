<?php

namespace App\Services\Costing;

use App\Models\CostCenter;
use App\Models\CostMonthLine;
use App\Models\Expense;
use App\Models\FixedAssetDepreciation;
use App\Models\PayrollRun;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductSpec;
use App\Services\Hr\PayrollCost;
use App\Services\Mfg\LineHours;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * ═══ تحميل التكاليف غير المباشرة على الإنتاج (الخط ٩ — D7/D8) ═══
 *
 * عند القفل (`forClosing`):
 *   • ساعات متسجلة (> ٠) + معدل تقديري للخط ← ساعات × المعدل (`hours_estimate`).
 *     المعدل التقديري = متوسط `cost_month_lines.rate` للخط في آخر ٣ شهور
 *     تكاليف مقفولة قبل شهر الأمر ليها معدل.
 *   • مفيش ساعات ← كيلو × `cost_default_kg_rate` وعلامة `no_hours`
 *     (Review Focus 3). ساعات من غير تاريخ ← نفس الكيلو وعلامة `no_history`.
 *   • السعر الافتراضي فاضي/صفر ← تحميل صفر وعلامة `no_rate` — من غير خطأ.
 *   • الوزن: مواصفة معتمدة `net_weight_g`، وإلا `net_content`/`net_uom` للصنف،
 *     وإلا `cost_unit_kg_fallback` للوحدة وعلامة `no_weight`.
 *   ⚠️ مفيش قسمة على صفر ولا استثناء في أي فرع.
 *
 * الأحواض الشهرية (`pools`) من المستندات مش من الدفتر (بتشتغل والدفتر مقفول):
 * مصروفات مرحّلة على الخط (المباشر) + حوض المصنع (مصروفاته + مرتباته +
 * إهلاكه) موزّع على خطوطه بالساعات، وإلا بكيلو الإنتاج، وإلا بيفضل على
 * المصنع (`factory_unshared`) ويتقيّد انحراف على مركز المصنع وقت إقفال الشهر.
 * بند نوعه `asset` مستبعد (بيتهلك)، و`payroll` بس لو مفيش مسير معتمد للشهر.
 */
final class Overhead
{
    public const METHODS = ['hours_estimate', 'kg_default'];

    public const FLAGS = ['no_hours', 'no_history', 'no_rate', 'no_weight'];

    /**
     * @return array{amount:float, method:string, flag:?string, rate:?float, kg:?float}
     */
    public static function forClosing(ProductionOrder $o, int $outputQty): array
    {
        $product = $o->product()->first();
        $w = $product !== null ? self::kgFor($product, $outputQty) : ['kg' => 0.0, 'flag' => 'no_weight'];
        $kg = $w['kg'];
        $hours = LineHours::forOrder($o);
        $month = Carbon::parse($o->produced_on ?? today())->startOfMonth();
        $line = $o->line_id ? CostCenter::find($o->line_id) : null;

        $flags = [];
        if ($hours !== null && $hours > 0) {
            $rate = $line !== null ? self::estimateRate($line, $month) : null;
            if ($rate !== null && $rate > 0) {
                return ['amount' => round($hours * $rate, 2), 'method' => 'hours_estimate', 'flag' => null,
                    'rate' => $rate, 'kg' => $kg];
            }
            $flags[] = 'no_history';
        } else {
            $flags[] = 'no_hours';
        }

        $default = CostingSettings::defaultKgRate();
        if ($default === null) {
            $flags[] = 'no_rate';
        }
        if ($w['flag'] !== null) {
            $flags[] = $w['flag'];
        }

        return [
            'amount' => $default === null ? 0.0 : round($kg * $default, 2),
            'method' => 'kg_default',
            'flag' => implode(',', $flags),
            'rate' => $default,
            'kg' => $kg,
        ];
    }

    /**
     * وزن الإنتاج بالكيلو: مواصفة معتمدة ← محتوى الصنف الصافي (g/ml ÷ ١٠٠٠،
     * kg/l × ١) ← الافتراضي للوحدة + `no_weight`.
     *
     * @return array{kg:float, flag:?string}
     */
    public static function kgFor(Product $p, int $qty): array
    {
        $spec = ProductSpec::approvedFor($p);
        if ($spec !== null && (float) $spec->net_weight_g > 0) {
            return ['kg' => round($qty * (float) $spec->net_weight_g / 1000, 3), 'flag' => null];
        }

        $content = (float) ($p->net_content ?? 0);
        if ($content > 0) {
            $factor = match (strtolower(trim((string) ($p->net_uom ?: 'g')))) {
                'g', 'gm', 'gr', 'ml' => 0.001,
                'kg', 'l', 'lt', 'ltr' => 1.0,
                default => null,
            };
            if ($factor !== null) {
                return ['kg' => round($qty * $content * $factor, 3), 'flag' => null];
            }
        }

        return ['kg' => round($qty * CostingSettings::unitKgFallback(), 3), 'flag' => 'no_weight'];
    }

    /** متوسط معدل الساعة الفعلي للخط في آخر ٣ شهور مقفولة (ليها معدل) قبل الشهر ده */
    public static function estimateRate(CostCenter $line, CarbonInterface $month): ?float
    {
        $rates = CostMonthLine::query()
            ->join('cost_months as m', 'm.id', '=', 'cost_month_lines.cost_month_id')
            ->where('cost_month_lines.line_id', $line->id)
            ->whereNotNull('cost_month_lines.rate')
            ->where('m.status', 'closed')
            ->where('m.period', '<', $month->format('Y-m'))
            ->orderByDesc('m.period')
            ->limit(3)
            ->pluck('cost_month_lines.rate')
            ->map(fn ($r) => (float) $r);

        return $rates->isEmpty() ? null : round($rates->avg(), 4);
    }

    /**
     * @return array<int|string, mixed> line_id ⇒ array{line:CostCenter, direct:float, shared:float, payroll:float,
     *   depreciation:float, pool:float, hours:float, output_kg:float, applied:float, rate:?float}
     *   + `'factory_unshared'` ⇒ float
     */
    public static function pools(CarbonInterface $month): array
    {
        $b = self::breakdown($month);

        return $b['lines'] + ['factory_unshared' => round(array_sum(array_column($b['unshared'], 'pool')), 2)];
    }

    /**
     * الحساب الكامل: أحواض الخطوط + اللي فضل على كل مصنع (لإقفال الشهر).
     *
     * @return array{lines: array<int, array<string, mixed>>, unshared: array<int, array{center:CostCenter, direct:float, payroll:float, depreciation:float, pool:float}>}
     */
    public static function breakdown(CarbonInterface $month): array
    {
        $from = Carbon::parse($month)->startOfMonth();
        $to = $from->copy()->endOfMonth();

        $factories = CostCenter::where('kind', 'factory')->orderBy('id')->get();
        $lines = CostCenter::where('kind', 'line')->orderBy('id')->get();
        $ids = $factories->pluck('id')->merge($lines->pluck('id'))->all();

        $expenses = self::expensesByCenter($ids, $from, $to);
        $payroll = PayrollCost::forMonth(\Carbon\Carbon::parse($from));
        $deprec = self::depreciationByCenter($ids, $from, $to);

        $out = [];
        foreach ($lines as $line) {
            [$applied, $kg] = self::appliedAndKg($line, $from, $to);
            $out[$line->id] = [
                'line' => $line,
                'direct' => $expenses[$line->id] ?? 0.0,
                'shared' => 0.0,
                'payroll' => round((float) ($payroll[$line->id]['total'] ?? 0), 2),
                'depreciation' => $deprec[$line->id] ?? 0.0,
                'pool' => 0.0,
                'hours' => LineHours::forMonth($line, $from),
                'output_kg' => $kg,
                'applied' => $applied,
                'rate' => null,
            ];
        }

        $unshared = [];
        foreach ($factories as $f) {
            $parts = [
                'direct' => $expenses[$f->id] ?? 0.0,
                'payroll' => round((float) ($payroll[$f->id]['total'] ?? 0), 2),
                'depreciation' => $deprec[$f->id] ?? 0.0,
            ];
            $pool = round(array_sum($parts), 2);
            if ($pool == 0.0) {
                continue;
            }
            $mine = $lines->where('parent_id', $f->id)->pluck('id')->all();
            $basis = self::shareBasis($out, $mine);
            if ($basis === null) {
                $unshared[$f->id] = ['center' => $f] + $parts + ['pool' => $pool];

                continue;
            }
            foreach (self::split($pool, $basis) as $lineId => $amount) {
                $out[$lineId]['shared'] = round($out[$lineId]['shared'] + $amount, 2);
            }
        }

        foreach ($out as $id => $row) {
            $pool = round($row['direct'] + $row['shared'] + $row['payroll'] + $row['depreciation'], 2);
            $out[$id]['pool'] = $pool;
            $out[$id]['rate'] = $row['hours'] > 0 ? round($pool / $row['hours'], 4) : null;
        }

        return ['lines' => $out, 'unshared' => $unshared];
    }

    /**
     * أساس توزيع حوض المصنع على خطوطه: الساعات، وإلا كيلو الإنتاج، وإلا null.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  list<int>  $lineIds
     * @return array<int, float>|null
     */
    private static function shareBasis(array $rows, array $lineIds): ?array
    {
        foreach (['hours', 'output_kg'] as $k) {
            $basis = [];
            foreach ($lineIds as $id) {
                $basis[$id] = (float) $rows[$id][$k];
            }
            if (array_sum($basis) > 0) {
                return $basis;
            }
        }

        return null;
    }

    /**
     * توزيع نسبي بالقرش — الباقي على آخر خط عشان المجموع يطلع بالظبط.
     *
     * @param  array<int, float>  $basis
     * @return array<int, float>
     */
    private static function split(float $amount, array $basis): array
    {
        $total = array_sum($basis);
        $out = [];
        $left = $amount;
        $keys = array_keys(array_filter($basis, fn ($v) => $v > 0));
        $last = end($keys);
        foreach ($keys as $id) {
            $share = $id === $last ? round($left, 2) : round($amount * $basis[$id] / $total, 2);
            $out[$id] = $share;
            $left -= $share;
        }

        return $out;
    }

    /**
     * مصروفات مرحّلة في الشهر على المراكز دي — `asset` مستبعد، و`payroll` بس
     * لو مفيش مسير معتمد/مدفوع للشهر (D7). من غير بند = تشغيلي.
     *
     * @param  list<int>  $ids
     * @return array<int, float>
     */
    private static function expensesByCenter(array $ids, Carbon $from, Carbon $to): array
    {
        if ($ids === []) {
            return [];
        }
        $runApproved = PayrollRun::where('period', $from->format('Y-m'))->whereIn('status', ['approved', 'paid'])->exists();
        $excluded = $runApproved ? ['asset', 'payroll'] : ['asset'];

        return Expense::query()
            ->leftJoin('expense_categories as c', 'c.id', '=', 'expenses.category_id')
            ->where('expenses.status', 'posted')
            ->whereBetween('expenses.date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('expenses.cost_center_id', $ids)
            ->where(fn ($q) => $q->whereNull('c.type')->orWhereNotIn('c.type', $excluded))
            ->groupBy('expenses.cost_center_id')
            ->selectRaw('expenses.cost_center_id as center, COALESCE(SUM(expenses.amount),0) as amt')
            ->pluck('amt', 'center')
            ->map(fn ($v) => round((float) $v, 2))
            ->all();
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, float>
     */
    private static function depreciationByCenter(array $ids, Carbon $from, Carbon $to): array
    {
        if ($ids === []) {
            return [];
        }

        return FixedAssetDepreciation::query()
            ->join('fixed_assets as a', 'a.id', '=', 'fixed_asset_depreciations.fixed_asset_id')
            ->whereBetween('fixed_asset_depreciations.date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('a.cost_center_id', $ids)
            ->groupBy('a.cost_center_id')
            ->selectRaw('a.cost_center_id as center, COALESCE(SUM(fixed_asset_depreciations.amount),0) as amt')
            ->pluck('amt', 'center')
            ->map(fn ($v) => round((float) $v, 2))
            ->all();
    }

    /**
     * المحمّل (Σ `overhead_cost`) وكيلو الإنتاج لأوامر الخط اللي اتقفلت في الشهر.
     *
     * @return array{0:float, 1:float}
     */
    private static function appliedAndKg(CostCenter $line, Carbon $from, Carbon $to): array
    {
        $orders = ProductionOrder::with('product')->where('line_id', $line->id)->where('status', 'closed')
            ->whereBetween('closed_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->get(['id', 'product_id', 'output_qty', 'overhead_cost']);
        $applied = 0.0;
        $kg = 0.0;
        foreach ($orders as $o) {
            $applied += (float) $o->overhead_cost;
            if ($o->product !== null && (int) $o->output_qty > 0) {
                $kg += self::kgFor($o->product, (int) $o->output_qty)['kg'];
            }
        }

        return [round($applied, 2), round($kg, 3)];
    }
}
