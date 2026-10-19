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
     * نقط قفزة الضريبة الشهرية: عند وعاء شهري T بيتحقق `12T − الإعفاء > over`
     * فالضريبة بتقفز (إلغاء الشرايح) — يعني الصافي مش متصاعد عند
     * `T = (over + الإعفاء) / 12`. الـ T دي نفسها لسه في الشريحة الأقل.
     *
     * @return list<float> وعاء شهري (قبل التأمين)
     */
    public static function taxBreaks(array $elimination, float $exemption): array
    {
        return array_values(array_map(fn ($e) => ((float) $e[0] + $exemption) / 12, $elimination));
    }

    /**
     * أصغر إجمالي G (قرشين) يحقق `G − التأمين − الضريبة(G − التأمين) ≥ الصافي`،
     * في المدى [net, net×2 + التأمين + 1].
     *
     * ⚠️ الصافي مش متصاعد: عند كل نقطة إلغاء شرايح الضريبة بتقفز، فبايسكشن
     * واحدة على المدى كله ممكن تقع على حل أكبر من الأصغر (53719 بدل 53274.55).
     * عشان كده المدى بيتقسم عند `$taxableBreaks` (من `taxBreaks()`) لقطع —
     * جوه كل قطعة الصافي متصاعد — والقطع بتتفحص بالترتيب، وأول قطعة آخرها
     * بيحقق الشرط هي اللي فيها الأصغر؛ بايسكشن بالقرش جواها.
     * من غير `$taxableBreaks` = قطعة واحدة (صح لما مفيش إلغاء شرايح).
     *
     * @param  callable(float): float  $taxOf  ضريبة الشهر على الوعاء الخاضع
     * @param  list<float>  $taxableBreaks  وعاء شهري بتقفز عنده الضريبة
     */
    public static function grossUp(float $net, float $employeeInsurance, callable $taxOf, array $taxableBreaks = []): float
    {
        // بالقروش (int) عشان مفيش أخطاء فاصلة عايمة في الحدود
        $ok = function (int $cents) use ($net, $employeeInsurance, $taxOf): bool {
            $taxable = $cents / 100 - $employeeInsurance;

            return round($taxable - (float) $taxOf($taxable), 2) >= round($net, 2);
        };

        $loC = (int) ceil(round($net * 100, 6));
        $hiC = (int) ceil(round(($net * 2 + $employeeInsurance + 1) * 100, 6));

        // آخر قرش في كل قطعة: floor(نقطة القفزة) — لسه في الشريحة الأقل
        $ends = [];
        foreach ($taxableBreaks as $t) {
            $c = (int) floor(round(((float) $t + $employeeInsurance) * 100, 6));
            if ($c >= $loC && $c < $hiC) {
                $ends[] = $c;
            }
        }
        $ends = array_values(array_unique($ends));
        sort($ends);
        $ends[] = $hiC;

        $a = $loC;
        foreach ($ends as $e) {
            if ($e >= $a && $ok($e)) {
                return round(self::firstCent($a, $e, $ok) / 100, 2);
            }
            $a = $e + 1;
        }

        // مفيش حل في المدى (مش متوقع: الضريبة أقل من ٢٧٫٥٪) — نكمّل بالقرش لفوق
        $g = $hiC;
        for ($n = 0; $n < 10000 && ! $ok($g); $n++) {
            $g++;
        }

        return round($g / 100, 2);
    }

    /** أصغر قرش في [a, e] بيحقق الشرط، والمعروف إن `ok(e)` صح والشرط متصاعد جوه القطعة. */
    private static function firstCent(int $a, int $e, callable $ok): int
    {
        if ($ok($a)) {
            return $a;
        }
        $lo = $a;   // مش بيحقق
        $hi = $e;   // بيحقق
        while ($hi - $lo > 1) {
            $mid = intdiv($lo + $hi, 2);
            if ($ok($mid)) {
                $hi = $mid;
            } else {
                $lo = $mid;
            }
        }
        // تقريب الضريبة لقرشين ممكن يعمل رعشة قرش — نتأكد إن اللي قبله فعلاً مش بيحقق
        for ($n = 0; $n < 100 && $hi - 1 >= $a && $ok($hi - 1); $n++) {
            $hi--;
        }

        return $hi;
    }
}
