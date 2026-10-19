@extends('layouts.system')

@section('title', __('costing.overhead_title'))

@section('content')
@php
    $canAdmin = \App\Support\Access::action(auth()->user(), 'act.costing.admin');
    $m2 = fn ($v) => number_format((float) $v, 2);
    $vClass = fn (float $v) => $v > 0.005 ? 'color:var(--red)' : ($v < -0.005 ? 'color:var(--green)' : '');
    $sumPool = collect($lines)->sum('pool') + $unshared;
    $sumApplied = collect($lines)->sum('applied');
    $sumVar = round($sumPool - $sumApplied, 2);
@endphp

<div class="kpis">
    <div class="kpi"><div class="lbl">{{ __('costing.kpi_pool') }}</div><div class="val">{{ $m2($sumPool) }}</div><div class="sub2">{{ $month->format('Y-m') }}</div></div>
    <div class="kpi"><div class="lbl">{{ __('costing.kpi_applied') }}</div><div class="val">{{ $m2($sumApplied) }}</div></div>
    <div class="kpi {{ $sumVar > 0.005 ? 'neg' : 'pos' }}"><div class="lbl">{{ __('costing.kpi_variance') }}</div><div class="val">{{ $m2($sumVar) }}</div></div>
    <div class="kpi {{ $unshared > 0.005 ? 'mid' : '' }}"><div class="lbl">{{ __('costing.kpi_unshared') }}</div><div class="val">{{ $m2($unshared) }}</div><div class="sub2">{{ __('costing.kpi_unshared_hint') }}</div></div>
</div>

<div class="card">
    <h3>🏭 {{ __('costing.pools_title') }}
        @if ($thisMonthClosed)<span class="badge b-green">{{ __('costing.month_closed') }}</span>@endif
    </h3>
    <div class="s" style="color:var(--muted);margin-bottom:8px">{{ __('costing.overhead_hint') }}</div>

    <form class="searchbar" method="GET" data-noprint>
        <label class="fl"><span>{{ __('costing.month') }}</span>
        <input type="month" name="month" value="{{ $month->format('Y-m') }}"></label>
        <input type="hidden" name="from" value="{{ $range->fromValue() }}">
        <input type="hidden" name="to" value="{{ $range->toValue() }}">
        <button class="btn gold">{{ __('common.search') }}</button>
    </form>

    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('costing.col_line') }}</th>
                <th class="num">{{ __('costing.col_direct') }}</th>
                <th class="num">{{ __('costing.col_shared') }}</th>
                <th class="num">{{ __('costing.col_payroll') }}</th>
                <th class="num">{{ __('costing.col_depreciation') }}</th>
                <th class="num">{{ __('costing.col_pool') }}</th>
                <th class="num">{{ __('costing.col_hours') }}</th>
                <th class="num" data-nosum>{{ __('costing.col_rate') }}</th>
                <th class="num" data-nosum>{{ __('costing.col_estimate') }}</th>
                <th class="num">{{ __('costing.col_applied') }}</th>
                <th class="num">{{ __('costing.col_variance') }}</th>
            </tr>
            @forelse ($lines as $r)
                <tr>
                    <td>{{ $centerName($r['line']) }}</td>
                    <td class="num">{{ $m2($r['direct']) }}</td>
                    <td class="num">{{ $m2($r['shared']) }}</td>
                    <td class="num">{{ $m2($r['payroll']) }}</td>
                    <td class="num">{{ $m2($r['depreciation']) }}</td>
                    <td class="num"><b>{{ $m2($r['pool']) }}</b></td>
                    <td class="num">{{ rtrim(rtrim(number_format((float) $r['hours'], 2, '.', ''), '0'), '.') ?: '0' }}</td>
                    <td class="num">{{ $r['rate'] !== null ? number_format($r['rate'], 2) : '—' }}</td>
                    <td class="num">{{ $r['estimate'] !== null ? number_format($r['estimate'], 2) : '—' }}</td>
                    <td class="num">{{ $m2($r['applied']) }}</td>
                    <td class="num" style="{{ $vClass($r['variance']) }}"><b>{{ $m2($r['variance']) }}</b></td>
                </tr>
            @empty
                <tr><td colspan="11" style="text-align:center;color:var(--muted);padding:28px">{{ __('costing.no_lines') }}</td></tr>
            @endforelse
            <tr>
                <td>{{ __('costing.factory_unshared') }}</td>
                <td colspan="4"></td>
                <td class="num"><b>{{ $m2($unshared) }}</b></td>
                <td colspan="4"></td>
                <td class="num" style="{{ $vClass($unshared) }}"><b>{{ $m2($unshared) }}</b></td>
            </tr>
        </table>
    </div>
</div>

@if ($canAdmin)
<div class="card">
    <h3>🔒 {{ __('costing.close_title') }}</h3>
    <div class="s" style="color:var(--muted);margin-bottom:8px">{{ __('costing.close_hint') }}</div>
    <form class="searchbar" method="POST" action="{{ route('costing.overhead.close') }}"
          onsubmit="return confirm({{ \Illuminate\Support\Js::from(__('costing.close_confirm')) }})">
        @csrf
        <label class="fl"><span>{{ __('costing.month') }}</span>
        <input type="month" name="period" value="{{ $month->format('Y-m') }}" required></label>
        <button class="btn gold" type="submit">{{ __('costing.close_btn') }}</button>
    </form>
    <form class="searchbar" method="POST" action="{{ route('costing.sync') }}"
          onsubmit="return confirm({{ \Illuminate\Support\Js::from(__('costing.sync_confirm')) }})">
        @csrf
        <label class="fl"><span>{{ __('costing.sync_from') }}</span>
        <input type="date" name="from"></label>
        <button class="btn" type="submit">{{ __('costing.sync_btn') }}</button>
    </form>
