@extends('layouts.system')

@section('title', __('activity.page'))

@php
    use App\Models\ActivityLog;
    use App\Services\ActivityStats as AS_;

    $fmt = fn ($n) => number_format((float) $n);
    $tabUrl = fn (string $t, array $x = []) => route('erp.activity', ['tab' => $t] + $x);

    // لون الحدث — التعديل والمسح لازم يبانوا وسط ضوضاء الزيارات
    $tone = fn ($e) => match ($e) {
        'created' => 'b-green', 'updated' => 'b-blue', 'deleted' => 'b-red',
        'login' => 'b-purple', 'action' => 'b-orange', default => 'b-gray',
    };
    $stateTone = ['active' => 'b-green', 'idle' => 'b-orange', 'away' => 'b-gray'];
    $verdictTone = ['working' => 'b-green', 'browsing' => 'b-orange', 'light' => 'b-gray', 'none' => 'b-red'];
    $todayStr = today()->toDateString();
@endphp

@section('actions')
    @if ($tab !== 'now')
        <a class="btn sm green" href="{{ request()->fullUrlWithQuery(['export' => 1, 'page' => null]) }}">⬇ {{ __('ui.export_all') }}</a>
    @endif
@endsection

@section('content')

{{-- التابات: دلوقتي · ملخص اليوزرات · السجل الكامل --}}
<div class="rng-q" style="margin-bottom:14px;gap:8px">
    @foreach (['now' => '🟢', 'users' => '🧑‍💼', 'log' => '🧾'] as $t => $ico)
        <a href="{{ $tabUrl($t) }}" @class(['on' => $tab === $t]) style="font-size:13px;padding:8px 16px">{{ $ico }} {{ __('activity.tab_'.$t) }}</a>
    @endforeach
</div>

{{-- ═══════════════════════ ١. مين شغال دلوقتي ═══════════════════════ --}}
@if ($tab === 'now')

<div class="kpis">
    <a class="kpi @if($state === 'active') on @endif" href="{{ $tabUrl('now', $state === 'active' ? [] : ['state' => 'active']) }}">
        <div class="lbl">🟢 {{ __('activity.k_active') }}</div><div class="val" style="color:var(--green)">{{ $fmt($kpi['active']) }}</div>
        <div class="sub2">{{ __('activity.k_active_hint', ['m' => ActivityLog::ACTIVE_MIN]) }}</div></a>
    <a class="kpi @if($state === 'idle') on @endif" href="{{ $tabUrl('now', $state === 'idle' ? [] : ['state' => 'idle']) }}">
        <div class="lbl">🟠 {{ __('activity.k_idle') }}</div><div class="val">{{ $fmt($kpi['idle']) }}</div>
        <div class="sub2">{{ __('activity.k_idle_hint', ['a' => ActivityLog::ACTIVE_MIN, 'b' => ActivityLog::IDLE_MIN]) }}</div></a>
    <a class="kpi @if($state === 'away') on @endif" href="{{ $tabUrl('now', $state === 'away' ? [] : ['state' => 'away']) }}">
        <div class="lbl">⚪ {{ __('activity.k_away') }}</div><div class="val">{{ $fmt($kpi['away']) }}</div>
        <div class="sub2">{{ __('activity.k_away_hint') }}</div></a>
    <a class="kpi" href="{{ $tabUrl('log', ['event' => 'work', 'from' => $todayStr, 'to' => $todayStr]) }}">
        <div class="lbl">✏️ {{ __('activity.k_work_today') }}</div><div class="val" style="color:var(--primary)">{{ $fmt($kpi['work']) }}</div>
        <div class="sub2">{{ __('activity.k_work_hint') }}</div></a>
    <a class="kpi" href="{{ $tabUrl('log', ['event' => 'deleted', 'from' => $todayStr, 'to' => $todayStr]) }}">
        <div class="lbl">🗑 {{ __('activity.k_deleted_today') }}</div><div class="val" style="color:var(--red)">{{ $fmt($kpi['deleted']) }}</div></a>
    <a class="kpi" href="{{ $tabUrl('log', ['failed' => 1, 'from' => $todayStr, 'to' => $todayStr]) }}">
        <div class="lbl">✖ {{ __('activity.k_failed_today') }}</div><div class="val">{{ $fmt($kpi['failed']) }}</div>
        <div class="sub2">{{ __('activity.k_failed_hint') }}</div></a>
</div>

