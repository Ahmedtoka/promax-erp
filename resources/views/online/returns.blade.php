@extends('layouts.system')

@section('title', __('online.returns_title'))

@php
    $money = fn ($v) => number_format((float) $v, 2);
    $f = fn (array $extra) => route('online.returns', array_filter($extra + request()->only(['search', 'from', 'to'])));
@endphp

@section('content')

@if ($errors->any())
    <div class="alert" style="margin-bottom:12px">{{ $errors->first() }}</div>
@endif
@if (session('ok'))
    <div class="alert good" style="margin-bottom:12px">{{ session('ok') }}</div>
@endif

{{-- الكروت فلاتر على نفس الجدول — دوسة تانية بتشيل الفلتر --}}
<div class="kpis">
    <a class="kpi {{ $state === '' ? 'on' : '' }}" href="{{ $f([]) }}">
        <div class="lbl">{{ __('online.k_returns') }}</div>
        <div class="val">{{ (int) $totals->n }}</div>
        <div class="sub2">{{ __('online.k_returns_how', ['p' => (int) $totals->p]) }}</div>
    </a>
    <a class="kpi" href="{{ $f([]) }}">
        <div class="lbl">{{ __('online.k_returns_value') }}</div>
        <div class="val neg">{{ $money($totals->v) }}</div>
        <div class="sub2">{{ __('online.k_returns_value_how') }}</div>
    </a>
    <a class="kpi {{ $state === 'stock' ? 'on' : '' }}" href="{{ $f($state === 'stock' ? [] : ['state' => 'stock']) }}">
        <div class="lbl">{{ __('online.k_in_stock') }}</div>
        <div class="val mid">{{ (int) $totals->stock_n }}</div>
        <div class="sub2">{{ __('online.k_in_stock_how') }}</div>
    </a>
    <a class="kpi {{ $state === 'reshipped' ? 'on' : '' }}" href="{{ $f($state === 'reshipped' ? [] : ['state' => 'reshipped']) }}">
        <div class="lbl">{{ __('online.k_reshipped') }}</div>
        <div class="val pos">{{ (int) $totals->reshipped_n }}</div>
        <div class="sub2">{{ __('online.k_reshipped_how') }}</div>
    </a>
</div>

<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:10px">
        <h3 style="margin:0">↩️ {{ __('online.returns_title') }}</h3>
        <form method="GET" class="searchbar" style="margin:0;align-items:flex-end">
            @if ($state !== '')
                <input type="hidden" name="state" value="{{ $state }}">
            @endif
            <label class="fl"><span>{{ __('ui.l_search') }}</span>
                <input type="text" name="search" value="{{ request('search') }}" placeholder="🔎 {{ __('common.search') }}"></label>
            @include('partials._range', ['from' => $range->fromValue(), 'to' => $range->toValue(), 'auto' => true])
            <button class="btn gold" type="submit">{{ __('common.search') }}</button>
        </form>
    </div>
    <div class="dash-hint" style="margin-bottom:10px">{{ __('online.returns_hint') }}</div>

    <div class="tablewrap frz" style="max-height:68vh;overflow:auto">
        <table>
            <tr>
                <th data-nosum>{{ __('common.date') }}</th>
                <th>{{ __('online.shopify_no') }}</th>
                <th>{{ __('common.name') }}</th>
                <th>{{ __('online.area') }}</th>
                <th>{{ __('online.pickup_no') }}</th>
                <th>{{ __('online.return_kind') }}</th>
                <th>{{ __('online.return_lines') }}</th>
                <th class="num">{{ __('online.pieces') }}</th>
                <th class="num">{{ __('online.return_value') }}</th>
                <th>{{ __('common.status') }}</th>
                <th></th>
            </tr>
            @forelse ($rows as $r)
                @php $o = $r->order; @endphp
                <tr>
                    <td class="s">{{ $r->created_at?->format('Y-m-d h:i A') ?: '—' }}</td>
                    <td class="num s">@if ($o)<a href="{{ route('online.invoice', $o) }}"><b>#{{ $o->number }}</b></a>@else — @endif</td>
                    <td>{{ $o?->customer_name ?: '—' }}</td>
                    <td class="s">{{ $o?->area ?: '—' }}</td>
                    <td class="num s">
                        @if ($r->pickup)
                            <a href="{{ route('online.pickup', $r->pickup) }}" style="font-weight:900;color:var(--royal-blue)">{{ $r->pickup->number }}</a>
                        @else
                            —
                        @endif
                    </td>
                    <td><span class="badge {{ $r->kind === 'full' ? 'b-red' : 'b-orange' }}">{{ __('online.return_kind_'.$r->kind) }}</span></td>
                    <td style="min-width:200px;white-space:normal">
                        @foreach ($r->lines ?? [] as $l)
                            <div style="font-size:11px">{{ (int) ($l['qty'] ?? 0) }} × {{ $l['title'] ?? '—' }}</div>
                        @endforeach
                    </td>
                    <td class="num">{{ (int) $r->pieces }}</td>
                    <td class="num neg">{{ $money($r->value) }}</td>
                    <td>
                        @if ($r->reshipped_at)
                            <span class="badge b-green">🔁 {{ __('online.return_reshipped') }}</span>
                            <div style="font-size:10.5px;color:var(--muted)">{{ $r->reshipped_at->format('Y-m-d') }}
                                @if ($r->reshipPick) · {{ $r->reshipPick->number }} @endif</div>
                        @else
                            <span class="badge b-gold">📦 {{ __('online.return_in_stock') }}</span>
                        @endif
                    </td>
                    <td class="num">
                        @if ($o && $r->canReship())
                            @include('online._reship_btn', ['o' => $o])
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="11" style="text-align:center;color:var(--muted);padding:28px">{{ __('online.returns_empty') }}</td></tr>
            @endforelse
        </table>
    </div>

    @include('partials._pagination', ['p' => $rows])
</div>

@endsection
