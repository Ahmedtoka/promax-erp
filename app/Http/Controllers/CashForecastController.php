<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\User;
use App\Services\CashForecast;
use App\Support\Csv;
use App\Support\DateRange;
use Illuminate\Http\Request;

/**
 * ═══════════════════════════════════════════════════════════════
 * الكاش المتوقع — كالندر استحقاق مديونية العملاء (١٥ سبتمبر ٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * سؤال المالك بالحرف: «المبيعات اللي بعتها فيه حاجات بعد 15 يوم وبعد
 * 30 وبعد 60 — كل ما أحدد الفترة أعرف ممكن يكون معايا كاش قد إيه».
 *
 * الشاشة بتعرض المديونية المفتوحة على العملاء الآجل موزّعة على
 * **ميعاد استحقاقها** (مش تاريخ الفاتورة): كالندر شهري، منحنى تراكمي،
 * أزرار سريعة 15/30/60/90 يوم، وجدول بالحركة والعميل والمندوب.
 * الأرقام من `App\Services\CashForecast` — نفس توزيع `aging()`/`overdue()`.
 *
 * `?export=1` بيطلّع CSV بنفس الفلتر. الطباعة من المتصفح (زرار PDF).
 */
class CashForecastController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        // ⚠️ الافتراضي «النهارده → +60 يوم» — الشاشة سؤالها عن المستقبل.
        // `from` أقل من النهارده مالوش معنى هنا (اللي عدّى ميعاده له
        // كارت «متأخر» لوحده) — فبنرفعه للنهارده بصمت.
        $range = DateRange::fromRequest($request, 'open');
        $from = $range->from !== null && $range->from->gte(today()) ? $range->from : today();
        $to = $range->to ?? $from->copy()->addDays(60);
        if ($to->lt($from)) {
            $to = $from->copy();
        }
        $range = DateRange::fromRequest(new Request(['from' => $from->toDateString(), 'to' => $to->toDateString()]), 'open');

        $repId = (int) $request->query('rep', 0) ?: null;
        $managerId = (int) $request->query('manager', 0) ?: null;

        // فلتر المدير مسموح للأدمن بس — المدير سكوبه مفروض عليه من `visibleTo`
        if ($user->role !== 'admin') {
            $managerId = null;
        }

        $data = CashForecast::build($user, $range, $repId, $managerId);

        if ($request->boolean('export')) {
            return $this->export($data, $range);
        }

        // ═══ الكالندر: كل الشهور اللي النافذة بتلمسها — الأيام بره النافذة باهتة ═══
        $months = [];
        $cursor = $range->from->copy()->startOfMonth();
        $end = $range->to->copy()->startOfMonth();
        while ($cursor->lte($end) && count($months) < 6) {
            $months[] = $cursor->copy();
            $cursor->addMonth();
        }

        // المندوبين في السكوب — نفس بول شاشة التحصيلات
        $reps = User::query()->whereIn('role', User::FIELD_ROLES)->where('active', true)->orderBy('name');
        $reps = User::fieldVisibleTo($reps, $user)->get();
        $managers = $user->role === 'admin'
            ? User::query()->whereIn('role', ['manager', 'branch_manager'])->where('active', true)->orderBy('name')->get()
            : collect();

        return view('erp.cashflow', [
            'data' => $data,
            'range' => $range,
            'months' => $months,
            'reps' => $reps,
            'managers' => $managers,
            'repId' => $repId,
            'managerId' => $managerId,
        ]);
    }

    /** CSV بنفس الفلتر: المستحق جوه النافذة + المتأخر + بلا شروط — كل صف بحالته */
    private function export(array $data, DateRange $range)
    {
        $rows = collect()
            ->concat($data['overdue']['rows']->map(fn ($r) => $r + ['status' => __('cashflow.status_overdue')]))
            ->concat($data['rows']->map(fn ($r) => $r + ['status' => __('cashflow.status_due')]))
            ->concat($data['no_terms']['rows']->map(fn ($r) => $r + ['status' => __('cashflow.status_no_terms')]));

        $out = $rows->map(fn ($r) => [
            $r['due']?->toDateString() ?? '',
            $r['status'],
            $r['client'],
            $r['rep'] ?? '',
            $r['channel'] ?? '',
            $r['doc'],
            $r['date']->toDateString(),
            Csv::money($r['debit']),
            Csv::money($r['open']),
            $r['terms'] === null ? '' : $r['terms'].' '.__('cashflow.days_'.$r['basis']),
            $r['days_late'] ?: '',
        ]);

        return Csv::download(
            'cash-forecast-'.$range->fromValue().'-'.$range->toValue().'.csv',
            [
                __('cashflow.col_due'), __('cashflow.col_status'), __('cashflow.col_client'), __('cashflow.col_rep'),
                __('cashflow.col_channel'), __('cashflow.col_doc'), __('cashflow.col_doc_date'),
                __('cashflow.col_debit'), __('cashflow.col_open'), __('cashflow.col_terms'), __('cashflow.col_days_late'),
            ],
            $out,
            [__('cashflow.total'), '', '', '', '', '', '', '', Csv::money((float) $rows->sum('open')), '', ''],
            Csv::meta(__('cashflow.title'), $range->fromValue(), $range->toValue())
        );
    }
}
