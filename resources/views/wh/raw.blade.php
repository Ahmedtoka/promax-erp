@extends('layouts.system')

{{-- مخزن الخامات والتعبئة: تاب الباتشات (FEFO) وتاب سجل الصرف --}}

@php
    $fmt = fn ($n) => number_format((float) $n);
    $base = ['tab' => $tab, 'kind' => $kind ?: null, 'q' => $search ?: null, 'warehouse' => $warehouseId ?: null] + $range->query();
@endphp

@section('title', __('nav.raw_store'))

@section('actions')
    <a class="btn" href="{{ route('wh.raw', array_filter($base) + ['export' => 1]) }}">⬇️ {{ __('common.export') }}</a>
@endsection

@section('content')

<div class="searchbar">
    <a class="btn {{ $tab === 'batches' ? 'gold' : '' }}" href="{{ route('wh.raw', array_filter(['kind' => $kind ?: null, 'q' => $search ?: null, 'warehouse' => $warehouseId ?: null] + $range->query())) }}">{{ __('stock.raw_tab_batches') }}</a>
    <a class="btn {{ $tab === 'moves' ? 'gold' : '' }}" href="{{ route('wh.raw', array_filter(['tab' => 'moves', 'kind' => $kind ?: null, 'q' => $search ?: null, 'warehouse' => $warehouseId ?: null] + $range->query())) }}">{{ __('stock.raw_tab_moves') }}</a>
</div>

<form method="GET" class="searchbar" style="margin-bottom:12px" data-noprint>
    <input type="hidden" name="tab" value="{{ $tab }}">
    @if ($warehouses->count() > 1)
        <select name="warehouse" onchange="this.form.submit()">
            <option value="">{{ __('stock.all_warehouses') }}</option>
            @foreach ($warehouses as $w)
                <option value="{{ $w->id }}" @selected($warehouseId === $w->id)>{{ $w->displayName() }}</option>
            @endforeach
        </select>
    @endif
    <select name="kind" onchange="this.form.submit()">
        <option value="">{{ __('stock.raw_kind_all') }}</option>
        @foreach (['raw', 'packaging'] as $k)
            <option value="{{ $k }}" @selected($kind === $k)>{{ __('stock.product_kind_'.$k) }}</option>
        @endforeach
    </select>
    <input type="search" name="q" value="{{ $search }}" placeholder="{{ __('stock.raw_search') }}">
    <button class="btn" type="submit">{{ __('common.search') }}</button>
    @include('partials._range', ['from' => $range->fromValue(), 'to' => $range->toValue(), 'auto' => true])
    <span style="font-size:11px;color:var(--muted);align-self:flex-end;padding-bottom:9px">ℹ️ {{ __('stock.raw_range_note') }}</span>
</form>

@if ($tab === 'batches')
    <div class="kpis">
        <div class="kpi"><div class="lbl">{{ __('stock.raw_lots') }}</div><div class="val">{{ $fmt($kpi['lots']) }}</div></div>
        <div class="kpi"><div class="lbl">{{ __('stock.raw_qty_total') }}</div><div class="val">{{ $fmt($kpi['qty']) }}</div></div>
        <div class="kpi"><div class="lbl">{{ __('stock.raw_value_total') }}</div><div class="val">{{ number_format($kpi['value'], 2) }}</div></div>
    </div>

    <div class="card">
        @if ($rows->isEmpty())
            <div class="alert good"><span>✅</span><span>{{ __('stock.raw_none') }}</span></div>
        @else
            <div class="tablewrap">
                <table>
                    <tr>
                        <th style="text-align:start">{{ __('stock.item') }}</th>
                        <th>{{ __('stock.raw_kind') }}</th>
                        <th>{{ __('stock.warehouse') }}</th>
                        <th>{{ __('stock.raw_lot') }}</th>
                        <th data-nosum>{{ __('stock.raw_expiry') }}</th>
                        <th class="num">{{ __('stock.raw_qty') }}</th>
                        <th class="num" data-nosum>{{ __('stock.raw_unit_cost') }}</th>
                        <th class="num">{{ __('stock.raw_value') }}</th>
                    </tr>
                    @foreach ($rows as $b)
                        <tr>
                            <td style="text-align:start"><b>{{ $b->product?->displayName() ?? '—' }}</b></td>
                            <td><span class="badge b-blue">{{ $b->product?->kindLabel() }}</span></td>
                            <td class="s">{{ $b->warehouse?->displayName() ?? '—' }}</td>
                            <td class="num">{{ $b->batch_no }}</td>
                            <td class="num">
                                {{ $b->expires_on?->format('Y-m-d') ?? '—' }}
                                @if ($b->expires_on)<div><span class="badge {{ $b->expiryClass() }}">{{ $b->expiryLabel() }}</span></div>@endif
                            </td>
                            <td class="num"><b>{{ $fmt($b->qty_remaining) }}</b></td>
                            <td class="num">{{ number_format((float) $b->cost, 2) }}</td>
                            <td class="num">{{ number_format($b->qty_remaining * (float) $b->cost, 2) }}</td>
                        </tr>
                    @endforeach
                </table>
            </div>
        @endif
    </div>
@else
    <div class="card">
        @if ($moves->isEmpty())
            <div class="alert good"><span>✅</span><span>{{ __('stock.raw_moves_none') }}</span></div>
        @else
            <div class="tablewrap">
                <table>
                    <tr>
                        <th data-nosum>{{ __('stock.raw_moved_at') }}</th>
                        <th style="text-align:start">{{ __('stock.item') }}</th>
                        <th>{{ __('stock.warehouse') }}</th>
                        <th>{{ __('stock.raw_lot') }}</th>
                        <th class="num">{{ __('stock.raw_qty') }}</th>
                        <th class="num" data-nosum>{{ __('stock.raw_unit_cost') }}</th>
                        <th>{{ __('stock.raw_for') }}</th>
                        <th>{{ __('stock.raw_by') }}</th>
                    </tr>
                    @foreach ($moves as $m)
                        <tr>
                            <td class="num">{{ $m->moved_at?->format('Y-m-d H:i') }}</td>
                            <td style="text-align:start"><b>{{ $m->product?->displayName() ?? '—' }}</b></td>
                            <td class="s">{{ $m->warehouse?->displayName() ?? '—' }}</td>
                            <td class="num">{{ $m->batch?->batch_no ?? '—' }}</td>
                            <td class="num"><b>{{ $fmt($m->qty) }}</b></td>
                            <td class="num">{{ number_format((float) $m->unit_cost, 2) }}</td>
                            <td>{{ $forLabel($m) }}</td>
                            <td class="s">{{ $m->creator?->name ?? '—' }}</td>
                        </tr>
                    @endforeach
                </table>
            </div>
        @endif
    </div>
@endif

@endsection
