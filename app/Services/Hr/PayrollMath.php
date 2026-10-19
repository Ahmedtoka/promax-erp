<?php

namespace App\Services\Hr;

/**
 * حسابات المرتبات الصافية (Lane 7 — المهمة ٤) — دوال static من غير DB.
 * السعر والشرايح بتيجي من برّه (`HrSettings`) عشان تتختبر بأرقام ثابتة.
 */
final class PayrollMath
{
    /**
     * التأمينات: الأجر بيتقص على [min, max] وكل حصة متقرّبة لقرشين.
     *
     * @return array{wage: float, employee: float, employer: float}
     */
    public static function insurance(float $wage, float $empRate, float $erRate, float $min, float $max): array
    {
        $w = max($min, min($max, $wage));

        return [
            'wage' => (float) $w,
            'employee' => round($w * $empRate, 2),
            'employer' => round($w * $erRate, 2),
        ];
    }

    /**
     * ضريبة سنوية تصاعدية على الوعاء بعد الإعفاء.
     *
     * الإلغاء: لما الوعاء > `over` كل الدخل من الصفر لحد آخر الشريحة `upto`
     * بيتحسب بسعر الشريحة دي (أعلى `over` منطبق بيكسب)، واللي فوقها عادي.
     *
     * @param  list<array{0: float|int, 1: float|int|null, 2: float|int}>  $brackets
     * @param  list<array{0: float|int, 1: int}>  $elimination
     */
    public static function annualTax(float $annualTaxable, array $brackets, array $elimination): float
    {
        if ($annualTaxable <= 0) {
            return 0.0;
        }

        $upto = -1;
        $bestOver = null;
        foreach ($elimination as [$over, $idx]) {
            if ($annualTaxable > (float) $over && ($bestOver === null || (float) $over > $bestOver)) {
                $bestOver = (float) $over;
                $upto = (int) $idx;
            }
        }
        $flatRate = isset($brackets[$upto]) ? (float) $brackets[$upto][2] : null;

        $tax = 0.0;
        foreach (array_values($brackets) as $i => [$from, $to, $rate]) {
            $from = (float) $from;
            if ($annualTaxable <= $from) {
                break;
            }
            $top = $to === null ? $annualTaxable : min($annualTaxable, (float) $to);
            $r = ($flatRate !== null && $i <= $upto) ? $flatRate : (float) $rate;
            $tax += ($top - $from) * $r;
        }

        return $tax <= 0 ? 0.0 : round($tax, 2);
    }

    /** ضريبة الشهر = ضريبة (الشهري × ١٢ − الإعفاء) ÷ ١٢. */
    public static function monthlyTax(float $monthlyTaxable, array $brackets, array $elimination, float $exemption): float
    {
        $annual = max($monthlyTaxable * 12 - $exemption, 0);

        return round(self::annualTax($annual, $brackets, $elimination) / 12, 2);
    }

    /**
     * أصغر إجمالي G (قرشين) يحقق `G − التأمين − الضريبة(G − التأمين) ≥ الصافي`.
     * بايسكشن ٦٠ لفة على [net, net×2 + التأمين + 1] وبعدين تقريب لفوق.
     *
     * @param  callable(float): float  $taxOf  ضريبة الشهر على الوعاء الخاضع
     */
    public static function grossUp(float $net, float $employeeInsurance, callable $taxOf): float
    {
        $ok = function (float $g) use ($net, $employeeInsurance, $taxOf): bool {
            $taxable = $g - $employeeInsurance;

            // ⚠️ هامش ميكروسكوبي عشان أخطاء الفاصلة العايمة
            return $taxable - (float) $taxOf($taxable) >= $net - 1e-9;
        };

        $lo = $net;
        $hi = $net * 2 + $employeeInsurance + 1;
        if ($ok($lo)) {
            return round(ceil(round($lo * 100, 6)) / 100, 2);
        }

        for ($i = 0; $i < 60; $i++) {
            $mid = ($lo + $hi) / 2;
            if ($ok($mid)) {
                $hi = $mid;
            } else {
                $lo = $mid;
            }
        }

        $g = ceil(round($hi * 100, 6)) / 100;
        // تقريب الضريبة لقرشين ممكن يخلّي الدالة مش متصاعدة بالظبط — نزوّد قرش لحد ما تتحقق
        for ($n = 0; $n < 100 && ! $ok($g); $n++) {
            $g += 0.01;
        }

        return round($g, 2);
    }
}
