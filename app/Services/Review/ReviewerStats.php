<?php

namespace App\Services\Review;

use App\Models\DocReviewEvent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * المراجِع بيراجع فعلاً ولا بيدوس ✓ ✓ ✓؟ (طلب أحمد ١٠/١١)
 *
 * لكل يوزر ولكل يوم: كام مستند، الوسيط والـp10 للثواني بين مراجعة
 * والتانية، وسيط وقت فتح المستند، المراجعات السريعة (أقل من
 * FAST_SECONDS) والسلاسل السريعة (FAST_STREAK ورا بعض = علم أحمر)،
 * والتعديلات والفلوس اللي اتغيّرت.
 */
class ReviewerStats
{
    /**
     * @return list<array<string, mixed>> صف لكل (يوزر، يوم) — الأحدث الأول
     */
    public static function days(Carbon $from, Carbon $to, ?int $userId = null): array
    {
        $events = DocReviewEvent::query()
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->orderBy('id')->get();

        $names = User::whereIn('id', $events->pluck('user_id')->filter()->unique())->get()->keyBy('id');
        $out = [];

        foreach ($events->groupBy(fn ($e) => $e->user_id.'|'.$e->created_at->toDateString()) as $k => $evs) {
            [$uid, $day] = explode('|', $k);
            $reviews = $evs->whereIn('action', DocReviewEvent::REVIEW_ACTIONS)->values();
            $edits = $evs->whereIn('action', DocReviewEvent::EDIT_ACTIONS);
            $streaks = self::streaks($reviews);

            $out[] = [
                'user_id' => (int) $uid,
                'user' => $names[$uid]?->displayName() ?? '#'.$uid,
                'day' => $day,
                'reviews' => $reviews->count(),
                'ok' => $reviews->where('action', 'ok')->count(),
                'issues' => $reviews->where('action', 'issue')->count(),
                'median_s' => self::pct($reviews->pluck('seconds_since_prev')->filter(fn ($v) => $v !== null), 50),
                'p10_s' => self::pct($reviews->pluck('seconds_since_prev')->filter(fn ($v) => $v !== null), 10),
                'open_s' => ($m = self::pct($reviews->pluck('open_ms')->filter(fn ($v) => $v !== null), 50)) === null ? null : round($m / 1000, 1),
                'fast' => $reviews->filter(fn ($e) => self::isFast($e))->count(),
                'streaks' => $streaks,
                'flag' => $streaks !== [],
                'edits' => $edits->count(),
                'edits_by' => $edits->countBy('action')->all(),
                'money_abs' => round($edits->sum(fn ($e) => abs((float) $e->grand_delta)), 2),
                'money_net' => round($edits->sum(fn ($e) => (float) $e->grand_delta), 2),
                'reopens' => $evs->where('action', 'reopen')->count(),
                'first' => $evs->first()->created_at->format('H:i'),
                'last' => $evs->last()->created_at->format('H:i'),
            ];
        }

        usort($out, fn ($a, $b) => [$b['day'], $b['reviews']] <=> [$a['day'], $a['reviews']]);

        return $out;
    }

    public static function isFast(DocReviewEvent $e): bool
    {
        return $e->seconds_since_prev !== null && $e->seconds_since_prev < DocReviewEvent::FAST_SECONDS;
    }

    /**
     * السلاسل السريعة: FAST_STREAK مراجعات سريعة أو أكتر ورا بعض.
     *
     * @return list<array{from: string, to: string, n: int, ids: list<int>}>
     */
    public static function streaks(Collection $reviews): array
    {
        $out = [];
        $run = [];
        $flush = function () use (&$run, &$out) {
            if (count($run) >= DocReviewEvent::FAST_STREAK) {
                $out[] = [
                    'from' => $run[0]->created_at->format('H:i:s'),
                    'to' => end($run)->created_at->format('H:i:s'),
                    'n' => count($run),
                    'ids' => array_map(fn ($e) => $e->id, $run),
                ];
            }
            $run = [];
        };

        foreach ($reviews as $e) {
            self::isFast($e) ? $run[] = $e : $flush();
        }
        $flush();

        return $out;
    }

    private static function pct(Collection $vals, int $p): ?float
    {
        $v = $vals->map(fn ($x) => (float) $x)->sort()->values();
        if ($v->isEmpty()) {
            return null;
        }
        $i = ($p / 100) * ($v->count() - 1);
        $lo = (int) floor($i);
        $hi = (int) ceil($i);

        return round($v[$lo] + ($v[$hi] - $v[$lo]) * ($i - $lo), 1);
    }

    /** هدف اليوم لكل مستند — قابل للتعديل من هنا لحد ما يبقى ليه إعداد */
    public const DAILY_TARGET = 150;

    /**
     * (١٢/١١) سرعة الإنجاز والمتوقع: ✓ النهارده، متوسط آخر ٥ أيام اشتغل فيها
     * حد، والباقي ÷ المتوسط = كام يوم شغل لحد ١٠٠٪. لكل مراجِع ولإجمالي.
     *
     * @return array{today:int, avg:float, remaining:int, eta_days:?int, target:int, people: list<array{user:string, today:int, avg:float}>}
     */
    public static function pace(int $remaining): array
    {
        $since = today()->subDays(30);
        $rows = DocReviewEvent::where('action', 'ok')->where('created_at', '>=', $since)
            ->selectRaw('user_id, DATE(created_at) AS d, COUNT(*) AS n')->groupBy('user_id', 'd')->get();

        $byDay = $rows->groupBy('d')->map(fn ($g) => (int) $g->sum('n'))->sortKeys();
        $todayKey = today()->toDateString();
        $today = (int) ($byDay[$todayKey] ?? 0);
        $recent = $byDay->except($todayKey)->take(-5);
        $avg = $recent->isEmpty() ? (float) $today : round($recent->avg(), 1);

        $names = User::whereIn('id', $rows->pluck('user_id')->unique())->get()->keyBy('id');
        $people = $rows->groupBy('user_id')->map(function ($g, $uid) use ($names, $todayKey) {
            $days = $g->keyBy('d');
            $past = $g->where('d', '!=', $todayKey)->sortBy('d')->take(-5);

            return [
                'user' => $names[$uid]?->displayName() ?? '#'.$uid,
                'today' => (int) ($days[$todayKey]->n ?? 0),
                'avg' => $past->isEmpty() ? 0.0 : round($past->avg('n'), 1),
            ];
        })->sortByDesc('today')->values()->all();

        return [
            'today' => $today, 'avg' => $avg, 'remaining' => $remaining,
            'eta_days' => $avg > 0 ? (int) ceil($remaining / $avg) : null,
            'target' => self::DAILY_TARGET, 'people' => $people,
        ];
    }
}
