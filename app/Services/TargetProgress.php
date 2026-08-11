<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Target;
use App\Models\Transaction;

/**
 * ═══════════════════════════════════════════════════════════════
 * المحقق للتارجيت السنوي — من `transactions` (مصدر الحقيقة)
 * ═══════════════════════════════════════════════════════════════
 *
 * صافي مبيعات العميل في الشهر:
 *
 *     Σ debit  WHERE kind = 'sale'   −   Σ credit WHERE kind = 'return'
 *
 * نفس أعمدة `Client::recalculate()` بالظبط (purchases − returns) —
 * يعني بالـ`grand_total` (شامل الضريبة) لأن قيد الليدجر بيتكتب بيه.
 * ⚠️ ممنوع نقرا `grand_total` من الفواتير أو الأوامر مباشرة —
 * الليدجر هو الحقيقة (فيه القيود التاريخية المستوردة كمان).
 * ⚠️ `consignment` مش مبيعات لحد ما تتباع، و`refund` حركة فلوس مش
 * مبيعات — الاتنين برّه الحسبة عن قصد.
 *
 * **التجميع لفوق (قرار المالك):** مبيعات العميل بتتحسب لمندوبه
 * (`clients.rep_id`) ولمديره (`clients.manager_id`) وللشركة —
 * مش لمين قبض الفاتورة. والشركة = **كل** العملاء.
 *
 * ═══ عقيدة اليدوي والتصعيد (١١ أغسطس ٢٠٢٦ — فيدباك المالك) ═══
 *
 *     achieved(node, m) = manual(node, m)                    لو متسجّل
 *                       = live(node, m)
 *                         + Σ children (achieved(child, m) − live(child, m))   غير كده
 *
 * يعني اليدوي المكتوب على أي عقدة بيصعّد **فرقه عن اللايف** لأبوها:
 * تكتب شهور مندوب قديمة يدوي، فبار مديره وإجمالي الشركة يتحركوا
 * معاه — إلا لو الأب نفسه له يدوي للشهر ده (اليدوي بيغلب في عقدته
 * دايماً). ومسح اليدوي بيرجّع اللايف + تصعيدات ولاده.
 *
 * التنفيذ: تحميل شجرة السنة كلها بشهورها **مرة واحدة**، واللايف من
 * كويري الليدجر المجمّعة الواحدة، وبعدين تمريرة واحدة من تحت لفوق
 * (عميل ← مندوب ← مدير ← شركة). مفيش N+1.
 *
 * ⚠️ كاش ستاتيك للريكوست الواحد — كويري مجمّعة واحدة لكل سنة
 * مهما الشاشة سألت عن عملاء ومناديب ومديرين.
 */
class TargetProgress
{
    /** @var array<int, array<int, array<int, float>>> سنة => عميل => شهر => صافي */
    private static array $sales = [];

    /** @var array<int, array{rep: ?int, mgr: ?int}>|null عميل => تسكيناته */
    private static ?array $assign = null;

    /**
     * شجرة السنة المحسوبة — لكل عقدة: `live` (من القيود بس)،
     * `base` (لايف + تصعيدات الولاد، من غير يدوي العقدة نفسها —
     * ده اللي بيتعرض placeholder في خانات اليدوي)، و`eff`
     * (النهائي — اليدوي بيغلب).
     *
     * @var array<int, array{live: array<int, array<int, float>>, base: array<int, array<int, float>>, eff: array<int, array<int, float>>}>
     */
    private static array $tree = [];

