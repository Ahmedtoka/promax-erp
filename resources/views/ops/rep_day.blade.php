@extends('layouts.system')

@section('title', __('journey.rep_day').' — '.$rep->displayName())

@php
    $fmt = fn ($n) => number_format((float) $n);

    $statusClass = [
        'done' => 'b-green',
        'in_visit' => 'b-orange',
        'pending' => 'b-gray',
    ];
@endphp

@section('actions')
    <a class="btn" href="{{ route('ops.live') }}">← {{ __('journey.live') }}</a>
    <a class="btn" href="{{ route('ops.rep', $rep) }}">🚚 {{ __('nav.reps') }}</a>
@endsection

@section('content')

<div class="card">
    <h3>🧑‍💼 {{ $rep->displayName() }}
        <span class="side">{{ $rep->roleLabel() }} · {{ $date->format('Y-m-d') }} · {{ __('journey.day_'.$date->dayOfWeek) }}</span>
    </h3>

    <form method="GET" action="{{ route('ops.rep_day', $rep) }}" class="searchbar">
        <div>
            <label class="f">{{ __('common.date') }}</label>
            <input type="date" name="date" value="{{ $date->toDateString() }}" onchange="this.form.submit()">
        </div>
    </form>
</div>

<div class="kpis">
    <div class="kpi">
        <div class="lbl">{{ __('journey.planned') }}</div>
        <div class="val">{{ $fmt($summary['planned']) }}</div>
        <div class="sub2">{{ __('journey.plan') }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('journey.done') }}</div>
        <div class="val pos">{{ $fmt($summary['done']) }}</div>
        <div class="sub2">{{ __('journey.completion') }}: {{ $summary['pct'] }}%</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('journey.pending') }}</div>
        <div class="val {{ $summary['pending'] > 0 ? 'mid' : 'pos' }}">{{ $fmt($summary['pending']) }}</div>
        <div class="sub2">{{ __('journey.plan') }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('journey.off_plan') }}</div>
        <div class="val">{{ $fmt($summary['off_plan']) }}</div>
        <div class="sub2">{{ __('journey.off_plan_hint') }}</div>
    </div>
</div>

{{-- ═══════════ خطة اليوم ═══════════ --}}
<div class="card">
    <h3>📋 {{ __('journey.plan') }} <span class="side">{{ $rows->count() }}</span></h3>

    <div class="tablewrap">
        <table>
            <tr>
                <th class="num">#</th>
                <th>{{ __('client.client') }}</th>
                <th>{{ __('client.zone') }}</th>
                <th>{{ __('journey.frequency') }}</th>
                <th>{{ __('ops.check_in') }}</th>
                <th>{{ __('ops.check_out') }}</th>
                <th>{{ __('common.status') }}</th>
            </tr>

            @forelse ($rows as $i => $row)
                <tr>
                    <td class="num">{{ $i + 1 }}</td>
                    <td>
                        <a href="{{ route('erp.clients.show', $row['client']) }}">
                            <b>{{ $row['client']->displayName() }}</b>
                        </a>
                        @if ($row['client']->address)
                            <br><span style="font-size:10.5px;color:var(--muted)">{{ $row['client']->address }}</span>
                        @endif
                    </td>
                    <td class="s">{{ $row['client']->zone?->displayName() ?: '—' }}</td>
                    <td class="s">{{ $row['plan']->frequencyLabel() }}</td>
                    <td class="num s">{{ $row['visit']?->checked_in_at?->format('H:i') ?: '—' }}</td>
                    <td class="num s">{{ $row['visit']?->checked_out_at?->format('H:i') ?: '—' }}</td>
                    <td>
                        <span class="badge {{ $statusClass[$row['status']] ?? 'b-gray' }}">
                            {{ __('journey.'.$row['status']) }}
                        </span>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" style="text-align:center;color:var(--muted);padding:28px">
                    {{ __('journey.no_plan_day') }}
                </td></tr>
            @endforelse
        </table>
    </div>
</div>

{{-- ═══════════ بره الخطة ═══════════ --}}
@if ($offPlan->isNotEmpty())
<div class="card">
    <h3>➕ {{ __('journey.off_plan') }} <span class="side">{{ $offPlan->count() }}</span></h3>
    <div class="alert info">{{ __('journey.off_plan_hint') }}</div>

    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('client.client') }}</th>
                <th>{{ __('ops.check_in') }}</th>
                <th>{{ __('ops.check_out') }}</th>
            </tr>
            @foreach ($offPlan as $v)
                <tr>
                    <td>
                        <a href="{{ route('erp.clients.show', $v->client_id) }}">
                            {{ $v->client?->displayName() }}
                        </a>
                    </td>
                    <td class="num s">{{ $v->checked_in_at?->format('H:i') ?: '—' }}</td>
                    <td class="num s">{{ $v->checked_out_at?->format('H:i') ?: '—' }}</td>
                </tr>
            @endforeach
        </table>
    </div>
</div>
@endif

@endsection
