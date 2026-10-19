@extends('layouts.system')

@section('title', __('mfg.requirements_title'))

@section('content')
@php
    $num = fn ($v, $dp = 3) => rtrim(rtrim(number_format((float) $v, $dp, '.', ''), '0'), '.') ?: '0';
@endphp
<div class="card">
    <h3>🧮 {{ __('mfg.requirements_title') }}
        <span class="side">{{ __('ui.rows_n', ['n' => $rows->count()]) }}</span>
    </h3>
    <div class="s" style="color:var(--muted);margin-bottom:8px">{{ __('mfg.requirements_hint') }}</div>

    {{-- فلتر «من — إلى» على `planned_on` بتاع الأوامر المخططة والجارية --}}
    <form class="searchbar" method="GET" data-noprint>
        @include('partials._range', ['from' => $range->fromValue(), 'to' => $range->toValue()])
        <button class="btn">{{ __('common.search') }}</button>
        <a class="btn" href="{{ route('mfg.requirements') }}">{{ __('common.clear') }}</a>
        <a class="btn sm green" href="{{ request()->fullUrlWithQuery(['export' => 1]) }}">⬇ {{ __('ui.export_all') }}</a>
    </form>

    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('mfg.col_material') }}</th>
                <th data-nosum>{{ __('mfg.col_unit') }}</th>
                <th class="num" data-nosum>{{ __('mfg.col_orders') }}</th>
                <th class="num">{{ __('mfg.col_std_qty_planned') }}</th>
                <th class="num">{{ __('mfg.col_issued_net') }}</th>
                <th class="num">{{ __('mfg.col_remaining') }}</th>
                <th class="num">{{ __('mfg.col_available') }}</th>
                <th class="num">{{ __('mfg.col_shortage') }}</th>
            </tr>
            @forelse ($rows as $r)
                <tr>
                    <td>{{ $r['product']->code }} — {{ $r['product']->displayName() }}</td>
                    <td>{{ $r['product']->unitLabel() }}</td>
                    <td class="num">{{ $r['orders'] }}</td>
                    <td class="num">{{ $num($r['std_qty']) }}</td>
                    <td class="num">{{ $num($r['issued_net']) }}</td>
                    <td class="num">{{ $num($r['remaining']) }}</td>
                    <td class="num">{{ $num($r['available']) }}</td>
                    <td class="num">
                        @if ($r['shortage'] > 0.0005)
                            <span class="badge b-red">{{ $num($r['shortage']) }}</span>
                        @else
                            <span style="color:var(--muted)">0</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" style="text-align:center;color:var(--muted);padding:28px">{{ __('mfg.no_requirements') }}</td></tr>
            @endforelse
        </table>
    </div>
</div>
@endsection
