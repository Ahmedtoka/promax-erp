<?php

namespace App\Services\Gl;

use App\Models\Gl\GlAccount;
use App\Models\Gl\GlEntry;
use App\Models\Gl\GlLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** قراءة بس — كل التقارير من gl_lines × gl_entries بفترة */
class Reports
{
    /** مجاميع مدين/دائن مجمّعة بـ`account_id` نفسه بس — بدون لمّ الأحفاد
     * (يعني حسابات postable الأوراق فقط)، على فترة */
    private function sums(?Carbon $from, ?Carbon $to): array
    {
        $q = DB::table('gl_lines')->join('gl_entries', 'gl_entries.id', '=', 'gl_lines.entry_id');
        if ($from) {
            $q->whereDate('gl_entries.date', '>=', $from->toDateString());
        }
        if ($to) {
            $q->whereDate('gl_entries.date', '<=', $to->toDateString());
        }

        return $q->selectRaw('gl_lines.account_id, SUM(gl_lines.debit) d, SUM(gl_lines.credit) c')
            ->groupBy('gl_lines.account_id')->get()->keyBy('account_id')->all();
    }

    private function signed(GlAccount $a, float $d, float $c): float
    {
        // حساب المدين طبيعته موجب بالمدين (زيادة الأصول/المصروفات)،
        // وحساب الدائن طبيعته موجب بالدائن — عشان كده بنعكس الطرح
        // حسب `normal_side` مش نطرح دايماً بنفس الاتجاه
        return round($a->normal_side === 'debit' ? $d - $c : $c - $d, 2);
    }

    /**
     * رصيد موقّع لـ**كل** حساب في الشجرة — بما فيهم المجموعات اللي
     * مافيهاش سطور (`is_postable = false`)، عشان شاشة الشجرة تعرض
     * رصيد على كل عقدة.
     *
     * ⚠️ **كويري واحدة مجمّعة + قراءة واحدة للشجرة.** النداء على
     * `balanceBetween()` لكل حساب كان معناه كويري لكل عقدة (٣٠+ كويري
     * على شاشة واحدة، وبتزيد مع كل حساب حر المالك بيضيفه). هنا
     * بنجمّع الصافي الخام (مدين ناقص دائن) وبنطلّعه لفوق في سلسلة
     * الآباء، وبعدين بنوقّعه بطبيعة **كل** حساب لوحده — الأب ممكن
     * تكون طبيعته عكس ابنه (خصم مسموح تحت الإيرادات).
     *
     * @return array<int, float>  id ⇒ الرصيد
     */
    public function balancesByAccount(?Carbon $from, ?Carbon $to): array
    {
        $accounts = GlAccount::orderBy('id')->get(['id', 'parent_id', 'normal_side']);
        $parent = [];
        $net = [];
        foreach ($accounts as $a) {
            $parent[$a->id] = $a->parent_id;
            $net[$a->id] = 0.0;
        }

        foreach ($this->sums($from, $to) as $id => $s) {
            if (! array_key_exists($id, $net)) {
                continue;   // سطر على حساب اتمسح — مش بيتحسب على حد
            }
            $v = (float) $s->d - (float) $s->c;
            $node = (int) $id;
            // ⚠️ حارس العمق: أب بيشاور على نفسه (داتا مكسورة) كان
            // هيخلي اللوب مالهاش آخر والصفحة تعلّق من غير رسالة
            for ($depth = 0; $node !== null && $depth < 50; $depth++) {
                $net[$node] += $v;
                $node = $parent[$node] ?? null;
            }
        }

        $out = [];
        foreach ($accounts as $a) {
            $out[$a->id] = round($a->normal_side === 'debit' ? $net[$a->id] : -$net[$a->id], 2);
        }

        return $out;
    }

    public function trialBalance(?Carbon $from, ?Carbon $to): array
    {
        $period = $this->sums($from, $to);
        $before = $from ? $this->sums(null, $from->copy()->subDay()) : [];
        $rows = [];
        $td = $tc = 0.0;
        foreach (GlAccount::where('is_postable', true)->orderBy('code')->get() as $a) {
            $p = $period[$a->id] ?? null;
            $b = $before[$a->id] ?? null;
            $opening = $b ? $this->signed($a, (float) $b->d, (float) $b->c) : 0.0;
            $d = round((float) ($p->d ?? 0), 2);
            $c = round((float) ($p->c ?? 0), 2);
            if ($opening == 0.0 && $d == 0.0 && $c == 0.0) {
                continue;
            }
            $rows[] = ['account' => $a, 'opening' => $opening, 'debit' => $d, 'credit' => $c,
                'closing' => round($opening + $this->signed($a, $d, $c), 2)];
            $td += $d;
            $tc += $c;
        }

        return ['rows' => $rows, 'totals' => ['debit' => round($td, 2), 'credit' => round($tc, 2)]];
    }

