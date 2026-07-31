<?php

namespace App\Services;

/**
 * مصدر الداتا الحقيقية (لحد شهر 6/2026) — من داشبورد PROMAX الأصلية.
 * الداتا في storage/app/data/promax.json — read-only لحد ما نبني الجداول الحقيقية.
 */
class PromaxData
{
    protected static ?array $data = null;

    // تصنيفات العملاء
    public const CATS = [
        'danger' => ['🔴 تحصيل فوري', 'b-red'],
        'watch' => ['🟠 تابع عن قرب', 'b-orange'],
        'grow' => ['🟢 كبّر التعامل', 'b-green'],
        'ok' => ['✅ منتظم', 'b-blue'],
        'idle' => ['⚪ خامل', 'b-gray'],
        'internal' => ['🚚 قناة داخلية', 'b-purple'],
        'credit' => ['🔵 رصيد دائن', 'b-blue'],
    ];

    public const FAM_AR = [
        'promax_bar' => 'بروماكس بار',
        'promax_cup' => 'بروكب',
        'spreads' => 'سبريدز',
        'pmx_bar' => 'PMX بار',
    ];

    public const TXN_LBL = [
        'sale' => 'فاتورة/مبيعات',
        'collection' => 'تحصيل نقدي',
        'return' => 'مرتجع',
        'rebate' => 'خصم تجاري',
        'settlement' => 'تسوية/مقاصة',
        'transfer' => 'قيد تحويل',
        'taxded' => 'ضرائب مخصومة',
    ];

    public static function raw(): array
    {
        if (static::$data === null) {
            static::$data = json_decode(
                file_get_contents(storage_path('app/data/promax.json')),
                true
            );
        }

        return static::$data;
    }

    public static function today(): string
    {
        return static::raw()['today'];
    }

    public static function totals(): array
    {
        return static::raw()['totals'];
    }

    public static function products(): array
    {
        return static::raw()['products'];
    }

    /** كل العملاء (103) مع index ثابت للروابط */
    public static function clients(): array
    {
        $clients = static::raw()['clients'];
        foreach ($clients as $i => &$c) {
            $c['idx'] = $i;
            $c['cat'] = $c['cat_py'] ?? 'ok';
        }

        return $clients;
    }

    public static function client(int $idx): ?array
    {
        return static::clients()[$idx] ?? null;
    }

    public static function stockSkus(): array
    {
        return static::raw()['stock']['skus'];
    }

    public static function alloc(): array
    {
        return static::raw()['alloc']['rows'] ?? [];
    }

    public static function arunb(): array
    {
        return static::raw()['arunb'];
    }

    // ---------- Aggregates ----------

    /** سلسلة شهرية مجمعة: [شهر => [مبيعات, تحصيل]] */
    public static function monthlySeries(): array
    {
        $months = [];
        foreach (static::clients() as $c) {
            foreach ($c['monthly'] ?? [] as $m => [$purch, $coll]) {
                $months[$m] ??= [0.0, 0.0];
                $months[$m][0] += $purch;
                $months[$m][1] += $coll;
            }
        }
        ksort($months);

        return $months;
    }

    /** إجمالي أعمار المديونية */
    public static function agingTotals(): array
    {
        $t = ['a30' => 0.0, 'a60' => 0.0, 'a90' => 0.0, 'a180' => 0.0, 'a180p' => 0.0];
        foreach (static::clients() as $c) {
            foreach ($c['aging'] ?? [] as $k => $v) {
                $t[$k] += $v;
            }
        }

        return $t;
    }

    /** أكبر العملاء بالمشتريات */
    public static function topClients(int $n = 15): array
    {
        $clients = static::clients();
        usort($clients, fn ($a, $b) => $b['purchases'] <=> $a['purchases']);

        return array_slice($clients, 0, $n);
    }

    /** العملاء اللي معاهم عقود */
    public static function contracts(): array
    {
        return array_values(array_filter(
            static::clients(),
            fn ($c) => ! empty($c['contract'])
        ));
    }

    /** عدد العملاء في كل تصنيف */
    public static function catCounts(): array
    {
        $counts = [];
        foreach (static::clients() as $c) {
            $counts[$c['cat']] = ($counts[$c['cat']] ?? 0) + 1;
        }

        return $counts;
    }

    /** أرصدة دائنة (البلانس بالسالب) */
    public static function creditClients(): array
    {
        $list = array_values(array_filter(
            static::clients(),
            fn ($c) => $c['balance'] < -1
        ));
        usort($list, fn ($a, $b) => $a['balance'] <=> $b['balance']);

        return $list;
    }
}
