<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\WorkTime;
use App\Support\Access;
use App\Support\DateRange;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * ═══════════════════════════════════════════════════════════════
 * وقت الشغل الفعلي (٥ نوفمبر ٢٠٢٦) — جاد بس
 * ═══════════════════════════════════════════════════════════════
 *
 * «بيستخدم السيستم ولا لا، لو بيستخدمه بيعمل إيه، ووقت قد إيه».
 * الحساب كله في `WorkTime`، والقفل على الراوتات (`WorkTimeOnly`).
 * النبضة (`ping`) بس هي اللي مفتوحة لكل يوزر — دي اللي بتملا الجدول.
 */
class WorkTimeController extends Controller
{
    private const MAX_DAYS = 31;

    // ═══════════════════ النبضة — كل يوزر ويب كل دقيقة ═══════════════════

    public function ping(Request $request)
    {
        $data = $request->validate([
            'tab' => ['required', 'string', 'max:16', 'regex:/^[A-Za-z0-9]+$/'],
            'state' => ['required', 'in:active,idle,hidden'],
            'url' => ['nullable', 'string', 'max:300'],
        ]);

        $url = ltrim((string) parse_url((string) ($data['url'] ?? ''), PHP_URL_PATH), '/');
        $at = now()->startOfMinute();

        // نبضة واحدة للتاب في الدقيقة — التكرار (رفرش، تايمر اتأخر) بيحدّث نفس الصف
        $row = DB::table('presence_pings')->where('user_id', $request->user()->id)
            ->where('tab', $data['tab'])->where('at', $at)->first();

        if ($row) {
            DB::table('presence_pings')->where('id', $row->id)->update(['state' => $data['state'], 'url' => mb_substr($url, 0, 300)]);
        } else {
            DB::table('presence_pings')->insert([
                'user_id' => $request->user()->id,
                'tab' => $data['tab'],
                'state' => $data['state'],
                'route' => mb_substr((string) ActivityLog::routeOfUrl($url), 0, 120) ?: null,
                'url' => mb_substr($url, 0, 300),
                'at' => $at,
            ]);
        }

        return response()->noContent();
    }

    // ═══════════════════ ١. كل الموظفين ═══════════════════

    public function index(Request $request)
    {
        [$a, $b, $from, $to] = $this->range($request);
        $board = $this->board($a, $b);

        $opened = array_filter($board, fn ($r) => $r['sum']['verdict'] !== 'none');
        $kpi = [
            'opened' => count($opened),
            'none' => count($board) - count($opened),
            'open' => array_sum(array_map(fn ($r) => $r['sum']['open'], $board)),
            'active' => array_sum(array_map(fn ($r) => $r['sum']['active'], $board)),
            'actions' => array_sum(array_map(fn ($r) => $r['sum']['actions'], $board)),
            'watch' => count(array_filter($board, fn ($r) => $r['sum']['verdict'] === 'watch')),
        ];
        $kpi['ratio'] = $kpi['open'] > 0 ? (int) round($kpi['active'] * 100 / $kpi['open']) : 0;

        $verdict = $request->string('verdict')->value();
        $rows = $verdict ? array_values(array_filter($board, fn ($r) => $r['sum']['verdict'] === $verdict)) : $board;

        return view('erp.worktime', [
            'rows' => $rows, 'kpi' => $kpi, 'verdict' => $verdict,
            'from' => $from, 'to' => $to, 'since' => $this->since(),
            'single' => $from === $to,
        ]);
    }

    // ═══════════════════ ٢. يوم موظف — الخط الزمني ═══════════════════

    public function user(Request $request, User $user)
    {
        $day = DateRange::day($request->query('day')) ?? today();
        $a = $day->copy()->startOfDay();
        $b = $day->copy()->endOfDay();

        $minutes = WorkTime::minutes($a, $b, [$user->id])[$user->id] ?? [];
        $actions = WorkTime::actions($a, $b, [$user->id])[$user->id] ?? [];

        return view('erp.worktime_user', [
            'user' => $user,
            'day' => $day,
            'sum' => WorkTime::summarize($minutes, $actions),
            'segments' => WorkTime::segments($minutes, $actions),
            'bar' => $this->bar($minutes),
            'since' => $this->since(),
        ]);
    }

    // ═══════════════════ ٣. «اتشيك» — خلاصة بالموديل ═══════════════════

