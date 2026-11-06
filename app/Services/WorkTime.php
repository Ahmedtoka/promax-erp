<?php

namespace App\Services;

use App\Http\Controllers\ActivityController;
use App\Models\ActivityLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * وقت الشغل الفعلي (٥ نوفمبر ٢٠٢٦ — جاد بس)
 * ═══════════════════════════════════════════════════════════════
 *
 * طلب المالك: «طلبت منه يعمل مازر كامبني… قعد ٣ ساعات والموضوع ١٥
 * دقيقة. فتح الشاشة ووقف عليها ساعة من غير حاجة، وبعدها ٥ دقايق
 * اشتغل، وفي الآخر داس كليك خلصت التاسك».
 *
 * المصدر نبضات المتصفح (`presence_pings`) — نبضة لكل تاب في الدقيقة.
 * اليوزر الفاتح كذا تاب بيتحسب **دقيقة واحدة** في الدقيقة بأحسن حالة
 * فيهم (شغال > قدامه وساكت > مستخبي) — وإلا الـ٣ تابات يبقوا ٣ ساعات.
 *
 *   فاتح       = عدد الدقايق اللي فيها نبضة
 *   شغال فعلاً  = دقايق فيها ماوس/كيبورد/سكرول
 *   ساكت       = الصفحة قدامه ومحدش لمسها
 *   مستخبية    = التاب ورا برنامج تاني أو متصغّر
 *   الأكشنات   = صفوف «شغل» من `activity_logs` (حفظ/تعديل/مسح/أكشن)
 *
 * ⚠️ الأرقام من الشروط دي بس — الموديل في «اتشيك» بيشرحها ومابيحسبش.
 */
final class WorkTime
{
    private const RANK = ['hidden' => 0, 'idle' => 1, 'active' => 2];

    /** الحدود — بالدقايق والنسبة المئوية */
    public const OPEN_NO_WORK_MIN = 120;     // فاتح ساعتين…

    public const OPEN_NO_WORK_ACTIONS = 3;   // …وأقل من ٣ أكشنات

    public const LONG_QUIET_MIN = 45;        // سكوت متواصل على السيستم

    public const LOW_RATIO = 20;             // شغل فعلي أقل من ٢٠٪ من الفتح

    public const MIN_OPEN_FOR_RATIO = 30;    // النسب مالهاش معنى تحت نص ساعة

    public const HIDDEN_RATIO = 50;          // الصفحة مستخبية أغلب الوقت

    public const GOOD_ACTIONS = 10;

    public const GOOD_RATIO = 50;

    /** فجوة أطول من كده بين نبضتين = قفل ورجع (فترة جديدة في الخط الزمني) */
    private const SEGMENT_GAP = 120;

    /**
     * دقايق كل يوزر: [uid => [minuteTs => [state, route, url]]] بالترتيب.
     *
     * @param  list<int>|null  $userIds
     * @return array<int, array<int, array{0:string,1:?string,2:?string}>>
     */
    public static function minutes(Carbon $a, Carbon $b, ?array $userIds = null): array
    {
        $out = [];

        DB::table('presence_pings')
            ->whereBetween('at', [$a, $b])
            ->when($userIds !== null, fn ($q) => $q->whereIn('user_id', $userIds))
            ->orderBy('id')
            ->select('id', 'user_id', 'state', 'route', 'url', 'at')
            ->chunk(5000, function ($chunk) use (&$out) {
                foreach ($chunk as $p) {
                    $ts = intdiv(strtotime($p->at), 60) * 60;
                    $cur = $out[$p->user_id][$ts] ?? null;

                    if ($cur === null || (self::RANK[$p->state] ?? 0) >= (self::RANK[$cur[0]] ?? 0)) {
                        $out[$p->user_id][$ts] = [$p->state, $p->route, $p->url];
                    }
                }
            });

        foreach ($out as &$m) {
            ksort($m);
        }

        return $out;
    }

    /**
     * أكشنات الشغل الفعلي لكل يوزر في الفترة.
     *
     * @param  list<int>|null  $userIds
     * @return array<int, list<array{ts:int,label:string,event:string,screen:string}>>
     */
    public static function actions(Carbon $a, Carbon $b, ?array $userIds = null): array
    {
        $out = [];

        ActivityLog::whereBetween('created_at', [$a, $b])
            ->whereNotNull('user_id')
            ->whereIn('event', ActivityLog::WORK)
            // الرفض بتاع الشاشة دي نفسها مش «شغل»
            ->where(fn ($q) => $q->whereNull('title')->orWhereNotIn('title', ['worktime_blocked', 'ai_blocked']))
            ->when($userIds !== null, fn ($q) => $q->whereIn('user_id', $userIds))
            ->orderBy('id')
            ->chunk(2000, function ($chunk) use (&$out) {
                foreach ($chunk as $r) {
                    $out[$r->user_id][] = [
                        'ts' => $r->created_at->getTimestamp(),
                        'label' => self::actionLabel($r),
                        'event' => $r->event,
                        'screen' => $r->screenLabel(),
                    ];
                }
            });

        return $out;
    }

    public static function actionLabel(ActivityLog $r): string
    {
        if (in_array($r->event, ['created', 'updated', 'deleted'], true)) {
            return trim(__('activity.e_'.$r->event).' '.ActivityController::modelLabel($r->subject_type).' '.$r->title);
        }

        $label = ActivityController::verbLabel((string) $r->routeName());

        return (int) $r->status >= 400 ? $label.' ✖' : $label;
    }