</div>
@endif

<div class="card">
    <h3>📦 {{ __('costing.orders_title') }}
        <span class="side">{{ __('ui.rows_n', ['n' => $orders->count()]) }}</span>
    </h3>
    <form class="searchbar" method="GET" data-noprint>
        <input type="hidden" name="month" value="{{ $month->format('Y-m') }}">
        @include('partials._range', ['from' => $range->fromValue(), 'to' => $range->toValue()])
        <button class="btn gold">{{ __('common.search') }}</button>
        <a class="btn" href="{{ route('costing.overhead', ['month' => $month->format('Y-m')]) }}">{{ __('common.clear') }}</a>
        <a class="btn sm green" href="{{ request()->fullUrlWithQuery(['export' => 1]) }}">⬇ {{ __('ui.export_all') }}</a>
    </form>
    <div class="tablewrap">
        <table>
            <tr>
                <th data-nosum>{{ __('costing.col_order') }}</th>
                <th data-nosum>{{ __('costing.col_product') }}</th>
                <th data-nosum>{{ __('costing.col_line') }}</th>
                <th data-nosum>{{ __('costing.col_method') }}</th>
                <th data-nosum>{{ __('costing.col_flags') }}</th>
                <th class="num">{{ __('costing.col_kg') }}</th>
                <th class="num" data-nosum>{{ __('costing.col_order_rate') }}</th>
                <th class="num">{{ __('costing.col_overhead') }}</th>
                <th class="num" data-nosum>{{ __('costing.col_unit_cost') }}</th>
                <th data-nosum>{{ __('costing.col_closed_at') }}</th>
            </tr>
            @forelse ($orders as $o)
                <tr>
                    <td>{{ $o->number }}</td>
                    <td>{{ $o->product?->displayName() }}</td>
                    <td>{{ $o->line ? $centerName($o->line) : '—' }}</td>
                    <td>{{ $o->overhead_method ? __('costing.oh_method_'.$o->overhead_method) : '—' }}</td>
                    <td>
                        @foreach ($flagsOf($o) as $f)
                            <span class="badge {{ in_array($f, ['no_rate', 'no_weight'], true) ? 'b-red' : 'b-orange' }}">{{ __('costing.flag_'.$f) }}</span>
                        @endforeach
                    </td>
                    <td class="num">{{ $o->overhead_kg !== null ? number_format((float) $o->overhead_kg, 3) : '—' }}</td>
                    <td class="num">
                        @if ($o->overhead_rate !== null)
                            {{ __($o->overhead_method === 'hours_estimate' ? 'costing.rate_per_hour' : 'costing.rate_per_kg', ['v' => number_format((float) $o->overhead_rate, 4)]) }}
                        @else — @endif
                    </td>
                    <td class="num">{{ $m2($o->overhead_cost) }}</td>
                    <td class="num">{{ $o->unit_cost !== null ? number_format((float) $o->unit_cost, 4) : '—' }}</td>
                    <td class="num">{{ $o->closed_at?->format('Y-m-d') }}</td>
                </tr>
            @empty
                <tr><td colspan="10" style="text-align:center;color:var(--muted);padding:28px">{{ __('costing.no_orders') }}</td></tr>
            @endforelse
        </table>
    </div>
</div>

<div class="card">
    <h3>🗓️ {{ __('costing.closed_months_title') }}</h3>
    <div class="tablewrap">
        <table>
            <tr>
                <th data-nosum>{{ __('costing.col_period') }}</th>
                <th class="num">{{ __('costing.col_summary_pool') }}</th>
                <th class="num">{{ __('costing.col_applied') }}</th>
                <th class="num">{{ __('costing.col_variance') }}</th>
                <th data-nosum>{{ __('costing.col_closed_by') }}</th>
                <th data-nosum>{{ __('costing.col_closed_at') }}</th>
            </tr>
            @forelse ($closedMonths as $cm)
                <tr>
                    <td><a href="{{ route('costing.overhead', ['month' => $cm->period, 'from' => $cm->period.'-01', 'to' => \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $cm->period.'-01')->endOfMonth()->toDateString()]) }}">{{ $cm->period }}</a></td>
                    <td class="num">{{ $m2($cm->summary['pool'] ?? 0) }}</td>
                    <td class="num">{{ $m2($cm->summary['applied'] ?? 0) }}</td>
                    <td class="num" style="{{ $vClass((float) ($cm->summary['variance'] ?? 0)) }}">{{ $m2($cm->summary['variance'] ?? 0) }}</td>
                    <td>{{ $cm->closer?->name }}</td>
                    <td class="num">{{ $cm->closed_at?->format('Y-m-d H:i') }}</td>
                </tr>
            @empty
                <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:28px">{{ __('costing.no_closed_months') }}</td></tr>
            @endforelse
        </table>
    </div>
</div>
@endsection