    /**
     * ⚠️ الموديل بياخد **الأرقام اللي اتحسبت** بس (أسامي، رولز، دقايق،
     * عدد أكشنات، علامات، أكتر شاشات سكوت) — من غير بيانات عملاء ولا
     * أسعار. والنتيجة متخزنة ربع ساعة لنفس الأرقام عشان الدوس المكرر
     * مايتحاسبش مرتين.
     */
    public function check(Request $request)
    {
        [$a, $b, $from, $to] = $this->range($request);
        $board = $this->board($a, $b, true);

        $people = array_map(fn ($r) => [
            'name' => $r['user']->displayName(),
            'role' => $r['user']->roleLabel(),
            'first' => $r['sum']['first'] ? date('H:i', $r['sum']['first']) : null,
            'last' => $r['sum']['last'] ? date('H:i', $r['sum']['last']) : null,
            'open_min' => $r['sum']['open'],
            'working_min' => $r['sum']['active'],
            'quiet_min' => $r['sum']['idle'],
            'hidden_min' => $r['sum']['hidden'],
            'longest_quiet_min' => $r['sum']['longest_quiet'],
            'actions' => $r['sum']['actions'],
            'flags' => array_map(fn ($f) => __('worktime.f_'.$f), $r['sum']['flags']),
            'verdict' => __('worktime.v_'.$r['sum']['verdict']),
            'quiet_screens' => $r['quiet_screens'],
            'did' => $r['did'],
        ], $board);

        $payload = ['from' => $from, 'to' => $to, 'tracking_since' => $this->since(), 'people' => $people];
        $key = 'worktime_check_'.md5(json_encode($payload).app()->getLocale());

        $apiKey = (string) config('agents.key');

        if ($apiKey === '') {
            return response()->json(['ok' => false, 'message' => __('worktime.ai_not_configured')]);
        }

        $text = Cache::get($key);

        if ($text === null) {
            $resp = rescue(fn () => Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
            ])->timeout((int) config('agents.timeout', 30))->post('https://api.anthropic.com/v1/messages', [
                'model' => (string) config('agents.model'),
                'max_tokens' => 1200,
                'system' => __('worktime.ai_system'),
                'messages' => [['role' => 'user', 'content' => json_encode($payload, JSON_UNESCAPED_UNICODE)]],
            ]), null, false);

            if (! $resp || ! $resp->successful()) {
                logger()->warning('worktime check api error', ['status' => $resp?->status(), 'body' => mb_substr((string) $resp?->body(), 0, 300)]);

                return response()->json(['ok' => false, 'message' => __('worktime.ai_failed')]);
            }

            $text = trim(collect($resp->json('content', []))->where('type', 'text')->pluck('text')->implode("\n"));
            Cache::put($key, $text, now()->addMinutes(15));
        }

        return response()->json(['ok' => true, 'text' => $text]);
    }

    // ═══════════════════ مساعدات ═══════════════════

    /**
     * صف لكل موظف ويب شغال — حتى اللي مافتحش (هو ده «مين مافتحش النهارده»).
     *
     * @return list<array<string, mixed>>
     */
    private function board(Carbon $a, Carbon $b, bool $detail = false): array
    {
        $users = User::where('active', true)->webUsers()->orderBy('name')->get();
        $ids = $users->pluck('id')->all();
        $minutes = WorkTime::minutes($a, $b, $ids);
        $actions = WorkTime::actions($a, $b, $ids);

        $board = [];

        foreach ($users as $u) {
            $m = $minutes[$u->id] ?? [];
            $acts = $actions[$u->id] ?? [];
            $row = ['user' => $u, 'sum' => WorkTime::summarize($m, $acts)];

            if ($detail) {
                $row['quiet_screens'] = $this->quietScreens($m);
                $row['did'] = array_slice(array_map(fn ($x) => date('H:i', $x['ts']).' '.$x['label'], $acts), -15);
            }

            $board[] = $row;
        }

        $order = ['watch' => 0, 'light' => 1, 'good' => 2, 'none' => 3];
        usort($board, fn ($x, $y) => [$order[$x['sum']['verdict']], -$x['sum']['open']]
            <=> [$order[$y['sum']['verdict']], -$y['sum']['open']]);

        return $board;
    }

    /** أكتر ٣ شاشات فضل عليها ساكت — للموديل */
    private function quietScreens(array $minutes): array
    {
        $by = [];

        foreach ($minutes as [$state, $route, $url]) {
            if ($state !== 'active') {
                $name = WorkTime::screenOf($route, (string) $url);
                $by[$name] = ($by[$name] ?? 0) + 1;
            }
        }

        arsort($by);

        return array_slice($by, 0, 3, true);
    }

    /**
     * شريط اليوم: قطع متتالية بنفس الحالة، والفجوات = «مقفول».
     *
     * @return list<array{state:string,from:int,min:int}>
     */
    private function bar(array $minutes): array
    {
        $out = [];
        $prev = null;

        foreach ($minutes as $ts => [$state]) {
            if ($prev !== null && $ts - $prev > 60) {
                $out[] = ['state' => 'off', 'from' => $prev + 60, 'min' => intdiv($ts - $prev - 60, 60)];
            }

            $last = $out ? count($out) - 1 : null;

            if ($last !== null && $out[$last]['state'] === $state) {
                $out[$last]['min']++;
            } else {
                $out[] = ['state' => $state, 'from' => $ts, 'min' => 1];
            }

            $prev = $ts;
        }

        return $out;
    }

    /** أول نبضة اتسجلت — قبلها الأرقام الدقيقة مش موجودة */
    private function since(): ?string
    {
        $at = DB::table('presence_pings')->min('at');

        return $at ? Carbon::parse($at)->toDateString() : null;
    }

    /** @return array{0:Carbon,1:Carbon,2:string,3:string} */
    private function range(Request $request): array
    {
        $r = DateRange::fromRequest($request, 'today');
        $a = $r->from ?? today();
        $b = $r->to ?? today()->endOfDay();

        if ($a->diffInDays($b) > self::MAX_DAYS) {
            $a = $b->copy()->subDays(self::MAX_DAYS)->startOfDay();
        }

        return [$a, $b, $a->toDateString(), $b->toDateString()];
    }
}