    public function statement(GlAccount $acc, ?Carbon $from, ?Carbon $to): array
    {
        $ids = $acc->subtreeIds();
        $opening = $from ? $acc->balanceBetween(null, $from->copy()->subDay()) : 0.0;
        $q = GlLine::with(['entry', 'account'])->whereIn('account_id', $ids)
            ->join('gl_entries', 'gl_entries.id', '=', 'gl_lines.entry_id')->select('gl_lines.*')
            ->orderBy('gl_entries.date')->orderBy('gl_entries.id')->orderBy('gl_lines.id');
        if ($from) {
            $q->whereDate('gl_entries.date', '>=', $from->toDateString());
        }
        if ($to) {
            $q->whereDate('gl_entries.date', '<=', $to->toDateString());
        }
        $running = $opening;
        $rows = [];
        foreach ($q->get() as $l) {
            $running = round($running + $this->signed($acc, (float) $l->debit, (float) $l->credit), 2);
            $rows[] = ['entry' => $l->entry, 'line' => $l, 'running' => $running];
        }

        return ['opening' => round($opening, 2), 'rows' => $rows, 'closing' => $running];
    }

    public function income(Carbon $from, Carbon $to): array
    {
        $sums = $this->sums($from, $to);
        $rev = $exp = [];
        $tr = $te = 0.0;
        foreach (GlAccount::whereIn('type', ['revenue', 'expense'])->where('is_postable', true)->orderBy('code')->get() as $a) {
            $s = $sums[$a->id] ?? null;
            if (! $s) {
                continue;
            }
            $amount = $this->signed($a, (float) $s->d, (float) $s->c);
            if ($a->type === 'revenue') {
                $rev[] = ['account' => $a, 'amount' => $amount];
                $tr += $amount;
            } else {
                $exp[] = ['account' => $a, 'amount' => $amount];
                $te += $amount;
            }
        }

        return ['revenue' => $rev, 'expenses' => $exp, 'total_revenue' => round($tr, 2), 'total_expenses' => round($te, 2), 'net' => round($tr - $te, 2)];
    }

    public function balanceSheet(Carbon $asOf): array
    {
        $sums = $this->sums(null, $asOf);
        $out = ['assets' => [], 'liabilities' => [], 'equity' => []];
        $tot = ['asset' => 0.0, 'liability' => 0.0, 'equity' => 0.0];
        foreach (GlAccount::whereIn('type', ['asset', 'liability', 'equity'])->where('is_postable', true)->orderBy('code')->get() as $a) {
            $s = $sums[$a->id] ?? null;
            if (! $s) {
                continue;
            }
            $amount = $this->signed($a, (float) $s->d, (float) $s->c);
            $out[$a->type === 'asset' ? 'assets' : ($a->type === 'liability' ? 'liabilities' : 'equity')][] = ['account' => $a, 'amount' => $amount];
            $tot[$a->type] += $amount;
        }
        // صافي الدخل مش قيد فعلي على حساب حقوق ملكية — هو رقم مشتق من
        // الإيرادات والمصروفات لحد النهاردة، وميزانية بلا قفل سنوي محتاجة
        // تضيفه لحقوق الملكية يدوياً في التقرير عشان الأصول تتوازن مع
        // الخصوم+الملكية (زي الأرباح المحتجزة قبل التوزيع/الإقفال)
        $retained = $this->income(Carbon::parse('1970-01-01'), $asOf)['net'];
        $tle = round($tot['liability'] + $tot['equity'] + $retained, 2);

        return $out + [
            'retained' => $retained,
            'total_assets' => round($tot['asset'], 2),
            'total_liabilities_equity' => $tle,
            'balanced' => abs(round($tot['asset'], 2) - $tle) < 0.005,
        ];
    }
}