    /**
     * ملخص يوزر.
     *
     * @param  array<int, array{0:string,1:?string,2:?string}>  $minutes
     * @param  list<array{ts:int,label:string,event:string,screen:string}>  $actions
     * @return array<string, mixed>
     */
    public static function summarize(array $minutes, array $actions): array
    {
        $count = ['active' => 0, 'idle' => 0, 'hidden' => 0];
        $quiet = 0;
        $longest = 0;
        $prev = null;

        foreach ($minutes as $ts => [$state]) {
            $count[$state] = ($count[$state] ?? 0) + 1;

            // السكوت المتواصل بينكسر بأي حركة أو بقفل السيستم (فجوة)
            if ($state === 'active' || ($prev !== null && $ts - $prev > 60)) {
                $quiet = 0;
            }

            if ($state !== 'active') {
                $longest = max($longest, ++$quiet);
            }

            $prev = $ts;
        }

        $open = array_sum($count);
        $keys = array_keys($minutes);
        $first = $keys[0] ?? null;
        $last = $keys ? end($keys) + 60 : null;
        $acts = count($actions);

        if ($actions) {
            $first = $first === null ? $actions[0]['ts'] : min($first, $actions[0]['ts']);
            $last = $last === null ? end($actions)['ts'] : max($last, end($actions)['ts']);
        }

        $sum = [
            'open' => $open,
            'active' => $count['active'],
            'idle' => $count['idle'],
            'hidden' => $count['hidden'],
            'ratio' => $open > 0 ? (int) round($count['active'] * 100 / $open) : 0,
            'longest_quiet' => $longest,
            'actions' => $acts,
            'first' => $first,
            'last' => $last,
            'tracked' => $open > 0,
        ];

        $sum['flags'] = self::flags($sum);
        $sum['verdict'] = self::verdict($sum);

        return $sum;
    }

    /**
     * علامات «خلي بالك» — شروط بس، من غير موديل.
     *
     * @param  array<string, mixed>  $s
     * @return list<string>
     */
    public static function flags(array $s): array
    {
        $f = [];

        if ($s['open'] >= self::OPEN_NO_WORK_MIN && $s['actions'] < self::OPEN_NO_WORK_ACTIONS) {
            $f[] = 'open_no_work';
        }

        if ($s['longest_quiet'] >= self::LONG_QUIET_MIN) {
            $f[] = 'long_quiet';
        }

        if ($s['open'] >= self::MIN_OPEN_FOR_RATIO) {
            if ($s['ratio'] < self::LOW_RATIO) {
                $f[] = 'low_ratio';
            }

            if ($s['hidden'] * 100 / $s['open'] >= self::HIDDEN_RATIO) {
                $f[] = 'mostly_hidden';
            }
        }

        return $f;
    }

    /** good (شغال كويس) · watch (يستاهل تسأله) · light (حركة خفيفة) · none (مافتحش) */
    public static function verdict(array $s): string
    {
        if ($s['open'] === 0 && $s['actions'] === 0) {
            return 'none';
        }

        $red = array_intersect($s['flags'], ['open_no_work', 'long_quiet', 'low_ratio']);

        if ($red) {
            return 'watch';
        }

        if ($s['actions'] >= self::GOOD_ACTIONS
            || ($s['actions'] >= 3 && $s['ratio'] >= self::GOOD_RATIO)) {
            return 'good';
        }

        return 'light';
    }

    /**
     * الخط الزمني: فترات متتالية على نفس الصفحة، والأكشنات جوه فترتها.
     * الأكشن اللي مالوش فترة (من الأبلكيشن، أو قبل النبضات) بيطلع سطر لوحده.
     *
     * @param  array<int, array{0:string,1:?string,2:?string}>  $minutes
     * @param  list<array{ts:int,label:string,event:string,screen:string}>  $actions
     * @return list<array<string, mixed>>
     */
    public static function segments(array $minutes, array $actions): array
    {
        $segs = [];
        $cur = null;
        $prev = null;

        foreach ($minutes as $ts => [$state, $route, $url]) {
            if ($cur === null || $url !== $cur['url'] || $ts - $prev > self::SEGMENT_GAP) {
                if ($cur !== null) {
                    $segs[] = $cur;
                }

                $cur = ['from' => $ts, 'to' => $ts + 60, 'url' => $url, 'route' => $route,
                    'screen' => self::screenOf($route, (string) $url),
                    'active' => 0, 'idle' => 0, 'hidden' => 0, 'actions' => []];
            }

            $cur['to'] = $ts + 60;
            $cur[$state]++;
            $prev = $ts;
        }

        if ($cur !== null) {
            $segs[] = $cur;
        }

        foreach ($actions as $act) {
            $placed = false;

            foreach ($segs as &$s) {
                // الحفظ بيحصل في نفس دقيقة الصفحة أو بعدها بشوية (الريدايركت)
                if (isset($s['url']) && $act['ts'] >= $s['from'] && $act['ts'] <= $s['to'] + 60) {
                    $s['actions'][] = $act;
                    $placed = true;
                    break;
                }
            }
            unset($s);

            if (! $placed) {
                $segs[] = ['from' => $act['ts'], 'to' => $act['ts'], 'url' => null, 'route' => null,
                    'screen' => $act['screen'], 'active' => 0, 'idle' => 0, 'hidden' => 0,
                    'actions' => [$act], 'loose' => true];
            }
        }

        usort($segs, fn ($x, $y) => $x['from'] <=> $y['from']);

        return $segs;
    }

    /** اسم الشاشة من المنيو + رقم المستند لو الـURL فيه رقم */
    public static function screenOf(?string $route, string $url): string
    {
        $nav = $route ? ActivityLog::navOf($route) : null;
        $name = $nav ? __($nav[1]) : ($route ?: $url);

        if (preg_match('~/(\d+)(?:/[a-z_-]+)?$~', $url, $m)) {
            $name .= ' #'.$m[1];
        }

        return $name;
    }
}
