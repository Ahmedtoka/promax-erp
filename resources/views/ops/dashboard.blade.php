@extends('layouts.system')

@section('title', __('ops.dashboard'))

@php $fmt = fn ($n) => number_format((float) $n); @endphp

@section('content')

<div class="kpis">
    {{-- (٢٢/٩) كروت النهارده بتفتح قايمتها بتاريخ النهارده --}}
    @php
        $td = today()->toDateString();
        // (٢٢/٩) شرح الكروت من نفس صفوف الجدول تحت — مفيش تعريف تاني للرقم
        $vAll = (int) $field->sum('visits');
        $withCustody = $field->filter(fn ($s) => $s['custody'])->count();
        $inVisit = $field->filter(fn ($s) => $s['openVisit'])->count();
    @endphp
    <a class="kpi" href="{{ route('ops.invoices', ['from' => $td, 'to' => $td]) }}"><div class="lbl">{{ __('ops.cash_van_sales_today') }}</div><div class="val pos">{{ $fmt($todaySales) }} {{ __('common.currency') }}</div>
        <div class="sub2">{{ __('uic.od_sales_sub') }}</div></a>
    <a class="kpi" href="{{ route('ops.pos', ['status' => 'delivered']) }}"><div class="lbl">{{ __('ops.delivered_today') }}</div><div class="val" style="color:var(--blue)">{{ $fmt($todayPos) }} {{ __('common.currency') }}</div>
        <div class="sub2">{{ __('uic.od_pos_sub', ['t' => $fmt((float) $todaySales + (float) $todayPos)]) }}</div></a>
    <a class="kpi" href="{{ route('ops.visits', ['from' => $td, 'to' => $td, 'status' => 'closed']) }}"><div class="lbl">{{ __('ops.visits_closed') }}</div><div class="val">{{ $visitsDone }}</div>
        <div class="sub2">{{ __('uic.od_visits_sub', ['d' => $visitsDone, 'a' => $vAll, 'o' => max($vAll - $visitsDone, 0)]) }}</div></a>
    <a class="kpi" href="{{ route('ops.requests') }}"><div class="lbl">{{ __('ops.pending_client_requests') }}</div><div class="val mid">{{ $openRequests }}</div>
        <div class="sub2">{{ __('uic.od_req_sub') }}</div>
        <div class="sub2" style="color:var(--blue);font-weight:800">{{ __('ops.review_them') }} ←</div></a>
    <a class="kpi" href="#opsReps"><div class="lbl">{{ __('ops.reps_on_road') }}</div><div class="val">{{ $field->count() }}</div>
        <div class="sub2">{{ __('uic.od_reps_sub', ['n' => $field->count(), 'c' => $withCustody, 'v' => $inVisit]) }}</div></a>
</div>

<div class="card" id="opsReps">
    <h3>🚛 {{ __('ops.reps_live') }}</h3>
    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('ops.rep') }}</th><th>{{ __('team.role') }}</th><th>{{ __('team.zone') }}</th>
                <th>{{ __('ops.todays_performance') }}</th><th>{{ __('ops.van_stock_left') }}</th>
                {{-- (٢٢/٩) زرار «تفاصيل» اتشال — اللاي أوت بيضيف «عرض» لكل صف، فكانوا زرارين لنفس المكان --}}
                <th>{{ __('common.status') }}</th>
            </tr>
            @foreach ($field as $s)
                @php $u = $s['user']; @endphp
                <tr class="clickable" onclick="location.href='{{ route('ops.rep', $u) }}'">
                    <td><a href="{{ route('ops.rep', $u) }}" onclick="event.stopPropagation()"><b>{{ $u->displayName() }}</b></a><br><span style="font-size:10.5px;color:var(--muted)">{{ $u->code }}</span></td>
                    <td><span class="badge {{ $u->isDriver() ? 'b-blue' : 'b-green' }}">{{ $u->roleLabel() }}</span></td>
                    <td style="color:var(--muted)">{{ $u->zone?->displayName() ?? ($u->isDriver() ? __('ops.delivery_run') : '—') }}</td>
                    {{-- الرقم موحّد للكل: فواتيره + أوامره المسلَّمة
                         (عقيدة ١١/٨) — التفرّع سواق/غيره كان بيخفي
                         آجل السيلز اللي اتسلّم بأمر توريد. السطر
                         التاني بس هو اللي لسه بيتفرّع بالدور. --}}
                    <td class="num">
                        {{ $fmt($s['sales']) }} {{ __('common.currency') }}<br>
                        @if ($u->isDriver())
                            <span style="color:var(--muted)">{{ $s['posDone'] }}/{{ $s['pos'] }} {{ trans_choice('ops.delivery', $s['pos']) }}</span>
                        @else
                            <span style="color:var(--muted)">{{ $s['visitsDone'] }}/{{ $s['visits'] }} {{ trans_choice('ops.visit_count', $s['visits']) }}</span>
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
                <div><b>{{ $e->happened_at->format('h:i A') }} — <a href="{{ route('ops.rep', $e->user_id) }}" style="color:inherit">{{ $e->user?->displayName() }}</a>:</b> {{ $e->title }}
                    @if ($e->subtitle)<span style="color:var(--muted)"> • {{ $e->subtitle }}</span>@endif
                </div>
            </div>
        @empty
            <div style="text-align:center;color:var(--muted);padding:20px">{{ __('ops.no_activity_today') }}</div>
        @endforelse
    </div>
</div>

@endsection