    /**
     * صافي مبيعات كل عميل شهر بشهر للسنة دي — كويري واحدة، كاش للريكوست.
     *
     * @return array<int, array<int, float>> [client_id][month] => net
     */
    public static function clientMonthSales(int $year): array
    {
        if (! array_key_exists($year, self::$sales)) {
            $rows = Transaction::query()
                ->selectRaw("client_id, MONTH(`date`) AS m,
                    SUM(CASE WHEN kind = 'sale' THEN debit ELSE 0 END)
                  - SUM(CASE WHEN kind = 'return' THEN credit ELSE 0 END) AS net")
                ->whereIn('kind', ['sale', 'return'])
                ->whereNotNull('client_id')
                ->whereYear('date', $year)
                ->groupByRaw('client_id, MONTH(`date`)')
                ->get();

            $map = [];

            foreach ($rows as $r) {
                $map[(int) $r->client_id][(int) $r->m] = round((float) $r->net, 2);
            }

            self::$sales[$year] = $map;
        }

        return self::$sales[$year];
    }

    /**
     * تسكينات العملاء (مندوب/مدير) — كويري واحدة للريكوست.
     *
     * @return array<int, array{rep: ?int, mgr: ?int}>
     */
    private static function assignments(): array
    {
        if (self::$assign === null) {
            self::$assign = Client::query()
                ->select('id', 'rep_id', 'manager_id')
                ->get()
                ->mapWithKeys(fn ($c) => [(int) $c->id => [
                    'rep' => $c->rep_id === null ? null : (int) $c->rep_id,
                    'mgr' => $c->manager_id === null ? null : (int) $c->manager_id,
                ]])
                ->all();
        }

        return self::$assign;
    }

    /** @return array<int, float> */
    private static function zeros(): array
    {
        return array_fill(1, 12, 0.0);
    }

    /** محقق عميل واحد شهر بشهر */
    public static function clientByMonth(int $year, int $clientId): array
    {
        $out = self::zeros();

        foreach (self::clientMonthSales($year)[$clientId] ?? [] as $m => $net) {
            if ($m >= 1 && $m <= 12) {
                $out[$m] = $net;
            }
        }

        return $out;
    }

    /** محقق مندوب = مجموع عملائه المسكّنين له (`clients.rep_id`) */
    public static function repByMonth(int $year, int $userId): array
    {
        return self::rollup($year, fn (array $a) => $a['rep'] === $userId);
    }

    /** محقق مدير = مجموع عملائه (`clients.manager_id`) */
    public static function managerByMonth(int $year, int $userId): array
    {
        return self::rollup($year, fn (array $a) => $a['mgr'] === $userId);
    }

    /** محقق الشركة = كل العملاء */
    public static function companyByMonth(int $year): array
    {
        return self::rollup($year, fn () => true);
    }

    /** @param  \Closure(array{rep: ?int, mgr: ?int}): bool  $take */
    private static function rollup(int $year, \Closure $take): array
    {
        $out = self::zeros();
        $assign = self::assignments();

        foreach (self::clientMonthSales($year) as $clientId => $byMonth) {
            $a = $assign[$clientId] ?? ['rep' => null, 'mgr' => null];

            if (! $take($a)) {
                continue;
            }

            foreach ($byMonth as $m => $net) {
                if ($m >= 1 && $m <= 12) {
                    $out[$m] = round($out[$m] + $net, 2);
                }
            }
        }

        return $out;
    }

    /**
     * اللايف من القيود لعقدة حسب نوعها — من غير أي يدوي ولا تصعيد.
     *
     * @return array<int, float>
     */
    public static function liveByMonth(Target $t): array
    {
        return match ($t->kind) {
            Target::KIND_COMPANY => self::companyByMonth((int) $t->year),
            Target::KIND_MANAGER => self::managerByMonth((int) $t->year, (int) $t->user_id),
            Target::KIND_REP => self::repByMonth((int) $t->year, (int) $t->user_id),
            Target::KIND_CLIENT => self::clientByMonth((int) $t->year, (int) $t->client_id),
            default => self::zeros(),
        };
    }

    /**
     * حل شجرة السنة كلها مرة واحدة: تحميل كل عقد السنة بشهورها
     * (كويري واحدة)، اللايف من الليدجر المجمّع، وتمريرة من تحت لفوق
     * بتصعّد فرق اليدوي عن اللايف لكل أب.
     *
     * @return array{live: array<int, array<int, float>>, base: array<int, array<int, float>>, eff: array<int, array<int, float>>}
     */
    private static function solveYear(int $year): array
    {
        if (isset(self::$tree[$year])) {
            return self::$tree[$year];
        }

        $nodes = Target::with('months')->where('year', $year)->get();

        $byParent = [];

        foreach ($nodes as $n) {
            if ($n->parent_id !== null) {
                $byParent[(int) $n->parent_id][] = $n;
            }
        }

        $live = [];
        $base = [];
        $eff = [];

        foreach ($nodes as $n) {
            $live[(int) $n->id] = self::liveByMonth($n);
        }

        // حارس الدواير: عمق الشجرة ٤ بحكم البناء، بس لو parent_id
        // اتلخبط يوم من الأيام مانلفّش للأبد — العقدة اللي لسه بتتحل
        // بترجع لايفها وخلاص.
        $busy = [];

        $solve = function (Target $n) use (&$solve, &$live, &$base, &$eff, &$busy, $byParent): array {
            $id = (int) $n->id;

            if (isset($eff[$id])) {
                return $eff[$id];
            }

            if (isset($busy[$id])) {
                return $live[$id];
            }

            $busy[$id] = true;
            $out = $live[$id];

            foreach ($byParent[$id] ?? [] as $child) {
                $cid = (int) $child->id;
                $childEff = $solve($child);

                for ($m = 1; $m <= 12; $m++) {
                    $out[$m] = round($out[$m] + $childEff[$m] - $live[$cid][$m], 2);
                }
            }

            $base[$id] = $out;

            foreach ($n->manualByMonth() as $m => $manual) {
                if ($manual !== null) {
                    $out[$m] = $manual;
                }
            }

            unset($busy[$id]);

            return $eff[$id] = $out;
        };

        foreach ($nodes as $n) {
            $solve($n);
        }

        return self::$tree[$year] = ['live' => $live, 'base' => $base, 'eff' => $eff];
    }

    /**
     * المحقق الفعلي لعقدة شهر بشهر — يدوي العقدة بيغلب، وتصعيدات
     * يدوي الولاد محسوبة (الدوكترين فوق).
     *
     * @return array<int, float>
     */
    public static function achievedByMonth(Target $t): array
    {
        $tree = self::solveYear((int) $t->year);

        return $tree['eff'][(int) $t->id] ?? self::liveByMonth($t);
    }

    /**
     * المحسوب للعقدة **من غير يدويها هي** (لايف + تصعيدات الولاد) —
     * ده الـplaceholder بتاع خانة اليدوي: لو مسحتها ده اللي هيتحسب.
     *
     * @return array<int, float>
     */
    public static function baseByMonth(Target $t): array
    {
        $tree = self::solveYear((int) $t->year);

        return $tree['base'][(int) $t->id] ?? self::liveByMonth($t);
    }
}
