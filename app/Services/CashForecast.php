<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Transaction;
use App\Models\User;
use App\Support\DateRange;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * ═══════════════════════════════════════════════════════════════
 * الكاش المتوقع — «لو كل عميل سدّد في ميعاده، هيدخل كام وإمتى؟»
 * (١٥ سبتمبر ٢٠٢٦ — طلب المالك)
 * ═══════════════════════════════════════════════════════════════
 *
 * المصدر الوحيد للأرقام: `Client::openDebts()` (التوزيع FIFO للرصيد على
 * قيود المديونية) + `Client::dueDateOf()` (ميعاد كل حركة حسب أساس العدّ).
 * دول نفس الدالتين اللي بتقرا منهم `aging()` و`overdue()` — فالرقم اللي
 * هنا هو نفس الرقم اللي على كارت العميل وشاشة أعمار الديون، موزّع على
 * الزمن بدل ما يكون موزّع على الشرائح.
 *
 * ⚠️ **توقّع تعاقدي مش وعد.** الشاشة بتقول «المستحق» في كل يوم، ومعاه
 * سطر «المتأخر» (عدّى ميعاده ولسه مفتوح) و«بلا شروط» (عميل آجل من غير
 * أيام سداد فمفيش ميعاد نحطه عليه). العميل الكاش مش هنا أصلاً: بيعه
 * بيتحصّل لحظتها.
 *
 * ⚠️ **السكوب زي أي شاشة عملاء**: `Client::visibleTo` على الفاعل قبل
 * أي تجميع — الأرقام المجمّعة للمدير من فريقه بس.
 */
class CashForecast
{
    /**
     * @param  DateRange  $range  نافذة الاستحقاق المعروضة (من/إلى بالاستحقاق مش بالفاتورة)
     * @return array{
     *   rows: Collection<int, array>,        صف لكل حركة مفتوحة ليها ميعاد جوه النافذة
     *   days: array<string, array{total: float, count: int}>,   المجموع لكل يوم استحقاق جوه النافذة
     *   overdue: array{total: float, count: int, rows: Collection},  عدّى ميعاده قبل النهارده ولسه مفتوح
     *   no_terms: array{total: float, count: int, rows: Collection}, آجل بلا أيام سداد
     *   later: array{total: float, count: int},                 مستحق بعد نهاية النافذة
     *   total_open: float,                                       كل المديونية المفتوحة على العملاء الآجل
     *   horizon: array<int, float>                               مجمّع تراكمي بعد 15/30/60/90 يوم من النهارده
     * }
     */
    public static function build(?User $viewer, DateRange $range, ?int $repId = null, ?int $managerId = null): array
    {
        $today = today();
        $from = $range->from?->copy()->startOfDay() ?? $today->copy();
        $to = $range->to?->copy()->endOfDay() ?? $today->copy()->addDays(60)->endOfDay();

        // ═══ العملاء الآجل اللي عليهم رصيد — بالسكوب ═══
        $clients = Client::query()
            ->where('balance', '>', 0)
            ->where('status', 'active')
            ->with(['contract', 'channel', 'rep', 'group.contract'])
            ->when($repId, fn ($q) => $q->where('rep_id', $repId))
            ->when($managerId, fn ($q) => $q->where('manager_id', $managerId));

        if ($viewer !== null) {
            $clients = Client::visibleTo($clients, $viewer);
        }

        $clients = $clients->get()->filter(fn (Client $c) => $c->allowsCredit())->values();

        // كويري واحد لكل قيود المديونية — بدل كويري لكل عميل
        $byClient = Transaction::query()
            ->whereIn('client_id', $clients->pluck('id'))
            ->whereIn('kind', Transaction::DEBT_KINDS)
            ->orderByDesc('date')
            ->get()
            ->groupBy('client_id');

        $rows = collect();
        $overdueRows = collect();
        $noTermsRows = collect();
        $days = [];
        $later = ['total' => 0.0, 'count' => 0];
        $totalOpen = 0.0;
        $horizon = [15 => 0.0, 30 => 0.0, 60 => 0.0, 90 => 0.0];

        foreach ($clients as $client) {
            $client->setRelation('transactions', $byClient->get($client->id, collect()));

            foreach ($client->openDebts() as ['tx' => $t, 'open' => $open]) {
                $totalOpen += $open;
                $due = $client->dueDateOf($t);
                $row = self::row($client, $t, $open, $due, $today);

                if ($due === null) {
                    $noTermsRows->push($row);

                    continue;
                }

                // المتأخر يدخل في كل أفق (المفروض يدخل «فوراً»)
                if ($due->lt($today)) {
                    $overdueRows->push($row);
                    foreach ($horizon as $h => $v) {
                        $horizon[$h] += $open;
                    }

                    continue;
                }

                foreach ($horizon as $h => $v) {
                    if ($due->lte($today->copy()->addDays($h)->endOfDay())) {
                        $horizon[$h] += $open;
                    }
                }

                if ($due->gt($to)) {
                    $later['total'] += $open;
                    $later['count']++;

                    continue;
                }

                if ($due->lt($from)) {
                    // مستحق بين النهارده وبداية النافذة — مش متأخر ومش معروض
                    continue;
                }

                $rows->push($row);
                $k = $due->toDateString();
                $days[$k] ??= ['total' => 0.0, 'count' => 0];
                $days[$k]['total'] += $open;
                $days[$k]['count']++;
            }
        }

        ksort($days);
        foreach ($days as &$d) {
            $d['total'] = round($d['total'], 2);
        }
        unset($d);

        $sortDue = fn ($r) => ($r['due']?->toDateString() ?? '9999').'-'.$r['client'];

        return [
            'rows' => $rows->sortBy($sortDue)->values(),
            'days' => $days,
            'overdue' => [
                'total' => round((float) $overdueRows->sum('open'), 2),
                'count' => $overdueRows->count(),
                'rows' => $overdueRows->sortBy($sortDue)->values(),
            ],
            'no_terms' => [
                'total' => round((float) $noTermsRows->sum('open'), 2),
                'count' => $noTermsRows->count(),
                'rows' => $noTermsRows->sortBy($sortDue)->values(),
            ],
            'later' => ['total' => round($later['total'], 2), 'count' => $later['count']],
            'total_open' => round($totalOpen, 2),
            'horizon' => array_map(fn ($v) => round($v, 2), $horizon),
            'from' => $from,
            'to' => $to,
        ];
    }

    /** صف واحد — كل اللي الجدول والكالندر محتاجينه من غير ما يرجعوا للموديلات */
    private static function row(Client $client, Transaction $t, float $open, ?Carbon $due, Carbon $today): array
    {
        return [
            'client_id' => $client->id,
            'client' => $client->displayName(),
            'rep' => $client->rep?->displayName(),
            'channel' => $client->channel?->displayName(),
            'tx_id' => $t->id,
            'kind' => $t->kind,
            'doc' => $t->reference ?: ($t->memo ?: ''),
            'date' => $t->date,
            'debit' => round((float) $t->debit, 2),
            'open' => $open,
            'due' => $due,
            'days_late' => $due !== null && $due->lt($today) ? (int) $due->diffInDays($today) : 0,
            'invoice_id' => $t->source_type === \App\Models\Invoice::class ? $t->source_id : null,
            'terms' => $client->paymentDays(),
            'basis' => $client->paymentBasis(),
        ];
    }
}
