@extends('layouts.system')

{{--
    قفل اليوم — يومية الحسابات (2026-08-06): أرقام اليوم لايف،
    وزرار القفل بيجمدها سنابشوت — كشف يومي دائم + هيستوري الأيام.
--}}

@php
    $fmt = fn ($n) => number_format((float) $n, 2);
    $fmtI = fn ($n) => number_format((float) $n);
    $g = $figures;
@endphp

@section('title', __('incent.dayclose_title'))

@section('actions')
    <a class="btn" href="{{ route('erp.repclose') }}">🤝 {{ __('settle.title') }}</a>
@endsection

@section('content')

@if (session('ok'))
    <div class="alert good" style="margin-bottom:12px"><span>✅</span><span>{{ session('ok') }}</span></div>
@endif
@if ($errors->any())
    <div class="alert" style="margin-bottom:12px;flex-direction:column;align-items:stretch;gap:4px">
        @foreach ($errors->all() as $msg)<div class="errline" style="margin:0">{{ $msg }}</div>@endforeach
    </div>
@endif

<div class="card">
    <h3>📅 {{ __('incent.dayclose_title') }}
        <span class="side">{{ __('incent.dayclose_hint') }}</span>
        <span class="badge {{ $close ? 'b-green' : 'b-orange' }}" style="margin-inline-start:auto">
            {{ $close ? __('incent.day_closed') : __('incent.day_open') }}
        </span>
    </h3>

    <div class="searchbar" style="margin-bottom:12px">
        <form method="GET" style="display:flex;gap:8px;align-items:center">
            <input type="date" name="date" value="{{ $date->toDateString() }}" onchange="this.form.submit()">
        </form>
        @if (! $close)
            <form method="POST" action="{{ route('erp.dayclose.store') }}" style="margin-inline-start:auto;display:flex;gap:8px;align-items:center"
                  onsubmit="return confirm(@js(__('incent.close_day_confirm')))">
                @csrf
                <input type="hidden" name="date" value="{{ $date->toDateString() }}">
                <input type="text" name="notes" maxlength="1000" placeholder="{{ __('settle.note') }}" style="min-width:240px">
                <button class="btn gold" type="submit">🔒 {{ __('incent.close_day') }}</button>
            </form>
        @endif
    </div>

    {{-- المقفول بيعرض السنابشوت المجمد — والمفتوح بيعرض اللايف --}}
    @php $s = $close ?? null; $v = fn ($key) => $s ? $s->{$key} : $g[$key]; @endphp
    <div class="kpis">
        <div class="kpi"><div class="lbl">🧾 {{ __('incent.invoices_count') }}</div><div class="val">{{ $fmtI($v('invoices_count')) }}</div><div class="sub2">{{ $fmtI($v('clients_count')) }} {{ __('incent.clients_count') }}</div></div>
        <div class="kpi"><div class="lbl">💵 {{ __('incent.sales_cash') }}</div><div class="val pos">{{ $fmt($v('sales_cash')) }}</div></div>
        <div class="kpi"><div class="lbl">📒 {{ __('incent.sales_credit') }}</div><div class="val mid">{{ $fmt($v('sales_credit')) }}</div></div>
        <div class="kpi"><div class="lbl">📈 {{ __('incent.sales_net') }}</div><div class="val" style="color:var(--primary)">{{ $fmt($v('sales_net')) }}</div></div>
        <div class="kpi"><div class="lbl">↩️ {{ __('incent.returns_total') }}</div><div class="val neg">{{ $fmt($v('returns_total')) }}</div></div>
        <div class="kpi"><div class="lbl">💰 {{ __('incent.collections_total') }}</div><div class="val pos">{{ $fmt($v('collections_total')) }}</div></div>
        <div class="kpi"><div class="lbl">🚚 {{ __('incent.pos_delivered') }}</div><div class="val">{{ $fmtI($v('pos_delivered_count')) }}</div><div class="sub2">{{ $fmt($v('pos_delivered_value')) }} {{ __('common.currency') }}</div></div>
        <div class="kpi"><div class="lbl">🤝 {{ __('incent.settlements') }}</div><div class="val">{{ $fmtI($v('settlements_count')) }}</div><div class="sub2">{{ __('incent.received_total') }}: {{ $fmt($v('settlements_received')) }} · {{ __('incent.carried_total') }}: {{ $fmt($v('settlements_balance')) }}</div></div>
    </div>

    @if ($close)
        <div style="font-size:11.5px;color:var(--muted);margin-top:8px">
            🔒 {{ __('incent.closed_by') }}: <b style="color:var(--ink)">{{ $close->closer?->name ?? '—' }}</b>
            · {{ $close->created_at->format('Y-m-d h:i A') }}
            @if ($close->notes) · {{ $close->notes }}@endif
        </div>
    @endif
</div>

{{-- ═══ الأيام المقفولة — الكشف اليومي الدائم ═══ --}}
<div class="card">
    <h3>🗂️ {{ __('incent.history') }}</h3>
    <div class="tablewrap dc-tbl">
        <table>
            <tr>
                <th>{{ __('common.date') }}</th>
                <th>{{ __('incent.invoices_count') }}</th>
                <th>{{ __('incent.sales_cash') }}</th>
                <th>{{ __('incent.sales_credit') }}</th>
                <th>{{ __('incent.sales_net') }}</th>
                <th>{{ __('incent.returns_total') }}</th>
                <th>{{ __('incent.collections_total') }}</th>
                <th>{{ __('incent.pos_delivered') }}</th>
                <th>{{ __('incent.received_total') }}</th>
                <th>{{ __('incent.closed_by') }}</th>
            </tr>
            @forelse ($history as $h)
                <tr>
                    <td class="num"><a href="{{ route('erp.dayclose', ['date' => $h->date->toDateString()]) }}"><b>{{ $h->date->format('Y-m-d') }}</b></a></td>
                    <td class="num">{{ $fmtI($h->invoices_count) }}</td>
                    <td class="num pos">{{ $fmt($h->sales_cash) }}</td>
                    <td class="num mid">{{ $fmt($h->sales_credit) }}</td>
                    <td class="num"><b>{{ $fmt($h->sales_net) }}</b></td>
                    <td class="num neg">{{ $fmt($h->returns_total) }}</td>
                    <td class="num">{{ $fmt($h->collections_total) }}</td>
                    <td class="num">{{ $fmtI($h->pos_delivered_count) }}</td>
                    <td class="num pos"><b>{{ $fmt($h->settlements_received) }}</b></td>
                    <td class="s">{{ $h->closer?->name ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="10" style="text-align:center;color:var(--muted);padding:24px">{{ __('incent.no_closes') }}</td></tr>
            @endforelse
        </table>
    </div>
</div>

@endsection

@section('scripts')
<style>.dc-tbl th, .dc-tbl td { text-align: center; vertical-align: middle; }</style>
@endsection