<div class="card">
    <h3>🟢 {{ __('activity.board_title') }} <span class="side">{{ now()->format('h:i A') }} · {{ __('activity.auto_refresh') }}</span></h3>
    <div class="tablewrap">
        <table data-page="50">
            <thead><tr>
                <th>{{ __('activity.c_user') }}</th><th>{{ __('activity.c_state') }}</th>
                <th>{{ __('activity.c_where') }}</th><th>{{ __('activity.c_last_work') }}</th>
                <th data-nosum>{{ __('activity.c_session_start') }}</th>
                <th data-nosum>{{ __('activity.c_minutes_today') }}</th>
                <th>{{ __('activity.c_views') }}</th><th>{{ __('activity.c_work') }}</th>
                <th data-nosum>{{ __('activity.c_pace') }}</th><th>{{ __('activity.c_verdict') }}</th>
            </tr></thead>
            <tbody>
            @forelse ($board as $b)
                @php $u = $b['user']; $l = $b['last']; $w = $b['last_work']; @endphp
                <tr class="clickable" onclick="location.href='{{ route('erp.activity.user', ['user' => $u, 'from' => $todayStr, 'to' => $todayStr]) }}'">
                    <td><b>{{ $u->displayName() }}</b><br><span style="font-size:10.5px;color:var(--muted)">{{ $u->roleLabel() }}</span></td>
                    <td>
                        <span class="badge {{ $stateTone[$b['state']] }}">{{ __('activity.s_'.$b['state']) }}</span>
                        <br><span style="font-size:10.5px;color:var(--muted)">{{ $l?->created_at?->diffForHumans() }}</span>
                    </td>
                    <td style="font-size:11.5px;white-space:normal">
                        @if ($l)
                            {{ $b['app'] ? '📱' : '🖥' }} <b>{{ $l->screenLabel() }}</b>
                            <br><span style="color:var(--muted)">{{ ActivityLog::sectionLabel($l->sectionKey()) }}</span>
                        @endif
                    </td>
                    <td style="font-size:11.5px;white-space:normal">
                        @if ($w)
                            <span class="badge {{ $tone($w->event) }}">{{ __('activity.e_'.$w->event) }}</span>
                            {{ $w->subject_type ? \App\Http\Controllers\ActivityController::modelLabel($w->subject_type).' '.$w->title : \App\Http\Controllers\ActivityController::verbLabel((string) $w->routeName()) }}
                            <br><span style="color:var(--muted)">{{ $w->created_at->format('h:i A') }}</span>
                        @else
                            <span style="color:var(--muted)">{{ __('activity.no_work_today') }}</span>
                        @endif
                    </td>
                    <td class="num" style="font-size:11.5px">{{ $b['session_start'] ? date('h:i A', $b['session_start']) : '—' }}</td>
                    <td class="num">{{ AS_::duration($b['sum']['minutes']) }}</td>
                    <td class="num">{{ $fmt($b['sum']['views']) }}</td>
                    <td class="num"><b>{{ $fmt($b['sum']['work']) }}</b></td>
                    <td class="num" style="font-size:11.5px">{{ AS_::gap($b['sum']['pace']) }}</td>
                    <td><span class="badge {{ $verdictTone[$b['sum']['verdict']] }}">{{ __('activity.v_'.$b['sum']['verdict']) }}</span></td>
                </tr>
            @empty
                <tr><td colspan="10" style="text-align:center;color:var(--muted);padding:26px">{{ __('activity.nobody_today') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <p style="font-size:11.5px;color:var(--muted);margin-top:10px">ℹ️ {{ __('activity.legend') }}</p>
</div>

@if ($silent->isNotEmpty() && ! $state)
<div class="card">
    <h3>🔕 {{ __('activity.silent_title') }} <span class="side">{{ $fmt($silent->count()) }}</span></h3>
    <div style="display:flex;flex-wrap:wrap;gap:6px">
        @foreach ($silent as $u)
            <a class="badge b-gray" style="text-decoration:none" href="{{ route('erp.activity.user', $u) }}">{{ $u->displayName() }} · {{ $u->roleLabel() }}</a>
        @endforeach
    </div>
</div>
@endif

@endif

{{-- ═══════════════════════ ٢. ملخص اليوزرات ═══════════════════════ --}}
@if ($tab === 'users')

<div class="kpis">
    @foreach (['working', 'browsing', 'light', 'none'] as $v)
        <a class="kpi @if(($filters['verdict'] ?? '') === $v) on @endif"
           href="{{ request()->fullUrlWithQuery(['verdict' => ($filters['verdict'] ?? '') === $v ? null : $v]) }}">
            <div class="lbl"><span class="badge {{ $verdictTone[$v] }}">{{ __('activity.v_'.$v) }}</span></div>
            <div class="val">{{ $fmt($verdicts[$v] ?? 0) }}</div>
            <div class="sub2">{{ __('activity.v_'.$v.'_hint') }}</div></a>
    @endforeach
    <a class="kpi" href="{{ $tabUrl('log', ['event' => 'work', 'from' => $from, 'to' => $to]) }}">
        <div class="lbl">✏️ {{ __('activity.c_work') }}</div><div class="val" style="color:var(--primary)">{{ $fmt($T['work']) }}</div>
        <div class="sub2">{{ __('activity.of_total', ['n' => $fmt($T['total'])]) }}</div></a>
</div>

<div class="card">
    <form class="searchbar" method="GET">
        <input type="hidden" name="tab" value="users">
        <label class="fl grow"><span>{{ __('ui.l_search') }}</span>
            <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="🔍 {{ __('activity.search_user_ph') }}"></label>
        <label class="fl"><span>{{ __('activity.c_role') }}</span>
            <select name="role">
                <option value="">{{ __('ui.all_of', ['x' => __('activity.roles')]) }}</option>
                @foreach ($roles as $ro)
                    <option value="{{ $ro }}" @selected(($filters['role'] ?? '') === $ro)>{{ __('enums.role.'.$ro) }}</option>
                @endforeach
            </select></label>
        <label class="fl"><span>{{ __('activity.c_verdict') }}</span>
            <select name="verdict">
                <option value="">{{ __('common.all') }}</option>
                @foreach (['working', 'browsing', 'light', 'none'] as $v)
                    <option value="{{ $v }}" @selected(($filters['verdict'] ?? '') === $v)>{{ __('activity.v_'.$v) }}</option>
                @endforeach
            </select></label>
        @include('partials._range', ['from' => $from, 'to' => $to, 'all' => false])
        <button class="btn gold" type="submit">{{ __('common.filter') }}</button>
        <a class="btn" href="{{ $tabUrl('users') }}">{{ __('common.clear') }}</a>
    </form>

    <div class="tablewrap">
        <table data-page="50">
            <thead><tr>
                <th>{{ __('activity.c_user') }}</th><th>{{ __('activity.c_verdict') }}</th>
                <th data-nosum>{{ __('activity.c_days') }}</th><th>{{ __('activity.c_sessions') }}</th>
                <th data-nosum>{{ __('activity.c_minutes') }}</th><th>{{ __('activity.c_views') }}</th>
                <th>{{ __('activity.c_work') }}</th><th>{{ __('activity.e_deleted') }}</th>
                <th data-nosum>{{ __('activity.c_work_ratio') }}</th><th data-nosum>{{ __('activity.c_pace') }}</th>
                <th data-nosum>{{ __('activity.c_break') }}</th><th data-nosum>{{ __('activity.c_last') }}</th>
            </tr></thead>
            <tbody>
            @forelse ($list as $r)
                @php $u = $r['user']; $s = $r['sum']; @endphp
                <tr class="clickable" onclick="location.href='{{ route('erp.activity.user', ['user' => $u, 'from' => $from, 'to' => $to]) }}'">
                    <td><b>{{ $u->displayName() }}</b><br><span style="font-size:10.5px;color:var(--muted)">{{ $u->roleLabel() }}</span></td>
                    <td><span class="badge {{ $verdictTone[$s['verdict']] }}">{{ __('activity.v_'.$s['verdict']) }}</span></td>
                    <td class="num">{{ $s['days'] }}</td>
                    <td class="num">{{ $fmt($s['sessions']) }}</td>
                    <td class="num" data-v="{{ $s['minutes'] }}">{{ $s['minutes'] ? AS_::duration($s['minutes']) : '—' }}</td>
                    <td class="num">{{ $fmt($s['views']) }}</td>
                    <td class="num"><b>{{ $fmt($s['work']) }}</b>
                        @if ($s['work'])<br><span style="font-size:10px;color:var(--muted)">+{{ $s['created'] }} ✎{{ $s['updated'] }} ⚡{{ $s['actions'] }}</span>@endif</td>
                    <td class="num" @if($s['deleted']) style="color:var(--red);font-weight:800" @endif>{{ $fmt($s['deleted']) }}</td>
                    <td class="num">{{ $s['total'] ? $s['work_ratio'].'%' : '—' }}</td>
                    <td class="num" style="font-size:11.5px">{{ AS_::gap($s['pace']) }}</td>
                    <td class="num" style="font-size:11.5px">{{ $s['longest_break'] ? AS_::duration($s['longest_break']) : '—' }}</td>
                    <td style="font-size:11.5px;white-space:nowrap">{{ $s['last'] ? date('Y-m-d h:i A', $s['last']) : __('activity.never') }}</td>
                </tr>
            @empty
                <tr><td colspan="12" style="text-align:center;color:var(--muted);padding:26px">{{ __('activity.no_rows') }}</td></tr>
            @endforelse
            </tbody>
            @if (count($list) > 1)
                <tfoot><tr>
                    <td>Σ {{ __('common.total') }}</td><td></td><td></td><td class="num">{{ $fmt($T['sessions']) }}</td>
                    <td class="num">{{ AS_::duration($T['minutes']) }}</td><td class="num">{{ $fmt($T['views']) }}</td>
                    <td class="num">{{ $fmt($T['work']) }}</td><td class="num">{{ $fmt($T['deleted']) }}</td>
                    <td></td><td></td><td></td><td></td>
                </tr></tfoot>
            @endif
        </table>
    </div>
    <p style="font-size:11.5px;color:var(--muted);margin-top:10px">ℹ️ {{ __('activity.users_legend', ['m' => ActivityLog::IDLE_MIN]) }}</p>
</div>

@endif

{{-- ═══════════════════════ ٣. السجل الكامل ═══════════════════════ --}}
@if ($tab === 'log')

@php $fq = fn (array $x) => request()->fullUrlWithQuery($x + ['page' => null, 'export' => null]); @endphp
<div class="kpis">
    <a class="kpi" href="{{ $fq(['event' => null, 'failed' => null]) }}">
        <div class="lbl">🧾 {{ __('activity.k_rows') }}</div><div class="val">{{ $fmt($kpi['total']) }}</div></a>
    <a class="kpi" href="{{ $tabUrl('users', array_filter(['from' => $filters['from'] ?? null, 'to' => $filters['to'] ?? null])) }}">
        <div class="lbl">🧑‍💼 {{ __('activity.k_users') }}</div><div class="val">{{ $fmt($kpi['users']) }}</div></a>
    <a class="kpi @if(($filters['event'] ?? '') === 'work') on @endif" href="{{ $fq(['event' => ($filters['event'] ?? '') === 'work' ? null : 'work']) }}">
        <div class="lbl">✏️ {{ __('activity.c_work') }}</div><div class="val" style="color:var(--primary)">{{ $fmt($kpi['work']) }}</div></a>
    <a class="kpi @if(($filters['event'] ?? '') === 'deleted') on @endif" href="{{ $fq(['event' => ($filters['event'] ?? '') === 'deleted' ? null : 'deleted']) }}">
        <div class="lbl">🗑 {{ __('activity.e_deleted') }}</div><div class="val" style="color:var(--red)">{{ $fmt($kpi['deleted']) }}</div></a>
    <a class="kpi @if(! empty($filters['failed'])) on @endif" href="{{ $fq(['failed' => empty($filters['failed']) ? 1 : null]) }}">
        <div class="lbl">✖ {{ __('activity.k_failed') }}</div><div class="val">{{ $fmt($kpi['failed']) }}</div></a>
</div>

<div class="card">
    <form class="searchbar" method="GET">
        <input type="hidden" name="tab" value="log">
        <label class="fl grow"><span>{{ __('ui.l_search') }}</span>
            <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="🔍 {{ __('activity.search_log_ph') }}"></label>
        <label class="fl wide"><span>{{ __('activity.c_user') }}</span>
            <select name="user">
                <option value="">{{ __('ui.all_of', ['x' => __('activity.users')]) }}</option>
                @foreach ($users as $u)
                    <option value="{{ $u->id }}" @selected((int) ($filters['user'] ?? 0) === $u->id)>{{ $u->displayName() }}</option>
                @endforeach
            </select></label>
        <label class="fl"><span>{{ __('activity.c_role') }}</span>
            <select name="role">
                <option value="">{{ __('ui.all_of', ['x' => __('activity.roles')]) }}</option>
                @foreach ($roles as $ro)
                    <option value="{{ $ro }}" @selected(($filters['role'] ?? '') === $ro)>{{ __('enums.role.'.$ro) }}</option>
                @endforeach
            </select></label>
        <label class="fl"><span>{{ __('ui.l_event') }}</span>
            <select name="event">
                <option value="">{{ __('ui.all_of', ['x' => __('activity.events')]) }}</option>
                <option value="work" @selected(($filters['event'] ?? '') === 'work')>✏️ {{ __('activity.e_work') }}</option>
                @foreach (ActivityLog::EVENTS as $e)
                    <option value="{{ $e }}" @selected(($filters['event'] ?? '') === $e)>{{ __('activity.e_'.$e) }}</option>
                @endforeach
            </select></label>
        <label class="fl"><span>{{ __('ui.l_section') }}</span>
            <select name="section">
                <option value="">{{ __('ui.all_of', ['x' => __('activity.sections')]) }}</option>
                @foreach (array_keys(\App\Support\Access::NAV) as $g)
                    <option value="{{ $g }}" @selected(($filters['section'] ?? '') === $g)>{{ __($g) }}</option>
                @endforeach
                <option value="app" @selected(($filters['section'] ?? '') === 'app')>📱 {{ __('activity.sec_app') }}</option>
            </select></label>
        <label class="fl"><span>{{ __('activity.c_model') }}</span>
            <select name="model">
                <option value="">{{ __('ui.all_of', ['x' => __('activity.models')]) }}</option>
                @foreach ($models as $m)
                    <option value="{{ $m }}" @selected(($filters['model'] ?? '') === $m)>{{ \App\Http\Controllers\ActivityController::modelLabel($m) }}</option>
                @endforeach
            </select></label>
        @if (! empty($filters['failed']))<input type="hidden" name="failed" value="1">@endif
        @include('partials._range', ['from' => $filters['from'] ?? '', 'to' => $filters['to'] ?? ''])
        <button class="btn gold" type="submit">{{ __('common.filter') }}</button>
        <a class="btn" href="{{ $tabUrl('log') }}">{{ __('common.clear') }}</a>
    </form>

    <div class="tablewrap" style="max-height:68vh;overflow-y:auto">
        <table>
            <thead><tr>
                <th>{{ __('activity.c_when') }}</th>
                @if (! empty($filters['user']))<th data-nosum>{{ __('activity.c_gap') }}</th>@endif
                <th>{{ __('activity.c_user') }}</th><th>{{ __('activity.c_event') }}</th>
                <th>{{ __('activity.c_where') }}</th><th>{{ __('activity.c_what') }}</th>
                <th>{{ __('activity.c_details') }}</th><th>IP</th>
            </tr></thead>
            <tbody>
            @forelse ($rows as $r)
                <tr>
                    <td style="white-space:nowrap;font-size:11.5px">{{ $r->created_at?->format('Y-m-d') }}
                        <br><span style="color:var(--muted)">{{ $r->created_at?->format('h:i:s A') }}</span></td>
                    @if (! empty($filters['user']))
                        <td class="num" style="font-size:11.5px;@if(($r->gap ?? 0) > ActivityLog::IDLE_MIN * 60) color:var(--red);font-weight:800 @endif">{{ AS_::gap($r->gap ?? null) }}</td>
                    @endif
                    <td>
                        @if ($r->user)
                            <a href="{{ route('erp.activity.user', $r->user) }}"><b>{{ $r->user->displayName() }}</b></a>
                        @else
                            <b>{{ $r->user_name ?? '—' }}</b>
                        @endif
                        @if ($r->role)<br><span style="font-size:10px;color:var(--muted)">{{ __('enums.role.'.$r->role) }}</span>@endif
                    </td>
                    <td><span class="badge {{ $tone($r->event) }}">{{ __('activity.e_'.$r->event) }}</span></td>
                    <td style="font-size:11.5px;white-space:normal">
                        {{ str_starts_with((string) $r->url, 'api/') ? '📱' : '' }} <b>{{ $r->screenLabel() }}</b>
                        <br><a style="color:var(--muted)" href="{{ $fq(['section' => $r->sectionKey()]) }}">{{ ActivityLog::sectionLabel($r->sectionKey()) }}</a>
                    </td>
                    @include('erp._activity_row', ['r' => $r])
                    <td style="font-size:10.5px;color:var(--muted);white-space:nowrap" dir="ltr">{{ $r->ip }}</td>
                </tr>
            @empty
                <tr><td colspan="8" style="text-align:center;color:var(--muted);padding:26px">{{ __('activity.no_rows') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @include('partials._pagination', ['p' => $rows])
</div>

@endif

@endsection

@section('scripts')
@if ($tab === 'now')
<script>
// اللوحة الحية بتتحدّث كل دقيقة — بس والتاب ظاهر، عشان متصحّيش السيرفر من تاب منسي
setInterval(function () { if (!document.hidden) location.reload(); }, 60000);
</script>
@endif
@endsection
