@extends('layouts.system')

@section('title', __('worktime.user_page', ['name' => $user->displayName()]))

@php
    use App\Services\ActivityStats as AS_;

    $dur = fn (int $m) => AS_::duration($m);
    $d = $day->toDateString();
    $prev = $day->copy()->subDay()->toDateString();
    $next = $day->copy()->addDay()->toDateString();
    $stColor = ['active' => 'var(--green)', 'idle' => 'var(--orange,#C77700)', 'hidden' => '#9AA3AE', 'off' => 'var(--border)'];
    $barTotal = max(1, array_sum(array_column($bar, 'min')));
    $flagTone = ['open_no_work' => 'b-red', 'long_quiet' => 'b-red', 'low_ratio' => 'b-orange', 'mostly_hidden' => 'b-orange'];
    $verdictTone = ['good' => 'b-green', 'watch' => 'b-red', 'light' => 'b-gray', 'none' => 'b-gray'];
@endphp

@section('actions')
    <a class="btn sm" href="{{ route('erp.worktime', ['from' => $d, 'to' => $d]) }}">← {{ __('worktime.back') }}</a>
@endsection

@section('content')

<form class="searchbar" method="GET" style="margin-bottom:10px;align-items:flex-end;gap:8px">
    <a class="btn sm" href="{{ route('erp.worktime.user', ['user' => $user, 'day' => $prev]) }}">‹ {{ __('worktime.prev_day') }}</a>
    <label class="fl"><span>{{ __('worktime.c_time') }}</span>
        <input type="date" name="day" value="{{ $d }}" onchange="this.form.submit()"></label>
    <a class="btn sm" href="{{ route('erp.worktime.user', ['user' => $user, 'day' => $next]) }}">{{ __('worktime.next_day') }} ›</a>
    <span style="margin-inline-start:auto"><b>{{ $user->displayName() }}</b> · {{ $user->roleLabel() }}
        · <span class="badge {{ $verdictTone[$sum['verdict']] }}">{{ __('worktime.v_'.$sum['verdict']) }}</span></span>
</form>

<div class="kpis">
    <div class="kpi"><div class="lbl">🕘 {{ __('worktime.c_first') }} → {{ __('worktime.c_last') }}</div>
        <div class="val" style="font-size:17px">{{ $sum['first'] ? date('h:i A', $sum['first']) : '—' }} → {{ $sum['last'] ? date('h:i A', $sum['last']) : '—' }}</div></div>
    <div class="kpi"><div class="lbl">🖥 {{ __('worktime.c_open') }}</div><div class="val">{{ $dur($sum['open']) }}</div></div>
    <div class="kpi"><div class="lbl">✍️ {{ __('worktime.c_active') }}</div><div class="val" style="color:var(--green)">{{ $dur($sum['active']) }}</div>
        <div class="sub2">{{ __('worktime.c_ratio') }}: {{ $sum['ratio'] }}%</div></div>
    <div class="kpi"><div class="lbl">😶 {{ __('worktime.c_idle') }}</div><div class="val">{{ $dur($sum['idle']) }}</div>
        <div class="sub2">{{ __('worktime.c_hidden') }}: {{ $dur($sum['hidden']) }}</div></div>
    <div class="kpi"><div class="lbl">⏸ {{ __('worktime.c_quiet') }}</div><div class="val">{{ $dur($sum['longest_quiet']) }}</div></div>
    <div class="kpi"><div class="lbl">✅ {{ __('worktime.c_actions') }}</div><div class="val">{{ number_format($sum['actions']) }}</div></div>
</div>

@if ($sum['flags'])
    <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px">
        @foreach ($sum['flags'] as $f)<span class="badge {{ $flagTone[$f] }}">⚠️ {{ __('worktime.f_'.$f) }}</span>@endforeach
    </div>
@endif

@if ($bar)
<div class="card">
    <h3>📊 {{ date('h:i A', $bar[0]['from']) }} → {{ date('h:i A', end($bar)['from'] + end($bar)['min'] * 60) }}
        <span class="side">
            @foreach (['active', 'idle', 'hidden', 'off'] as $st)
                <span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:{{ $stColor[$st] }}"></span> {{ __('worktime.st_'.$st) }}&nbsp;
            @endforeach
        </span></h3>
    <div style="display:flex;height:26px;border-radius:7px;overflow:hidden;border:1px solid var(--border)" dir="ltr">
        @foreach ($bar as $p)
            <div title="{{ date('h:i A', $p['from']) }} · {{ __('worktime.st_'.$p['state']) }} · {{ $dur($p['min']) }}"
                 style="flex:{{ $p['min'] }} 0 0;background:{{ $stColor[$p['state']] }};min-width:1px"></div>
        @endforeach
    </div>
</div>
@endif

<div class="card">
    <h3>🧭 {{ __('worktime.user_page', ['name' => $user->displayName()]) }} <span class="side">{{ $d }}</span></h3>
    <div class="tablewrap">
        <table data-page="100">
            <thead><tr>
                <th data-nosum>{{ __('worktime.c_time') }}</th><th>{{ __('worktime.c_screen') }}</th>
                <th data-nosum>{{ __('worktime.c_minutes') }}</th><th data-nosum>{{ __('worktime.c_active') }}</th>
                <th data-nosum>{{ __('worktime.c_idle') }}</th><th data-nosum>{{ __('worktime.c_hidden') }}</th>
                <th>{{ __('worktime.c_did') }}</th>
            </tr></thead>
            <tbody>
            @forelse ($segments as $s)
                @php $total = $s['active'] + $s['idle'] + $s['hidden']; $quiet = $s['idle'] + $s['hidden']; @endphp
                <tr @if ($total >= 15 && $quiet * 100 >= $total * 80) style="background:rgba(214,69,69,.07)" @endif>
                    <td class="num" style="font-size:11.5px">{{ date('h:i A', $s['from']) }}@if ($total) – {{ date('h:i A', $s['to']) }}@endif</td>
                    <td style="white-space:normal"><b>{{ $s['screen'] }}</b>
                        @if (! empty($s['loose']))<br><span style="font-size:10.5px;color:var(--muted)">{{ __('worktime.loose') }}</span>@endif</td>
                    <td class="num">{{ $total ? $dur($total) : '—' }}</td>
                    <td class="num" style="color:var(--green)">{{ $total ? $dur($s['active']) : '—' }}</td>
                    <td class="num" style="color:var(--orange,#C77700)">{{ $total ? $dur($s['idle']) : '—' }}</td>
                    <td class="num" style="color:var(--muted)">{{ $total ? $dur($s['hidden']) : '—' }}</td>
                    <td style="white-space:normal;font-size:11.5px">
                        @forelse ($s['actions'] as $act)
                            <div>✅ {{ date('h:i A', $act['ts']) }} · {{ $act['label'] }}</div>
                        @empty
                            <span style="color:var(--muted)">{{ __('worktime.no_actions') }}</span>
                        @endforelse
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" style="text-align:center;color:var(--muted);padding:26px">{{ __('worktime.empty_day') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <p style="font-size:11.5px;color:var(--muted);margin-top:10px">ℹ️ {{ $since ? __('worktime.since', ['d' => $since]) : __('worktime.not_started') }} {{ __('worktime.legend') }}</p>
</div>

@endsection
