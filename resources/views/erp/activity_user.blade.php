@extends('layouts.system')

@section('title', __('activity.user_page', ['name' => $user->displayName()]))

@php
    use App\Models\ActivityLog;
    use App\Services\ActivityStats as AS_;

    $fmt = fn ($n) => number_format((float) $n);
    $tone = fn ($e) => match ($e) {
        'created' => 'b-green', 'updated' => 'b-blue', 'deleted' => 'b-red',
        'login' => 'b-purple', 'action' => 'b-orange', default => 'b-gray',
    };
    $stateTone = ['active' => 'b-green', 'idle' => 'b-orange', 'away' => 'b-gray'];
    $verdictTone = ['working' => 'b-green', 'browsing' => 'b-orange', 'light' => 'b-gray', 'none' => 'b-red'];
    $fq = fn (array $x) => request()->fullUrlWithQuery($x + ['page' => null]);
    $logUrl = fn (array $x) => route('erp.activity', ['tab' => 'log', 'user' => $user->id, 'from' => $from, 'to' => $to] + $x);
    $maxMin = max(1, ...array_values(array_map(fn ($d) => $d['minutes'], $days)), ...[1]);
    $secTotal = max(1, array_sum(array_column($sections, 'n')));
@endphp

@section('actions')
    <a class="btn sm" href="{{ route('erp.activity', ['tab' => 'users', 'from' => $from, 'to' => $to]) }}">← {{ __('activity.tab_users') }}</a>
    <a class="btn sm green" href="{{ $logUrl(['export' => 1]) }}">⬇ {{ __('ui.export_all') }}</a>
@endsection

@section('content')

<div class="card" style="display:flex;flex-wrap:wrap;gap:14px;align-items:flex-end;justify-content:space-between">
    <div>
        <div style="font-size:17px;font-weight:900">{{ $user->displayName() }}
            <span class="badge {{ $stateTone[$state] }}">{{ __('activity.s_'.$state) }}</span>
            <span class="badge {{ $verdictTone[$sum['verdict']] }}">{{ __('activity.v_'.$sum['verdict']) }}</span></div>
        <div style="font-size:12px;color:var(--muted);margin-top:4px">
            {{ $user->roleLabel() }} · {{ $user->code }}
            @if ($lastRow) · {{ __('activity.last_seen') }}: <b>{{ $lastRow->created_at->diffForHumans() }}</b> — {{ $lastRow->screenLabel() }} @endif
        </div>
    </div>
    <form class="searchbar" method="GET" style="margin:0">
        @include('partials._range', ['from' => $from, 'to' => $to, 'auto' => true, 'all' => false])
    </form>
</div>

