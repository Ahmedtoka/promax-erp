@extends('layouts.system')

@section('title', __('ops.dashboard'))

@php $fmt = fn ($n) => number_format((float) $n); @endphp

@section('content')

<div class="kpis">
    <div class="kpi"><div class="lbl">{{ __('ops.cash_van_sales_today') }}</div><div class="val pos">{{ $fmt($todaySales) }} {{ __('common.currency') }}</div></div>
    <div class="kpi"><div class="lbl">{{ __('ops.delivered_today') }}</div><div class="val" style="color:var(--blue)">{{ $fmt($todayPos) }} {{ __('common.currency') }}</div></div>
    <div class="kpi"><div class="lbl">{{ __('ops.visits_closed') }}</div><div class="val">{{ $visitsDone }}</div></div>
    <div class="kpi"><div class="lbl">{{ __('ops.pending_client_requests') }}</div><div class="val mid">{{ $openRequests }}</div>
        <div class="sub2"><a href="{{ route('ops.requests') }}" style="color:var(--blue);font-weight:800">{{ __('ops.review_them') }} ←</a></div></div>
    <div class="kpi"><div class="lbl">{{ __('ops.reps_on_road') }}</div><div class="val">{{ $field->count() }}</div></div>
</div>

<div class="card">
    <h3>🚛 {{ __('ops.reps_live') }}</h3>
    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('ops.rep') }}</th><th>{{ __('team.role') }}</th><th>{{ __('team.zone') }}</th>
                <th>{{ __('ops.todays_performance') }}</th><th>{{ __('ops.van_stock_left') }}</th>
                <th>{{ __('common.status') }}</th><th></th>
            </tr>
            @foreach ($field as $s)
                @php $u = $s['user']; @endphp
                <tr class="clickable" onclick="location.href='{{ route('ops.rep', $u) }}'">
                    <td><b>{{ $u->displayName() }}</b><br><span style="font-size:10.5px;color:var(--muted)">{{ $u->code }}</span></td>
                    <td><span class="badge {{ $u->isDriver() ? 'b-blue' : 'b-green' }}">{{ $u->roleLabel() }}</span></td>
                    <td style="color:var(--muted)">{{ $u->zone?->displayName() ?? ($u->isDriver() ? __('ops.delivery_run') : '—') }}</td>
                    <td class="num">
                        @if ($u->isDriver())
                            {{ $fmt($s['posValue']) }} {{ __('common.currency') }}<br><span style="color:var(--muted)">{{ $s['posDone'] }}/{{ $s['pos'] }} {{ trans_choice('ops.delivery', $s['pos']) }}</span>
                        @else
                            {{ $fmt($s['sales']) }} {{ __('common.currency') }}<br><span style="color:var(--muted)">{{ $s['visitsDone'] }}/{{ $s['visits'] }} {{ trans_choice('ops.visit_count', $s['visits']) }}</span>
                        @endif
                    </td>
                    <td class="num">{{ $s['remaining'] }} {{ trans_choice('ops.unit', $s['remaining']) }}<br><span style="color:var(--muted)">{{ $fmt($s['remainingValue']) }} {{ __('common.currency') }}</span></td>
                    <td>
                        @if ($s['openVisit'])
                            <span class="badge b-orange">{{ __('ops.in_visit') }}</span>
                        @elseif ($s['custody'])
                            <span class="badge b-green">{{ __('ops.on_duty') }}</span>
                        @else
                            <span class="badge b-gray">{{ __('ops.no_van_stock') }}</span>
                        @endif
                    </td>
                    <td><span class="btn sm">{{ __('common.details') }} ←</span></td>
                </tr>
            @endforeach
        </table>
    </div>
</div>

<div class="card">
    <h3>🛰️ {{ __('ops.todays_timeline') }} <span class="side">{{ $events->count() }} {{ trans_choice('ops.event', $events->count()) }}</span></h3>
    <div class="alerts" style="max-height:520px;overflow-y:auto">
        @forelse ($events as $e)
            @php
                $cls = match ($e->type) {
                    'sale', 'deliver' => 'good',
                    'check_in' => 'info',
                    'request' => 'warn',
                    'start' => 'info',
                    default => '',
                };
            @endphp
            <div class="alert {{ $cls }}">
                <div><b>{{ $e->happened_at->format('h:i A') }} — {{ $e->user->displayName() }}:</b> {{ $e->title }}
                    @if ($e->subtitle)<span style="color:var(--muted)"> • {{ $e->subtitle }}</span>@endif
                </div>
            </div>
        @empty
            <div style="text-align:center;color:var(--muted);padding:20px">{{ __('ops.no_activity_today') }}</div>
        @endforelse
    </div>
</div>

@endsection
