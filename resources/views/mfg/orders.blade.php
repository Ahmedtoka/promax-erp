@extends('layouts.system')

@section('title', __('mfg.orders_title'))

@section('content')
@php
    $badge = ['planned' => 'b-gray', 'in_progress' => 'b-blue', 'finished' => 'b-orange', 'closed' => 'b-green', 'cancelled' => 'b-red'];
@endphp
<div class="card">
    <h3>📋 {{ __('mfg.orders_title') }}
        <span class="side">{{ __('ui.rows_n', ['n' => $orders->total()]) }}</span>
    </h3>

    {{-- فلتر «من — إلى» على `planned_on` — تاريخ الخطة مش وقت الإدخال --}}
    <form class="searchbar" method="GET" data-noprint>
        <label class="fl"><span>{{ __('ui.l_status') }}</span>
        <select name="status">
            <option value="">{{ __('mfg.all_statuses') }}</option>
            @foreach (\App\Models\ProductionOrder::STATUSES as $st)
                <option value="{{ $st }}" @selected(($filters['status'] ?? '') === $st)>{{ __('mfg.order_status_'.$st) }}</option>
            @endforeach
        </select></label>
        @include('partials._range', ['from' => $range->fromValue(), 'to' => $range->toValue()])
        <button class="btn gold">{{ __('common.search') }}</button>
        <a class="btn" href="{{ route('mfg.orders') }}">{{ __('common.clear') }}</a>
        <a class="btn sm green" href="{{ request()->fullUrlWithQuery(['export' => 1, 'page' => null]) }}">⬇ {{ __('ui.export_all') }}</a>
    </form>

    <div class="tablewrap">
        <table>
            <tr>
                <th data-nosum>{{ __('mfg.col_number') }}</th>
                <th>{{ __('mfg.col_product') }}</th>
                <th>{{ __('mfg.col_line') }}</th>
                <th data-nosum>{{ __('mfg.col_batch_no') }}</th>
                <th data-nosum>{{ __('mfg.col_planned_on') }}</th>
                <th class="num">{{ __('mfg.col_planned_qty') }}</th>
                <th class="num">{{ __('mfg.col_output_qty') }}</th>
                <th>{{ __('mfg.col_status') }}</th>
            </tr>
            @forelse ($orders as $o)
                <tr>
                    <td class="num"><b>{{ $o->number }}</b></td>
                    <td>{{ $o->product?->displayName() ?? '—' }}</td>
                    <td>{{ $o->line?->displayName() ?? '—' }}</td>
                    <td class="num">{{ $o->batch_no }}</td>
                    <td class="num s">{{ $o->planned_on?->format('Y-m-d') }}</td>
                    <td class="num">{{ number_format($o->planned_qty) }}</td>
                    <td class="num">{{ $o->output_qty !== null ? number_format($o->output_qty) : '—' }}</td>
                    <td><span class="badge {{ $badge[$o->status] ?? 'b-gray' }}">{{ $o->statusLabel() }}</span></td>
                </tr>
            @empty
                <tr><td colspan="8" style="text-align:center;color:var(--muted);padding:28px">{{ __('mfg.no_orders') }}</td></tr>
            @endforelse
        </table>
    </div>
    @include('partials._pagination', ['p' => $orders])
</div>
@endsection
