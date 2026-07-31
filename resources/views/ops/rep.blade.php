@extends('layouts.system')

@section('title', $u->displayName())

@php $fmt = fn ($n) => number_format((float) $n);         // ⚠️ **مدير الفرع مش هنا.** الراوتس دي `role:admin,manager`،
    // و`isManager()` بترجّع له true — فكان بيشوف الزرار ويترمي على
    // 403 بعد ما يملا الفورم.
    $manager = auth()->user()->canDecideOps(); @endphp

@section('actions')
    <a class="btn" href="{{ route('ops.dashboard') }}">← {{ __('ops.dashboard') }}</a>
    @if ($manager)
        <button class="btn gold" onclick="openDlg('dlgLoad')">+ {{ __('ops.load_van') }}</button>
        @if ($custody && $custody->status === 'open')
            <form method="POST" action="{{ route('ops.rep.close', $u) }}" style="display:inline" onsubmit="return confirm({{ \Illuminate\Support\Js::from(__('ops.confirm_close_van')) }})">
                @csrf<button class="btn red" type="submit">{{ __('ops.close_van_stock') }}</button>
            </form>
        @endif
    @endif
@endsection

@section('content')

<div class="kpis">
    <div class="kpi"><div class="lbl">{{ __('team.role') }}</div><div class="val" style="font-size:17px">{{ $u->roleLabel() }}</div><div class="sub2">{{ $u->code }} • {{ $u->zone?->displayName() ?? __('ops.delivery_run') }}</div></div>
    <div class="kpi"><div class="lbl">{{ $u->isDriver() ? __('ops.delivered_value') : __('ops.sales_today') }}</div>
        <div class="val pos">{{ $fmt($u->isDriver() ? $stats['posValue'] : $stats['sales']) }} {{ __('common.currency') }}</div></div>
    <div class="kpi"><div class="lbl">{{ $u->isDriver() ? __('ops.deliveries') : __('ops.visits') }}</div>
        <div class="val">{{ $u->isDriver() ? $stats['posDone'].'/'.$stats['pos'] : $stats['visitsDone'].'/'.$stats['visits'] }}</div></div>
    <div class="kpi"><div class="lbl">{{ __('ops.van_stock_left') }}</div><div class="val">{{ $stats['remaining'] }}</div><div class="sub2">{{ $fmt($stats['remainingValue']) }} {{ __('common.currency') }}</div></div>
    <div class="kpi"><div class="lbl">{{ __('ops.van_stock_status') }}</div>
        <div class="val" style="font-size:17px">{{ $custody ? ($custody->status === 'open' ? __('ops.open') : __('ops.closed')) : __('common.none') }}</div>
        <div class="sub2">{{ $custody?->date?->format('Y-m-d') ?? '—' }}</div></div>
</div>

@if ($custody)
<div class="card">
    <h3>📦 {{ __('ops.van_stock') }} <span class="side">{{ __('ops.loaded') }} ← {{ __('ops.remaining') }}</span></h3>
    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('common.code') }}</th><th>{{ __('stock.item') }}</th><th>{{ __('stock.unit') }}</th>
                <th>{{ __('ops.loaded') }}</th><th>{{ __('field.sold') }}</th><th>{{ __('ops.remaining') }}</th>
                <th>{{ __('ops.remaining_value') }}</th>
            </tr>
            @foreach ($custody->items as $it)
                <tr>
                    <td class="num">{{ $it->product->code }}</td>
                    <td><b>{{ $it->product->displayName() }}</b></td>
                    <td style="color:var(--muted);font-size:11.5px">{{ $it->product->unitLabel() }}</td>
                    <td class="num">{{ $it->assigned }}</td>
                    <td class="num" style="color:var(--blue)">{{ $it->sold }}</td>
                    <td class="num pos"><b>{{ $it->remaining() }}</b></td>
                    <td class="num">{{ $fmt($it->remaining() * $it->product->priceFor($u->isDriver() ? 'old' : 'new')) }}</td>
                </tr>
            @endforeach
        </table>
    </div>
</div>
@endif

<div class="grid2">
    <div class="card">
        <h3>🧾 {{ __('ops.latest_invoices') }}</h3>
        <div class="tablewrap">
            <table>
                <tr>
                    <th>{{ __('ops.invoice') }}</th><th>{{ __('client.client') }}</th><th>{{ __('ops.payment') }}</th>
                    <th>{{ __('common.total') }}</th><th>{{ __('common.time') }}</th>
                </tr>
                @forelse ($invoices as $inv)
                    <tr class="clickable" onclick="location.href='{{ route('ops.invoice', $inv) }}'">
                        <td><b>{{ $inv->number }}</b></td>
                        <td>{{ $inv->client->displayName() }}</td>
                        <td><span class="badge {{ $inv->payment === 'cash' ? 'b-green' : 'b-orange' }}">{{ $inv->paymentLabel() }}</span></td>
                        <td class="num pos">{{ $fmt($inv->total) }}</td>
                        <td class="num">{{ $inv->created_at->format('m-d H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" style="text-align:center;color:var(--muted);padding:20px">{{ __('ops.no_invoices') }}</td></tr>
                @endforelse
            </table>
        </div>
    </div>

    <div class="card">
        <h3>🛰️ {{ __('ops.todays_timeline') }}</h3>
        <div class="alerts" style="max-height:400px;overflow-y:auto">
            @forelse ($events as $e)
                @php $cls = match ($e->type) { 'sale','deliver' => 'good', 'check_in','start' => 'info', 'request' => 'warn', default => '' }; @endphp
                <div class="alert {{ $cls }}"><div><b>{{ $e->happened_at->format('H:i') }}</b> — {{ $e->title }}
                    @if ($e->subtitle)<span style="color:var(--muted)"> • {{ $e->subtitle }}</span>@endif</div></div>
            @empty
                <div style="text-align:center;color:var(--muted);padding:20px">{{ __('ops.no_activity') }}</div>
            @endforelse
        </div>
    </div>
</div>

@if ($manager)
<dialog id="dlgLoad">
    <form class="dlg" method="POST" action="{{ route('ops.rep.load', $u) }}">
        @csrf
        <h4>{{ __('ops.load_van_for', ['name' => $u->displayName()]) }}</h4>
        <p style="font-size:12px;color:var(--muted);margin-bottom:12px">{{ __('ops.load_hint') }}</p>
        <div style="max-height:50vh;overflow-y:auto">
            <table>
                <tr><th>{{ __('stock.item') }}</th><th>{{ __('stock.unit') }}</th><th style="width:110px">{{ __('common.qty') }}</th></tr>
                @foreach ($products as $p)
                    <tr>
                        <td>{{ $p->code }} — {{ $p->displayName() }}</td>
                        <td style="color:var(--muted);font-size:11px">{{ $p->unitLabel() }}</td>
                        <td><input type="number" min="0" name="qty[{{ $p->id }}]" placeholder="0" style="width:100%"></td>
                    </tr>
                @endforeach
            </table>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px">
            <button class="btn" type="button" onclick="closeDlg('dlgLoad')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('ops.load_van') }}</button>
        </div>
    </form>
</dialog>
@endif

@endsection
