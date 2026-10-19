<?php

namespace App\Services\Costing;

use App\Models\CostCenter;
use App\Models\CostEntry;
use App\Models\CostMonth;
use App\Models\CostMonthLine;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Services\Hr\PayrollCost;
use App\Services\SalesSource;
use App\Support\DateRange;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ═══ قايمة الدخل الإدارية بالمستويات الخمسة (الخط ٩ — المهمة ٨، المواصفة §٣.٧) ═══
 *
 * الشركة ← المصنع (وخطوطه) / كل فرع (← العائلات ← الأصناف) / مركز الشركة.
 * بتقرا **طبقة التكلفة بس** (`cost_entries`/`cost_lines`) + مستندات المصروفات
 * والمرتبات والإهلاك — مش الدفتر، فبتشتغل قبل التشغيل الفعلي.
 *
 * قيمة السطر من `CostGl::lineValue` (المصدر الواحد للهامش): R بالطريقة،
 * و«بسعر التحويل» = مبلغ التحويل **بس** للسطر اللي ليه `unit_transfer`، وإلا R.
 *
 *  • الفرع: الإيراد = Σ إيراد قيود البيع والمرتجع على مركزه (المرتجع سالب)؛
 *    التكلفة = Σ القيمة بسعر التحويل (المرتجع بيطرح؛ التالف مبالغه صفر فالتكلفة
 *    بتفضل — D6)؛ المصروفات = مصروفات مركزه (`Overhead::countedExpenses`) +
 *    مرتباته + إهلاكه. الأولاد = العائلات ← الأصناف، والمصروفات بتتوزّع (D11)
 *    بنسبة صافي المبيعات أو صافي الكمية؛ الصنف بأساس ≤ ٠ ياخد صفر، وفرق القروش
 *    على أكبر نصيب. مفيش أساس موجب = المصروفات بتفضل على الفرع من غير توزيع.
 *  • المصنع: الإيراد = المبيعات الداخلية = Σ قيمة سطور التحويل بسعر التحويل
 *    (السطر من غير سعر = R بالطريقة، فمالوش هامش)؛ التكلفة = R بالطريقة؛
 *    المصروفات = تحميل ناقص/زايد (الحوض − المحمّل) لخطوطه + الغير موزّع:
 *    الشهر المقفول من `cost_month_lines.variance`، والمفتوح محسوب من
 *    `Overhead::pools` (مؤقت — `provisional`). الأولاد = الخطوط (إيراد وتكلفة صفر).
 *  • مركز الشركة: مصروفات المراكز من نوع `company` أو من غير مركز + مرتباتهم
 *    (و`'none'`) + إهلاكهم.
 *  • الشركة: الإيراد = Σ إيراد الفروع (البيع الداخلي مستبعد)؛ التكلفة = Σ R
 *    الحقيقي للبيع − المرتجع (عمره ما بيبقى سعر تحويل)؛ المصروفات = الكل؛
 *    الاستبعاد = Σ هامش التحويل − Σ هامش بيع الفروع في الفترة.
 *    `company.net` محسوب من مكوّناته، و`reconciles` بيأكّد إنه =
 *    صافي المصنع + Σ صافي الفروع + صافي مركز الشركة − الاستبعاد.
 *
 * ⚠️ **الشهور** (المرتبات والأحواض شهرية): الشهر بيدخل لو **آخر يوم فيه**
 * جوه الفترة. فترة من غير بداية = من أول تاريخ في الطبقة/المصروفات؛ من غير
 * نهاية = النهارده (فالشهر الجاري مابيدخلش لحد ما يخلص).
 */
final class ProfitLoss
{
    public const LEVELS = ['company', 'factory', 'line', 'branch', 'family', 'product', 'company_center', 'elimination'];

