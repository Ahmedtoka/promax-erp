<?php

namespace App\Services\Review;

use App\Models\ReviewCheck;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * أرقام كل عميل شهر بشهر للمطابقة مع الورق (مراجعة إعادة التشغيل).
 *
 *   صافي المسحوبات = بيع (sale.debit) − مرتجع (return.credit) بتاريخ القيد
 *   الرصيد آخر الشهر = Σمدين − Σدائن لكل القيود لحد آخر يوم في الشهر
 *
 * ⚠️ نفس تعريفات `SalesSource` وكشف الحساب — الرقم هنا لازم يطابق كشف
 * حساب العميل بالحرف، وإلا المراجع هيلاقي فرق مالوش وجود.
 */
class MonthFigures
{
    /**
     * @param  list<int>  $clientIds
     * @return array<int, array<string, array{net: float, balance: float}>>  client_id => month => أرقام
     */
    public static function forClients(array $clientIds): array
    {
        $months = ReviewCheck::months();

        if ($clientIds === [] || $months === []) {
            return [];
        }

        $start = Carbon::createFromFormat('Y-m-d', $months[0].'-01')->startOfDay();
        $end = Carbon::createFromFormat('Y-m-d', end($months).'-01')->endOfMonth();

        $before = DB::table('transactions')->whereIn('client_id', $clientIds)
            ->where('date', '<', $start->toDateString())
            ->groupBy('client_id')
            ->selectRaw('client_id, COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) AS b')
            ->pluck('b', 'client_id');

        $rows = DB::table('transactions')->whereIn('client_id', $clientIds)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->groupBy('client_id', 'm')
            ->selectRaw("client_id, DATE_FORMAT(date, '%Y-%m') AS m,
                SUM(CASE WHEN kind = 'sale' THEN debit ELSE 0 END)
                  - SUM(CASE WHEN kind = 'return' THEN credit ELSE 0 END) AS net,
                COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) AS delta")
            ->get()
            ->groupBy('client_id');

        $out = [];

        foreach ($clientIds as $id) {
            $running = (float) ($before[$id] ?? 0);
            $byMonth = collect($rows->get($id, []))->keyBy('m');

            foreach ($months as $m) {
                $r = $byMonth->get($m);
                $running += (float) ($r->delta ?? 0);
                $out[$id][$m] = ['net' => round((float) ($r->net ?? 0), 2), 'balance' => round($running, 2)];
            }
        }

        return $out;
    }

    /**
     * جمع أرقام فروع (سلسلة) في رقم واحد لكل شهر.
     *
     * @param  array<int, array<string, array{net: float, balance: float}>>  $figures
     * @param  iterable<int>  $clientIds
     * @return array<string, array{net: float, balance: float}>
     */
    public static function sum(array $figures, iterable $clientIds): array
    {
        $out = [];

        foreach (ReviewCheck::months() as $m) {
            $out[$m] = ['net' => 0.0, 'balance' => 0.0];
        }

        foreach ($clientIds as $id) {
            foreach ($figures[$id] ?? [] as $m => $f) {
                $out[$m]['net'] += $f['net'];
                $out[$m]['balance'] += $f['balance'];
            }
        }

        return array_map(fn ($f) => ['net' => round($f['net'], 2), 'balance' => round($f['balance'], 2)], $out);
    }
}