<div class="kpis">
    <a class="kpi" href="#sessions"><div class="lbl">⏱ {{ __('activity.c_minutes') }}</div>
        <div class="val">{{ AS_::duration($sum['minutes']) }}</div>
        <div class="sub2">{{ __('activity.k_sessions_days', ['s' => $sum['sessions'], 'd' => $sum['days']]) }}</div></a>
    <a class="kpi @if(($filters['event'] ?? '') === 'work') on @endif" href="{{ $fq(['event' => ($filters['event'] ?? '') === 'work' ? null : 'work']) }}#timeline">
        <div class="lbl">✏️ {{ __('activity.c_work') }}</div><div class="val" style="color:var(--primary)">{{ $fmt($sum['work']) }}</div>
        <div class="sub2">+{{ $sum['created'] }} · ✎{{ $sum['updated'] }} · ⚡{{ $sum['actions'] }} · {{ $sum['work_ratio'] }}%</div></a>
    <a class="kpi @if(($filters['event'] ?? '') === 'viewed') on @endif" href="{{ $fq(['event' => ($filters['event'] ?? '') === 'viewed' ? null : 'viewed']) }}#timeline">
        <div class="lbl">👁 {{ __('activity.c_views') }}</div><div class="val">{{ $fmt($sum['views']) }}</div>
        <div class="sub2">{{ __('activity.x_views', ['n' => $fmt($sum['total'])]) }}</div></a>
    <a class="kpi @if(($filters['event'] ?? '') === 'deleted') on @endif" href="{{ $fq(['event' => ($filters['event'] ?? '') === 'deleted' ? null : 'deleted']) }}#timeline">
        <div class="lbl">🗑 {{ __('activity.e_deleted') }}</div><div class="val" @if($sum['deleted']) style="color:var(--red)" @endif>{{ $fmt($sum['deleted']) }}</div>
        <div class=\"sub2\">{{ __('activity.x_deleted') }}</div></a>
    <a class="kpi" href="#sessions"><div class="lbl">⚡ {{ __('activity.c_pace') }}</div>
        <div class="val">{{ AS_::gap($sum['pace']) }}</div><div class="sub2">{{ __('activity.k_pace_hint') }}</div></a>
    <a class="kpi" href="#sessions"><div class="lbl">☕ {{ __('activity.c_break') }}</div>
        <div class="val">{{ $sum['longest_break'] ? AS_::duration($sum['longest_break']) : '—' }}</div>
        <div class="sub2">{{ __('activity.k_break_hint') }}</div></a>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:16px">
    <div class="card">
        <h3>📅 {{ __('activity.days_title') }}</h3>
        <div class="tablewrap">
            <table data-page="25">
                <thead><tr>
                    <th data-nosum>{{ __('ui.l_day') }}</th><th data-nosum>{{ __('activity.c_first') }}</th><th data-nosum>{{ __('activity.c_last') }}</th>
                    <th data-nosum>{{ __('activity.c_minutes') }}</th><th>{{ __('activity.c_sessions') }}</th>
                    <th>{{ __('activity.c_views') }}</th><th>{{ __('activity.c_work') }}</th><th data-nosum>{{ __('activity.c_break') }}</th>
                </tr></thead>
                <tbody>
                @forelse ($days as $d => $s)
                    <tr class="clickable" onclick="location.href='{{ $fq(['day' => $d]) }}#timeline'" @if(($filters['day'] ?? '') === $d) style="background:var(--blue-050)" @endif>
                        <td style="white-space:nowrap"><b dir="ltr">{{ $d }}</b></td>
                        <td class="num" style="font-size:11.5px">{{ date('h:i A', $s['first']) }}</td>
                        <td class="num" style="font-size:11.5px">{{ date('h:i A', $s['last']) }}</td>
                        <td style="min-width:120px">
                            <div style="display:flex;align-items:center;gap:6px">
                                <div style="flex:1;height:7px;background:var(--card2);border-radius:9px;overflow:hidden">
                                    <div style="width:{{ round($s['minutes'] * 100 / $maxMin) }}%;height:100%;background:var(--brand-gradient)"></div></div>
                                <span style="font-size:11px;white-space:nowrap">{{ AS_::duration($s['minutes']) }}</span>
                            </div></td>
                        <td class="num">{{ $s['sessions'] }}</td>
                        <td class="num">{{ $fmt($s['views']) }}</td>
                        <td class="num"><b>{{ $fmt($s['work']) }}</b></td>
                        <td class="num" style="font-size:11.5px">{{ $s['longest_break'] ? AS_::duration($s['longest_break']) : '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" style="text-align:center;color:var(--muted);padding:22px">{{ __('activity.no_rows') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h3>🗂 {{ __('activity.sections_title') }}</h3>
        <div class="tablewrap">
            <table data-page="25">
                <thead><tr><th>{{ __('activity.c_section') }}</th><th>{{ __('activity.c_total') }}</th>
                    <th>{{ __('activity.c_work') }}</th><th data-nosum>{{ __('activity.c_share') }}</th></tr></thead>
                <tbody>
                @foreach ($sections as $key => $s)
                    <tr @if($key !== '-') class="clickable" onclick="location.href='{{ $logUrl(['section' => $key]) }}'" @endif>
                        <td><b>{{ ActivityLog::sectionLabel($key === '-' ? null : $key) }}</b></td>
                        <td class="num">{{ $fmt($s['n']) }}</td><td class="num">{{ $fmt($s['work']) }}</td>
                        <td class="num">{{ round($s['n'] * 100 / $secTotal) }}%</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <h3 style="margin-top:16px">🖥 {{ __('activity.screens_title') }}</h3>
        <div class="tablewrap">
            <table data-page="25">
                <thead><tr><th>{{ __('activity.c_screen') }}</th><th>{{ __('activity.c_section') }}</th>
                    <th>{{ __('activity.c_total') }}</th><th>{{ __('activity.c_work') }}</th></tr></thead>
                <tbody>
                @foreach ($screens as $name => $s)
                    <tr><td><b>{{ $name }}</b></td>
                        <td style="font-size:11.5px;color:var(--muted)">{{ ActivityLog::sectionLabel($s['section'] === '-' ? null : $s['section']) }}</td>
                        <td class="num">{{ $fmt($s['n']) }}</td><td class="num">{{ $fmt($s['work']) }}</td></tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card" id="sessions">
    <h3>⏱ {{ __('activity.sessions_title') }} <span class="side">{{ __('activity.sessions_hint', ['m' => ActivityLog::IDLE_MIN]) }}</span></h3>
    <div class="tablewrap">
        <table data-page="25">
            <thead><tr>
                <th data-nosum>{{ __('ui.l_day') }}</th><th data-nosum>{{ __('activity.c_from') }}</th><th data-nosum>{{ __('activity.c_to') }}</th>
                <th data-nosum>{{ __('activity.c_duration') }}</th><th>{{ __('activity.c_total') }}</th>
                <th>{{ __('activity.c_views') }}</th><th>{{ __('activity.c_work') }}</th>
                <th data-nosum>{{ __('activity.c_gap_before') }}</th>
            </tr></thead>
            <tbody>
            @forelse ($sessions as $s)
                <tr class="clickable" onclick="location.href='{{ $fq(['day' => date('Y-m-d', $s['start'])]) }}#timeline'">
                    <td dir="ltr" style="white-space:nowrap">{{ date('Y-m-d', $s['start']) }}</td>
                    <td class="num" style="font-size:11.5px">{{ date('h:i A', $s['start']) }}</td>
                    <td class="num" style="font-size:11.5px">{{ date('h:i A', $s['end']) }}</td>
                    <td class="num">{{ AS_::duration(max(1, (int) round(($s['end'] - $s['start']) / 60))) }}</td>
                    <td class="num">{{ $s['n'] }}</td><td class="num">{{ $s['views'] }}</td>
                    <td class="num"><b>{{ $s['work'] }}</b>
                        @if ($s['work'] === 0 && $s['n'] >= 5)<span class="badge b-orange">{{ __('activity.only_browsing') }}</span>@endif</td>
                    <td class="num" style="font-size:11.5px">{{ AS_::gap($s['gap_before']) }}</td>
                </tr>
            @empty
                <tr><td colspan="8" style="text-align:center;color:var(--muted);padding:22px">{{ __('activity.no_rows') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="card" id="timeline">
    <h3>🧾 {{ __('activity.timeline_title') }}
        <span class="side">
            @if (! empty($filters['day']))<a class="badge b-blue" style="text-decoration:none" href="{{ $fq(['day' => null]) }}#timeline" dir="ltr">{{ $filters['day'] }} ✕</a>@endif
            @if (! empty($filters['event']))<a class="badge b-blue" style="text-decoration:none" href="{{ $fq(['event' => null]) }}#timeline">{{ __('activity.e_'.$filters['event']) }} ✕</a>@endif
            {{ $fmt($timeline->total()) }}
        </span></h3>
    <div class="tablewrap" style="max-height:70vh;overflow-y:auto">
        <table>
            <thead><tr>
                <th>{{ __('activity.c_when') }}</th><th data-nosum>{{ __('activity.c_gap') }}</th><th>{{ __('activity.c_event') }}</th>
                <th>{{ __('activity.c_where') }}</th><th>{{ __('activity.c_what') }}</th><th>{{ __('activity.c_details') }}</th><th>IP</th>
            </tr></thead>
            <tbody>
            @forelse ($timeline as $r)
                @php $long = ($r->gap ?? 0) > ActivityLog::IDLE_MIN * 60; @endphp
                <tr>
                    <td style="white-space:nowrap;font-size:11.5px"><span dir="ltr">{{ $r->created_at->format('Y-m-d') }}</span>
                        <br><span style="color:var(--muted)">{{ $r->created_at->format('h:i:s A') }}</span></td>
                    <td class="num" style="font-size:11.5px;@if($long) color:var(--red);font-weight:800 @endif">{{ AS_::gap($r->gap ?? null) }}</td>
                    <td><span class="badge {{ $tone($r->event) }}">{{ __('activity.e_'.$r->event) }}</span></td>
                    <td style="font-size:11.5px;white-space:normal">{{ str_starts_with((string) $r->url, 'api/') ? '📱' : '' }} <b>{{ $r->screenLabel() }}</b>
                        <br><span style="color:var(--muted)">{{ ActivityLog::sectionLabel($r->sectionKey()) }}</span></td>
                    @include('erp._activity_row', ['r' => $r])
                    <td style="font-size:10.5px;color:var(--muted);white-space:nowrap" dir="ltr">{{ $r->ip }}</td>
                </tr>
            @empty
                <tr><td colspan="7" style="text-align:center;color:var(--muted);padding:26px">{{ __('activity.no_rows') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @include('partials._pagination', ['p' => $timeline])
</div>

@endsection