    /**
     * @return array{company:array, factory:array, branches:list<array>, company_center:array, elimination:float,
     *   reconciles:bool, method:string, alt:string, from:string, to:string, months:list<string>,
     *   warnings:array{zero_cost:int, no_transfer_price:int, lines_without_items:float}}
     */
    public static function build(DateRange $range, ?string $method = null): array
    {
        $method = in_array($method, CostingSettings::METHODS, true) ? $method : CostingSettings::method();
        $alt = $method === 'batch' ? 'wavg' : 'batch';
        [$from, $to] = self::bounds($range);
        $months = self::months($from, $to);
        $centers = CostCenter::query()->get()->keyBy('id');
        $maadi = $centers->firstWhere('code', CostCenter::CODE_MAADI);

        $rows = DB::table('cost_lines as l')
            ->join('cost_entries as e', 'e.id', '=', 'l.cost_entry_id')
            ->whereIn('e.kind', ['sale', 'return', 'transfer'])
            ->whereBetween('e.date', [$from->toDateString(), $to->toDateString()])
            ->get(['e.kind', 'e.cost_center_id', 'l.product_id', 'l.qty', 'l.revenue', 'l.unit_transfer',
                'l.amount_batch', 'l.amount_wavg', 'l.amount_transfer']);

        $branch = [];      // center ⇒ product ⇒ [revenue, cost, cost_alt, qty]
        $realCost = 0.0;
        $realAlt = 0.0;
        $branchMarkup = 0.0;
        $fac = ['revenue' => 0.0, 'cost' => 0.0, 'cost_alt' => 0.0];
        $transferMarkup = 0.0;
        foreach ($rows as $r) {
            $v = CostGl::lineValue($r, $method);
            $va = CostGl::lineValue($r, $alt);
            if ($r->kind === 'transfer') {
                $fac['revenue'] += $v['tp'];
                $fac['cost'] += $v['real'];
                $fac['cost_alt'] += $va['real'];
                $transferMarkup += $v['tp'] - $v['real'];

                continue;
            }
            $sign = $r->kind === 'return' ? -1 : 1;
            $c = $r->cost_center_id ?? $maadi?->id ?? 0;
            $p = (int) $r->product_id;
            $cell = $branch[$c][$p] ?? ['revenue' => 0.0, 'cost' => 0.0, 'cost_alt' => 0.0, 'qty' => 0.0];
            $cell['revenue'] += (float) $r->revenue;
            $cell['cost'] += $sign * $v['tp'];
            $cell['cost_alt'] += $sign * $va['tp'];
            $cell['qty'] += $sign * (float) $r->qty;
            $branch[$c][$p] = $cell;
            $realCost += $sign * $v['real'];
            $realAlt += $sign * $va['real'];
            $branchMarkup += $sign * ($v['tp'] - $v['real']);
        }

        // ═══ المصروفات: مستندات + مرتبات الشهور + إهلاك ═══
        $exp = Overhead::countedExpenses($from, $to);
        $dep = Overhead::depreciation($from, $to);
        $pay = [];
        foreach ($months as $m) {
            foreach (PayrollCost::forMonth(\Carbon\Carbon::parse($m.'-01')) as $k => $row) {
                $pay[$k] = ($pay[$k] ?? 0.0) + (float) $row['total'];
            }
        }
        $centerCost = fn ($k) => (float) ($exp[$k] ?? 0) + (float) ($pay[$k] ?? 0) + (float) ($dep[$k] ?? 0);

        // ═══ الفروع ═══
        $ids = $centers->where('kind', 'branch')->keys()->merge(array_keys($branch))->unique()->sort()->values();
        $products = Product::whereIn('id', collect($branch)->flatMap(fn ($ps) => array_keys($ps))->unique()->all())->get()->keyBy('id');
        $basis = CostingSettings::allocBasis();
        $branches = [];
        foreach ($ids as $cid) {
            $center = $centers->get($cid);
            $cells = $branch[$cid] ?? [];
            $expenses = $center !== null ? $centerCost($cid) : 0.0;
            if ($cells === [] && round($expenses, 2) == 0.0 && $cid !== $maadi?->id) {
                continue;
            }
            $branches[] = self::branchRow($cid, $center, $cells, $expenses, $products, $basis);
        }

        // ═══ المصنع وخطوطه (تحميل ناقص/زايد) ═══
        [$absorb, $unshared, $provisional] = self::absorption($months, $centers);
        $lineRows = [];
        foreach ($centers->where('kind', 'line')->sortBy('id') as $line) {
            $e = (float) ($absorb[$line->id] ?? 0);
            if (round($e, 2) != 0.0) {
                $lineRows[] = self::row('line:'.$line->id, $line->displayName(), 0, 0, 0, $e);
            }
        }
        if (round($unshared, 2) != 0.0) {
            $lineRows[] = self::row('line:unshared', __('costing.factory_unshared'), 0, 0, 0, $unshared);
        }
        $facExpenses = array_sum(array_column($lineRows, 'expenses'));
        $factoryCenter = $centers->firstWhere('kind', 'factory');
        $factory = self::row('factory', $factoryCenter?->displayName() ?? __('costing.pnl_factory'),
            $fac['revenue'], $fac['cost'], $fac['cost_alt'], $facExpenses, $lineRows);
        $factory['provisional'] = $provisional;

        // ═══ مركز الشركة ═══
        $ccKeys = $centers->where('kind', 'company')->keys()->all();
        $ccExp = $centerCost('none');
        foreach ($ccKeys as $k) {
            $ccExp += $centerCost($k);
        }
        $companyCenter = self::row('company_center', __('costing.pnl_company_center'), 0, 0, 0, $ccExp);

        // ═══ الشركة ═══
        $elimination = round($transferMarkup - $branchMarkup, 2);
        $revenue = array_sum(array_column($branches, 'revenue'));
        $expenses = array_sum(array_column($branches, 'expenses')) + $factory['expenses'] + $companyCenter['expenses'];
        $company = self::row('company', __('costing.pnl_company'), $revenue, $realCost, $realAlt, $expenses);
        $sumLevels = $factory['net'] + array_sum(array_column($branches, 'net')) + $companyCenter['net'] - $elimination;

        return [
            'company' => $company,
            'factory' => $factory,
            'branches' => $branches,
            'company_center' => $companyCenter,
            'elimination' => $elimination,
            'reconciles' => abs($company['net'] - $sumLevels) < 0.01,
            'method' => $method,
            'alt' => $alt,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'months' => $months,
            'warnings' => self::warnings($from, $to),
        ];
    }

