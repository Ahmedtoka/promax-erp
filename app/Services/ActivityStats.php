<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * أرقام مركز نشاط المستخدمين (٢٢ سبتمبر ٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * طلب المالك: «مين شغال وبيفرك في السيستم… وقولي هو أكتيف ولا بيفتح
 * السيستم وبين كل أكشن والتانية ساعات».
 *
 * السجل صفوف منفصلة (حركة بوقتها) — الحكم على اليوزر محتاج **جلسات**:
 * حركات ورا بعض الفجوة بينها أقل من 30 دقيقة = جلسة شغل واحدة، وأي
 * فجوة أطول = قام ورجع. من الجلسات بيطلع كل حاجة:
 *
 *   وقت الشغل   = Σ (آخر حركة − أول حركة) لكل جلسة، ودقيقة على الأقل
 *   الإيقاع     = متوسط الفجوة بين حركتين **جوه** الجلسات
 *   أطول سكوت  = أكبر فجوة بين جلستين في نفس اليوم
 *   نسبة الشغل  = (إنشاء + تعديل + مسح + أكشن) ÷ كل الحركات
 *
 * ⚠️ وقت الشغل ده «وقت على السيستم» مش ساعات عمل — الحضور ليه شاشته.
 * ⚠️ كله بيتحسب في PHP على صفوف الفترة (3 أعمدة بس) — الفترة متقيدة
 * بـ92 يوم في الكنترولر فالحجم معروف.
 */
final class ActivityStats
{
    /** الفجوة اللي بتقفل الجلسة، بالثواني */
    private const GAP = ActivityLog::IDLE_MIN * 60;

    /**
     * صفوف الفترة ملمومة لكل يوزر: [user_id => list<[ts, event]>] بالترتيب الزمني.
     *
     * @param  list<int>|null  $userIds
     * @return array<int, list<array{0:int,1:string}>>
     */
    public static function rows(Carbon $a, Carbon $b, ?array $userIds = null): array
    {
        $out = [];

        DB::table('activity_logs')
            ->whereNotNull('user_id')
            ->whereBetween('created_at', [$a, $b])
            ->when($userIds !== null, fn ($q) => $q->whereIn('user_id', $userIds))
            ->orderBy('id')
            ->select('id', 'user_id', 'event', 'created_at')
            ->chunk(5000, function ($chunk) use (&$out) {
                foreach ($chunk as $r) {
                    $out[$r->user_id][] = [strtotime($r->created_at), $r->event];
                }
            });

        return $out;
    }

    /**
     * جلسات يوزر واحد من صفوفه.
     *
     * @param  list<array{0:int,1:string}>  $rows
     * @return list<array{start:int,end:int,n:int,work:int,views:int,gap_before:?int}>
     */
    public static function sessions(array $rows): array
    {
        $sessions = [];
        $cur = null;
        $prev = null;

        foreach ($rows as [$ts, $event]) {
            if ($cur === null || $ts - $prev > self::GAP) {
                if ($cur !== null) {
                    $sessions[] = $cur;
                }

                $cur = ['start' => $ts, 'end' => $ts, 'n' => 0, 'work' => 0, 'views' => 0,
                    'gap_before' => $prev === null ? null : $ts - $prev];
            }

            $cur['end'] = $ts;
            $cur['n']++;

            if (in_array($event, ActivityLog::WORK, true)) {
                $cur['work']++;
            } elseif ($event === 'viewed') {
                $cur['views']++;
            }

            $prev = $ts;
        }

        if ($cur !== null) {
            $sessions[] = $cur;
        }

        return $sessions;
    }

    /**
     * ملخص يوزر واحد.
     *
     * @param  list<array{0:int,1:string}>  $rows
     * @return array<string, mixed>
     */
    public static function summarize(array $rows): array
    {
        $sessions = self::sessions($rows);
        $events = array_count_values(array_column($rows, 1));

        $seconds = 0;
        $inGaps = 0;
        $inGapCount = 0;
        $longestBreak = 0;

        foreach ($sessions as $s) {
            $seconds += max(60, $s['end'] - $s['start']);
            $inGaps += $s['end'] - $s['start'];
            $inGapCount += max(0, $s['n'] - 1);

            // السكوت بين جلستين في **نفس اليوم** بس — فجوة الليل مش سكوت
            if ($s['gap_before'] !== null
                && date('Y-m-d', $s['start']) === date('Y-m-d', $s['start'] - $s['gap_before'])) {
                $longestBreak = max($longestBreak, $s['gap_before']);
            }
        }

        $work = 0;

        foreach (ActivityLog::WORK as $e) {
            $work += $events[$e] ?? 0;
        }

        $total = count($rows);
        $days = count(array_unique(array_map(fn ($r) => date('Y-m-d', $r[0]), $rows)));

        return [
            'total' => $total,
            'work' => $work,
            'views' => $events['viewed'] ?? 0,
            'created' => $events['created'] ?? 0,
            'updated' => $events['updated'] ?? 0,
            'deleted' => $events['deleted'] ?? 0,
            'actions' => $events['action'] ?? 0,
            'logins' => $events['login'] ?? 0,
            'sessions' => count($sessions),
            'days' => $days,
            'minutes' => (int) round($seconds / 60),
            'pace' => $inGapCount > 0 ? (int) round($inGaps / $inGapCount) : null,   // ثواني بين الحركتين
            'longest_break' => (int) round($longestBreak / 60),                       // دقايق
            'work_ratio' => $total > 0 ? round($work * 100 / $total) : 0,
            'first' => $rows ? $rows[0][0] : null,
            'last' => $rows ? $rows[count($rows) - 1][0] : null,
            'verdict' => self::verdict($total, $work, $days),
        ];
    }

    /**
     * الحكم: working (بيشتغل) · browsing (بيفتح ويتفرج) · light (حركة خفيفة) · none.
     *
     * ⚠️ الحدود على **متوسط اليوم** مش على الإجمالي — أسبوع فيه 40 حركة
     * شغل حاجة، ويوم واحد فيه 40 حاجة تانية.
     */
    public static function verdict(int $total, int $work, int $days): string
    {
        if ($total === 0) {
            return 'none';
        }

        $perDay = $total / max(1, $days);
        $workPerDay = $work / max(1, $days);

        if ($workPerDay >= 5 || ($work > 0 && $work / $total >= 0.2)) {
            return 'working';
        }

        return $perDay >= 15 ? 'browsing' : 'light';
    }

    /** «3 س 20 د» / «45 د» */
    public static function duration(int $minutes): string
    {
        if ($minutes < 60) {
            return __('activity.d_min', ['m' => $minutes]);
        }

        return __('activity.d_hm', ['h' => intdiv($minutes, 60), 'm' => $minutes % 60]);
    }

    /** «12 ث» / «4 د» / «2 س 10 د» — للفجوات */
    public static function gap(?int $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }

        return $seconds < 60
            ? __('activity.d_sec', ['s' => $seconds])
            : self::duration((int) round($seconds / 60));
    }

    /**
     * بادئات راوتس قسم معيّن — لفلتر «القسم» في SQL.
     *
     * @return list<string>
     */
    public static function prefixesOf(string $section): array
    {
        return collect(\App\Support\Access::NAV[$section] ?? [])
            ->map(fn ($link) => rtrim($link[0], '.'))->unique()->values()->all();
    }

    /** @param Collection<int, ActivityLog> $rows يضيف `gap` (ثواني من الحركة اللي قبلها لنفس اليوزر) */
    public static function withGaps(Collection $rows): Collection
    {
        $last = [];

        foreach ($rows->sortBy('id') as $row) {
            $ts = $row->created_at->getTimestamp();
            $row->gap = isset($last[$row->user_id]) ? $ts - $last[$row->user_id] : null;
            $last[$row->user_id] = $ts;
        }

        return $rows;
    }
}
