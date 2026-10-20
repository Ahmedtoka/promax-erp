<?php

namespace App\Services\Costing;

use App\Exceptions\Rejected;
use App\Models\CostEntry;
use App\Models\CostMonth;
use App\Models\CostMonthLine;
use App\Models\Gl\GlPeriod;
use App\Models\User;
use App\Services\Gl\Ledger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ═══ إقفال شهر التكاليف (الخط ٩ — D9) ═══
 *
 * بيخزّن الأحواض الفعلية والساعات والمعدل لكل خط (`cost_month_lines`) ويرحّل
 * قيد `variance` واحد لكل خط = الحوض − المحمّل (Σ `overhead_cost` لأوامر
 * الخط اللي اتقفلت في الشهر)، بتاريخ آخر يوم في الشهر.
 *
 * ⚠️ حوض مصنع ماتوزّعش (خطوطه من غير ساعات ولا إنتاج في الشهر) بيتخزّن
 * كسطر شهر `line_id` = **مركز المصنع نفسه** (المحمّل صفر، الانحراف = الحوض)،
 * وقيد الانحراف بتاعه على مركز المصنع.
 * ⚠️ الأوامر المقفولة والباتشات اللي اتحركت عمرها ما بتتحسب تاني — الانحراف
 * بيتقيّد في الشهر بس. شهر مقفول مابيتقفلش تاني.
 * الرفض: الشهر الحالي أو بعده (`costing.month_not_past`)، مقفول قبل كده
 * (`costing.month_closed`)، شهر الدفتر مقفول (`gl.period_closed`).
 */
final class CostMonths
{
    public static function close(string $period, User $by): CostMonth
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            throw new Rejected(__('costing.month_not_past'));
        }
        $month = Carbon::createFromFormat('Y-m-d', $period.'-01')->startOfDay();
        if ($month->gte(today()->startOfMonth())) {
            throw new Rejected(__('costing.month_not_past'));
        }
        if (CostMonth::where('period', $period)->where('status', 'closed')->exists()) {
            throw new Rejected(__('costing.month_closed'));
        }
        if (GlPeriod::isClosed($month)) {
            throw new Rejected(__('gl.period_closed', ['period' => $period]));
        }

        // سباق قفل نفس الشهر مرتين: المفتاح الفريد على `period` يبقى رفض ودّي مش ٥٠٠
        try {
            return self::closeInTransaction($period, $month, $by);
        } catch (QueryException $e) {
            throw self::friendlyUniqueViolation($e) ?? $e;
        }
    }

    /** تكرار المفتاح الفريد لـ`cost_months.period` بس (1062 + اسم الفهرس) = رفض ودّي، وغيره null */
    public static function friendlyUniqueViolation(QueryException $e): ?Rejected
    {
        if ((int) ($e->errorInfo[1] ?? 0) === 1062 && str_contains($e->getMessage(), 'cost_months_period_unique')) {
            return new Rejected(__('costing.month_closed'));
        }

        return null;
    }

    private static function closeInTransaction(string $period, Carbon $month, User $by): CostMonth
    {
        return DB::transaction(function () use ($period, $month, $by) {
            if (CostMonth::where('period', $period)->lockForUpdate()->where('status', 'closed')->exists()) {
                throw new Rejected(__('costing.month_closed'));
            }
            $date = $month->copy()->endOfMonth()->toDateString();
            $b = Overhead::breakdown($month);

            $cm = CostMonth::updateOrCreate(['period' => $period], [
                'status' => 'closed', 'closed_by' => $by->id, 'closed_at' => now(),
            ]);

            $entries = [];
            $totals = ['pool' => 0.0, 'applied' => 0.0, 'variance' => 0.0, 'unshared' => 0.0];
            foreach ($b['lines'] as $lineId => $r) {
                $variance = round($r['pool'] - $r['applied'], 2);
                $ml = CostMonthLine::create([
                    'cost_month_id' => $cm->id, 'line_id' => $lineId,
                    'direct' => $r['direct'], 'shared' => $r['shared'], 'payroll' => $r['payroll'],
                    'depreciation' => $r['depreciation'], 'pool' => $r['pool'], 'applied' => $r['applied'],
                    'variance' => $variance, 'hours' => $r['hours'], 'output_kg' => $r['output_kg'], 'rate' => $r['rate'],
                ]);
                $entries[] = self::varianceEntry($ml, $lineId, $variance, $date);
                $totals['pool'] += $r['pool'];
                $totals['applied'] += $r['applied'];
                $totals['variance'] += $variance;
            }
            foreach ($b['unshared'] as $centerId => $u) {
                $ml = CostMonthLine::create([
                    'cost_month_id' => $cm->id, 'line_id' => $centerId,
                    'direct' => $u['direct'], 'shared' => 0, 'payroll' => $u['payroll'],
                    'depreciation' => $u['depreciation'], 'pool' => $u['pool'], 'applied' => 0,
                    'variance' => $u['pool'], 'hours' => 0, 'output_kg' => 0, 'rate' => null,
                ]);
                $entries[] = self::varianceEntry($ml, $centerId, $u['pool'], $date);
                $totals['pool'] += $u['pool'];
                $totals['variance'] += $u['pool'];
                $totals['unshared'] += $u['pool'];
            }

            $cm->forceFill(['summary' => array_map(fn ($v) => round($v, 2), $totals)])->save();

            $ledger = app(Ledger::class);
            foreach ($entries as $e) {
                $ledger->post($e, $by);
            }

            return $cm->load('lines');
        });
    }

    private static function varianceEntry(CostMonthLine $ml, int $centerId, float $variance, string $date): CostEntry
    {
        return CostEntry::create([
            'kind' => 'variance',
            'origin_type' => $ml->getMorphClass(),
            'origin_id' => $ml->id,
            'date' => $date,
            'cost_center_id' => $centerId,
            'other_center_id' => null,
            'qty' => 0,
            'revenue' => 0,
            'amount_batch' => $variance,
            'amount_wavg' => $variance,
            'amount_transfer' => null,
            'materials' => 0,
            'overhead' => $variance,
            'flags' => null,
        ]);
    }
}