    /**
     * صف بالشكل الموحّد — الفلوس متقرّبة لمنزلتين.
     *
     * @param  list<array>|null  $children
     */
    private static function row(string $key, string $label, float $revenue, float $cost, float $costAlt, float $expenses, ?array $children = null): array
    {
        $revenue = round($revenue, 2);
        $cost = round($cost, 2);
        $expenses = round($expenses, 2);
        $gross = round($revenue - $cost, 2);
        $row = [
            'key' => $key, 'label' => $label, 'revenue' => $revenue, 'cost' => $cost, 'cost_alt' => round($costAlt, 2),
            'gross' => $gross, 'expenses' => $expenses, 'net' => round($gross - $expenses, 2),
        ];
        if ($children !== null) {
            $row['children'] = $children;
        }

        return $row;
    }

    /**
     * @param  array<int, array{revenue:float, cost:float, cost_alt:float, qty:float}>  $cells
     * @param  \Illuminate\Support\Collection<int, Product>  $products
     */
    private static function branchRow(int $cid, ?CostCenter $center, array $cells, float $expenses, $products, string $basis): array
    {
        $weights = [];
        foreach ($cells as $pid => $c) {
            $weights[$pid] = $basis === 'qty' ? $c['qty'] : $c['revenue'];
        }
        $alloc = self::allocate(round($expenses, 2), $weights);

        $families = [];
        foreach ($cells as $pid => $c) {
            $p = $products->get($pid);
            $fk = (string) ($p?->family ?? '');
            $families[$fk][] = self::row('product:'.$pid, $p?->displayName() ?? '#'.$pid,
                $c['revenue'], $c['cost'], $c['cost_alt'], $alloc[$pid] ?? 0.0);
        }
        ksort($families);
        $children = [];
        foreach ($families as $fk => $prows) {
            usort($prows, fn ($a, $b) => $b['revenue'] <=> $a['revenue']);
            $children[] = self::row('family:'.($fk === '' ? '-' : $fk),
                $fk === '' ? __('costing.pnl_no_family') : ProductFamily::label($fk),
                array_sum(array_column($prows, 'revenue')), array_sum(array_column($prows, 'cost')),
                array_sum(array_column($prows, 'cost_alt')), array_sum(array_column($prows, 'expenses')), $prows);
        }

        return self::row('branch:'.$cid, $center?->displayName() ?? '#'.$cid,
            array_sum(array_column($cells, 'revenue')), array_sum(array_column($cells, 'cost')),
            array_sum(array_column($cells, 'cost_alt')), $expenses, $children);
    }

    /**
     * توزيع مبلغ بنسبة الأساس (D11): الأساس ≤ ٠ = صفر، والفرق بالقرش على
     * أكبر نصيب عشان المجموع = المبلغ بالظبط. مفيش أساس موجب = مصفوفة فاضية.
     *
     * @param  array<int, float>  $weights
     * @return array<int, float>
     */
    public static function allocate(float $amount, array $weights): array
    {
        $pos = array_filter($weights, fn ($w) => $w > 0);
        $total = array_sum($pos);
        if ($total <= 0 || $amount == 0.0) {
            return [];
        }
        $out = [];
        foreach ($pos as $id => $w) {
            $out[$id] = round($amount * $w / $total, 2);
        }
        $largest = array_search(max($pos), $pos, true);
        $out[$largest] = round($out[$largest] + round($amount - array_sum($out), 2), 2);

        return $out;
    }

