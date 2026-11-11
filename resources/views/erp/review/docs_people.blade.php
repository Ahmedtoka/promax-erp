@extends('layouts.system')

@section('title', __('review.people_title'))

@php $money = fn ($v) => number_format((float) $v, 2); @endphp

@section('content')

@include('erp.review._tabs')

<div class="alert info" style="margin-bottom:12px"><span>⏱</span><span>{{ __('review.people_intro', ['s' => $fastSeconds, 'n' => $fastStreak]) }}</span></div>

<form class="card" method="GET" action="{{ route('erp.review.docs.people') }}" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;padding:12px 14px;margin-bottom:12px">
    <label class="fl"><span>{{ __('review.p_user') }}</span>
        <select name="user"><option value="">{{ __('review.p_all_users') }}</option>
            @foreach ($users as $x)<option value="{{ $x->id }}" @selected($user === $x->id)>{{ $x->displayName() }}</option>@endforeach
        </select></label>
    @include('partials._range', ['from' => $from, 'to' => $to, 'auto' => true, 'all' => false])
    <button class="btn">{{ __('review.f_apply') }}</button>
    <a class="btn sm" href="{{ route('erp.review.docs.people.export', request()->only(['from', 'to', 'user'])) }}">⬇ {{ __('review.x_trail') }}</a>
</form>

<div class="card">
    <h3>{{ __('review.people_title') }}</h3>
    <div class="tablewrap">
        <table>
            <thead><tr>
                <th>{{ __('review.p_user') }}</th><th>{{ __('review.p_day') }}</th><th>{{ __('review.p_reviews') }}</th>
                <th>{{ __('review.p_issues') }}</th><th data-nosum>{{ __('review.p_median') }}</th><th data-nosum>{{ __('review.p_p10') }}</th>
                <th data-nosum>{{ __('review.p_open') }}</th><th>{{ __('review.p_fast') }}</th><th data-nosum>{{ __('review.p_flag') }}</th>
                <th>{{ __('review.p_edits') }}</th><th>{{ __('review.p_money') }}</th><th data-nosum>{{ __('review.p_span') }}</th>
                <th data-nosum>{{ __('review.p_sample') }}</th>
            </tr></thead>
            <tbody>
            @forelse ($days as $d)
                <tr class="{{ $d['flag'] ? 'pp-flag' : '' }}">
                    <td><a href="{{ request()->fullUrlWithQuery(['user' => $d['user_id'], 'day' => $d['day']]) }}"><b>{{ $d['user'] }}</b></a></td>
                    <td dir="ltr">{{ $d['day'] }}</td>
                    <td>{{ $d['reviews'] }}</td>
                    <td>{{ $d['issues'] }}</td>
                    <td dir="ltr" class="{{ $d['median_s'] !== null && $d['median_s'] < $fastSeconds ? 'pp-bad' : '' }}">{{ $d['median_s'] === null ? '—' : __('review.p_sec', ['s' => $d['median_s']]) }}</td>
                    <td dir="ltr">{{ $d['p10_s'] === null ? '—' : __('review.p_sec', ['s' => $d['p10_s']]) }}</td>
                    <td dir="ltr">{{ $d['open_s'] === null ? '—' : __('review.p_sec', ['s' => $d['open_s']]) }}</td>
                    <td class="{{ $d['fast'] > 0 ? 'pp-bad' : '' }}">{{ $d['fast'] }}</td>
                    <td>
                        @foreach ($d['streaks'] as $s)
                            <div class="pp-streak">🚩 {{ __('review.p_streak', ['n' => $s['n'], 'from' => $s['from'], 'to' => $s['to']]) }}</div>
                        @endforeach
                    </td>
                    <td title="{{ collect($d['edits_by'])->map(fn ($n, $k) => __('review.ev_'.$k, ['number' => '']).' '.$n)->implode(' · ') }}">{{ $d['edits'] }}</td>
                    <td dir="ltr">{{ $money($d['money_abs']) }}<br><span class="rv-sub">{{ $d['money_net'] > 0 ? '+' : '' }}{{ $money($d['money_net']) }}</span></td>
                    <td dir="ltr">{{ $d['first'] }} – {{ $d['last'] }}</td>
                    <td>@if ($d['ok'] > 0)<a class="btn sm" href="{{ route('erp.review.docs', ['status' => 'ok', 'by' => $d['user_id'], 'day' => $d['day'], 'sample' => min(5, $d['ok'])]) }}" title="{{ __('review.p_sample_hint') }}">🔎 {{ min(5, $d['ok']) }}</a>@endif</td>
                </tr>
            @empty
                <tr><td colspan="13" class="rv-sub">{{ __('review.p_none') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

@if ($timeline !== null)
    @php $uName = $users->firstWhere('id', $user)?->displayName(); @endphp
    <div class="card" style="margin-top:14px">
        <h3>{{ __('review.p_timeline', ['user' => $uName, 'day' => $day]) }}</h3>
        <div class="tablewrap">
            <table>
                <thead><tr>
                    <th data-nosum>{{ __('review.p_time') }}</th><th>{{ __('review.p_action') }}</th><th>{{ __('review.p_doc') }}</th>
                    <th data-nosum>{{ __('review.p_gap') }}</th><th data-nosum>{{ __('review.p_open') }}</th><th>{{ __('review.p_delta') }}</th><th>{{ __('review.lines_note') }}</th>
                </tr></thead>
                <tbody>
                @foreach ($timeline as $e)
                    @php $fast = $e->seconds_since_prev !== null && $e->seconds_since_prev < $fastSeconds; @endphp
                    <tr class="{{ $fast ? 'pp-fastrow' : '' }}">
                        <td dir="ltr">{{ $e->created_at->format('H:i:s') }}</td>
                        <td>{{ __('review.ev_'.$e->action, ['number' => '']) }}</td>
                        <td dir="ltr"><a href="{{ route('erp.review.docs', ['doc' => ($e->doc_type === 'invoice' ? 'inv-' : 'po-').$e->doc_id, 'status' => 'all']) }}" target="_blank" rel="noopener">{{ $e->number }}</a></td>
                        <td dir="ltr" class="{{ $fast ? 'pp-bad' : '' }}">{{ $e->seconds_since_prev === null ? '—' : __('review.p_sec', ['s' => $e->seconds_since_prev]) }}</td>
                        <td dir="ltr">{{ $e->open_ms === null ? '—' : __('review.p_sec', ['s' => round($e->open_ms / 1000, 1)]) }}</td>
                        <td dir="ltr">{{ (float) $e->grand_delta != 0 ? $money($e->grand_delta) : '' }}</td>
                        <td>{{ $e->note }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

<style>
.pp-flag td{background:#FEF2F2}
.pp-bad{color:#B91C1C;font-weight:900}
.pp-streak{font-size:12px;color:#B91C1C;font-weight:800;white-space:nowrap}
.pp-fastrow td{background:#FFF7ED}
</style>
@endsection
