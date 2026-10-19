@extends('layouts.system')

{{--
    الجرد اليومي للخزن (Lane 4، L4-D5) — المتوقع = رصيد الخزنة في الدفتر
    لحد تاريخ الجرد وقت الحفظ، والفرق (المعدود − المتوقع) بيترحّل
    `cash.count` على عجز وزيادة الخزنة. جرد واحد مرحّل لكل خزنة في اليوم.
    الجرد الجديد بيتسجّل من زرار «جرد» في شاشة الخزن.
--}}

@section('title', __('cash.counts'))

@php
    $fmt = fn ($n) => number_format((float) $n, 2);
    $canPost = \App\Support\Access::action(auth()->user(), 'act.gl.post');
    $cols = $canPost ? 10 : 9;
@endphp

@section('actions')
    <a class="btn sm green" href="{{ request()->fullUrlWithQuery(['export' => 1, 'page' => null]) }}">⬇ {{ __('ui.export_all') }}</a>
    <a class="btn sm" href="{{ route('gl.safes') }}">🏦 {{ __('cash.safes') }}</a>
@endsection

@section('content')

@if ($errors->any())
    <div class="alert bad" style="margin-bottom:12px"><span>⛔</span><span>{{ $errors->first() }}</span></div>
@endif

<div class="kpis">
    <div class="kpi" style="grid-column:span 2">
        <div class="lbl">{{ __('cash.shortage_total') }}</div>
        <div class="val {{ $kpis['shortage'] < 0 ? 'neg' : '' }}" dir="ltr">{{ $fmt(abs($kpis['shortage'])) }}</div>
    </div>
    <div class="kpi" style="grid-column:span 2">
        <div class="lbl">{{ __('cash.overage_total') }}</div>
        <div class="val {{ $kpis['overage'] > 0 ? 'pos' : '' }}" dir="ltr">{{ $fmt($kpis['overage']) }}</div>
    </div>
    <div class="kpi" style="grid-column:span 2">
        <div class="lbl">{{ __('cash.n_counts') }}</div>
        <div class="val">{{ number_format($kpis['n']) }}</div>
        <div class="sub2">{{ __('cash.n_counts_sub') }}</div>
    </div>
</div>

<div class="card" id="counts-table">
    <h3>🧮 {{ __('cash.counts') }} <span class="side">{{ __('cash.counts_sub') }}</span></h3>

    <form method="GET" class="searchbar" data-noprint>
        <label class="fl"><span>{{ __('cash.safe') }}</span>
            <select name="safe_id" onchange="this.form.submit()">
                <option value="">{{ __('cash.all_safes') }}</option>
                @foreach ($safes as $sf)
                    <option value="{{ $sf->id }}" @selected((string) request('safe_id') === (string) $sf->id)>{{ $sf->displayName() }} ({{ $sf->code }})</option>
                @endforeach
            </select></label>
        <label class="fl"><span>{{ __('cash.count_status') }}</span>
            <select name="status" onchange="this.form.submit()">
                <option value="">{{ __('cash.all_statuses') }}</option>
                @foreach (\App\Models\SafeCount::STATUSES as $st)
                    <option value="{{ $st }}" @selected(request('status') === $st)>{{ __('cash.count_status_'.$st) }}</option>
                @endforeach
            </select></label>
        {{-- ⚠️ `all => false`: الفاضي = الشهر الحالي (`DateRange` month) --}}
        @include('partials._range', ['from' => $range->fromValue(), 'to' => $range->toValue(), 'auto' => true, 'all' => false])
        <button class="btn gold" type="submit">{{ __('common.filter') }}</button>
        <a class="btn" href="{{ route('gl.safes.counts') }}">{{ __('common.clear') }}</a>
    </form>

    <div class="tablewrap">
        <table>
            <tr>
                <th data-nosum>{{ __('cash.count_number') }}</th>
                <th>{{ __('common.date') }}</th>
                <th style="text-align:start">{{ __('cash.safe') }}</th>
                <th class="num">{{ __('cash.expected') }}</th>
                <th class="num">{{ __('cash.counted') }}</th>
                <th class="num">{{ __('cash.difference') }}</th>
                <th style="text-align:start">{{ __('cash.count_note') }}</th>
                <th style="text-align:start">{{ __('cash.count_by') }}</th>
                <th>{{ __('cash.count_status') }}</th>
                @if ($canPost)<th></th>@endif
            </tr>
            @forelse ($rows as $c)
                @php $d = (float) $c->difference; @endphp
                <tr @if ($c->status === 'void') style="opacity:.6" @endif>
                    <td><b>{{ $c->number }}</b></td>
                    <td class="num" style="font-size:11px">{{ $c->date?->format('Y-m-d') }}</td>
                    <td style="text-align:start">{{ $c->safe?->displayName() ?? '—' }}
                        <div style="font-size:10px;color:var(--muted)">{{ $c->safe?->code }}</div>
                    </td>
                    <td class="num">{{ $fmt($c->expected) }}</td>
                    <td class="num">{{ $fmt($c->counted) }}</td>
                    <td class="num">
                        <b class="{{ $d < 0 ? 'neg' : ($d > 0 ? 'pos' : '') }}">{{ $fmt($d) }}</b>
                        <div>
                            @if ($d < 0)
                                <span class="badge b-red">{{ __('cash.shortage') }}</span>
                            @elseif ($d > 0)
                                <span class="badge b-green">{{ __('cash.overage') }}</span>
                            @else
                                <span class="badge b-blue">{{ __('cash.exact') }}</span>
                            @endif
                        </div>
                    </td>
                    <td style="text-align:start;font-size:11px">{{ $c->note ?: '—' }}</td>
                    <td style="text-align:start">{{ $c->creator?->displayName() ?? '—' }}</td>
                    <td><span class="badge {{ $c->status === 'posted' ? 'b-green' : 'b-red' }}">{{ __('cash.count_status_'.$c->status) }}</span></td>
                    @if ($canPost)
                        <td>
                            @if ($c->status === 'posted')
                                <form method="POST" action="{{ route('gl.safes.counts.void', $c) }}" style="display:inline"
                                      onsubmit="return confirm(@js(__('cash.confirm_void_count')))">
                                    @csrf
                                    <button class="btn sm" type="submit">✕ {{ __('cash.void') }}</button>
                                </form>
                            @endif
                        </td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="{{ $cols }}" style="color:var(--muted);font-size:12px">{{ __('cash.no_counts') }}</td></tr>
            @endforelse
        </table>
    </div>

    @include('partials._pagination', ['p' => $rows])
</div>

@endsection