    /**
     * التحميل الناقص/الزايد بالخط في شهور الفترة: المقفول من `cost_month_lines`
     * (سطر مركز المصنع = الغير موزّع)، والمفتوح من `Overhead::pools`.
     *
     * @param  list<string>  $months
     * @return array{0:array<int, float>, 1:float, 2:list<string>}
     */
    private static function absorption(array $months, $centers): array
    {
        $byLine = [];
        $unshared = 0.0;
        $provisional = [];
        $closed = CostMonth::whereIn('period', $months)->where('status', 'closed')->pluck('id', 'period');
        foreach ($months as $m) {
            if ($closed->has($m)) {
                foreach (CostMonthLine::where('cost_month_id', $closed[$m])->get(['line_id', 'variance']) as $ml) {
                    if (($centers->get($ml->line_id)?->kind) === 'factory') {
                        $unshared += (float) $ml->variance;
                    } else {
                        $byLine[$ml->line_id] = ($byLine[$ml->line_id] ?? 0.0) + (float) $ml->variance;
                    }
                }

                continue;
            }
            $pools = Overhead::pools(Carbon::parse($m.'-01'));
            $any = false;
            foreach ($pools as $k => $r) {
                if ($k === 'factory_unshared') {
                    $unshared += (float) $r;
                    $any = $any || (float) $r != 0.0;

                    continue;
                }
                $v = (float) $r['pool'] - (float) $r['applied'];
                $byLine[$k] = ($byLine[$k] ?? 0.0) + $v;
                $any = $any || $v != 0.0;
            }
            if ($any) {
                $provisional[] = $m;
            }
        }

        return [$byLine, $unshared, $provisional];
    }

    /** @return array{zero_cost:int, no_transfer_price:int, lines_without_items:float} */
    private static function warnings(Carbon $from, Carbon $to): array
    {
        $a = $from->toDateString();
        $b = $to->toDateString();
        $zero = DB::table('cost_lines as l')->join('cost_entries as e', 'e.id', '=', 'l.cost_entry_id')
            ->whereBetween('e.date', [$a, $b])->where('l.zero_cost', true)->count();
        $noTp = CostEntry::where('kind', 'transfer')->whereBetween('date', [$a, $b])
            ->where('flags', 'like', '%no_transfer_price%')->count();

        // مبيعات الفترة من مصدر الأرقام الموحّد (قبل الضريبة) − اللي الطبقة قدرت تنسبه
        $sales = (float) SalesSource::docs($from, $to)->sum('total')
            - (float) SalesSource::returns($from, $to)->selectRaw('COALESCE(SUM(amount - tax),0) n')->value('n');
        $layer = (float) CostEntry::whereIn('kind', ['sale', 'return'])->whereBetween('date', [$a, $b])->sum('revenue');

        return ['zero_cost' => $zero, 'no_transfer_price' => $noTp, 'lines_without_items' => round($sales - $layer, 2)];
    }

    /** @return array{0:Carbon, 1:Carbon} */
    private static function bounds(DateRange $range): array
    {
        $to = $range->to?->copy()->endOfDay() ?? today()->endOfDay();
        if ($range->from !== null) {
            return [$range->from->copy()->startOfDay(), $to];
        }
        $first = collect([
            CostEntry::min('date'),
            DB::table('expenses')->where('status', 'posted')->min('date'),
            DB::table('fixed_asset_depreciations')->min('date'),
        ])->filter()->min();

        return [($first ? Carbon::parse($first) : today()->startOfMonth())->startOfDay(), $to];
    }

    /**
     * الشهور اللي آخر يوم فيها جوه الفترة.
     *
     * @return list<string> `Y-m`
     */
    public static function months(Carbon $from, Carbon $to): array
    {
        $out = [];
        $m = $from->copy()->startOfMonth();
        while ($m->lte($to)) {
            $end = $m->copy()->endOfMonth()->startOfDay();
            if ($end->gte($from->copy()->startOfDay()) && $end->lte($to)) {
                $out[] = $m->format('Y-m');
            }
            $m->addMonthNoOverflow();
        }

        return $out;
    }
}
