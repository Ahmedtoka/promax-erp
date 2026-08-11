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
 * **اليدوي بيغلب:** `target_months.manual_actual` لو متسجّل لشهر،
 * بيستبدل المحسوب للعقدة دي في الشهر ده (الشهور التاريخية 1–7).
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
     * المحقق الفعلي لعقدة تارجيت شهر بشهر — المحسوب من القيود،
     * و`manual_actual` بيغلب للشهر اللي متسجّل فيه.
     *
     * @return array<int, float>
     */
    public static function achievedByMonth(Target $t): array
    {
        $out = match ($t->kind) {
            Target::KIND_COMPANY => self::companyByMonth((int) $t->year),
            Target::KIND_MANAGER => self::managerByMonth((int) $t->year, (int) $t->user_id),
            Target::KIND_REP => self::repByMonth((int) $t->year, (int) $t->user_id),
            Target::KIND_CLIENT => self::clientByMonth((int) $t->year, (int) $t->client_id),
            default => self::zeros(),
        };

        foreach ($t->manualByMonth() as $m => $manual) {
            if ($manual !== null) {
                $out[$m] = $manual;
            }
        }

        return $out;
    }
}
