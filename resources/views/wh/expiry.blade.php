@extends('layouts.system')

@section('title', __('stock.expiry_report'))

@php
    $fmt = fn ($n) => number_format((float) $n);

    $bucketMeta = [
        'expired' => ['label' => __('stock.expiry_expired'), 'badge' => 'b-red', 'val' => 'neg', 'icon' => '⛔'],
        'danger' => ['label' => __('stock.expiry_danger'), 'badge' => 'b-red', 'val' => 'neg', 'icon' => '🔴'],
        'warn' => ['label' => __('stock.expiry_warn'), 'badge' => 'b-orange', 'val' => 'mid', 'icon' => '🟠'],
        'ok' => ['label' => __('stock.expiry_ok'), 'badge' => 'b-green', 'val' => 'pos', 'icon' => '🟢'],
    ];
@endphp

@section('actions')
    <a class="btn" href="{{ route('wh.index', $warehouse ? ['warehouse' => $warehouse->id] : []) }}">🏭 {{ __('stock.warehouse_overview') }}</a>
    <a class="btn" href="{{ route('wh.locations', $warehouse ? ['warehouse' => $warehouse->id] : []) }}">🗄️ {{ __('stock.shelf_map') }}</a>
@endsection

@section('content')

@if ($warehouses->count() > 1)
    <div class="searchbar">
        <span style="font-size:11.5px;font-weight:800;color:var(--muted)">{{ __('stock.warehouses') }}</span>
        @foreach ($warehouses as $w)
            <a class="btn {{ $warehouse && $w->id === $warehouse->id ? 'gold' : '' }}"
               href="{{ route('wh.expiry', ['warehouse' => $w->id]) }}">{{ $w->displayName() }}</a>
        @endforeach
    </div>
@endif

<div class="alert info" style="margin-bottom:14px">
    <span>⏳</span>
    <span>{{ __('stock.fefo_note') }} {{ __('stock.pick_face_hint') }}</span>
</div>

<div class="kpis">
    @foreach ($bucketMeta as $key => $meta)
        @php $bucket = $buckets[$key] ?? collect(); @endphp
        <div class="kpi">
            <div class="lbl">{{ $meta['icon'] }} {{ $meta['label'] }}</div>
            <div class="val {{ $meta['val'] }}">{{ $fmt($bucket->sum('qty')) }}</div>
            <div class="sub2">{{ __('stock.units') }} • {{ __('stock.batch_countable', ['count' => $bucket->count()]) }}</div>
        </div>
    @endforeach
</div>

@foreach ($bucketMeta as $key => $meta)
    @php $bucket = $buckets[$key] ?? collect(); @endphp
    @if ($bucket->isNotEmpty())
        <div class="card">
            <h3>{{ $meta['icon'] }} {{ $meta['label'] }}
                <span class="side">{{ $fmt($bucket->sum('qty')) }} {{ __('stock.units') }}</span></h3>
            <div class="tablewrap">
                <table>
                    <tr>
                        <th>{{ __('stock.location') }}</th>
                        <th>{{ __('stock.item') }}</th>
                        <th>{{ __('stock.batch_no') }}</th>
                        <th>{{ __('stock.expires_on') }}</th>
                        <th>{{ __('stock.expiry') }}</th>
                        <th>{{ __('common.qty') }}</th>
                    </tr>
                    @foreach ($bucket as $r)
                        <tr>
                            <td>
                                <b style="font-size:16px;letter-spacing:.4px">{{ $r->location?->code ?? '—' }}</b>
                                @if ($r->location?->is_pick_face)
                                    <span class="badge b-purple">★ {{ __('stock.pick_face') }}</span>
                                @endif
                                @if ($r->location)
                                    <br><span style="font-size:10.5px;color:var(--muted)">
                                        {{ __('stock.stand') }} {{ $r->location->stand }} • {{ __('stock.level') }} {{ $r->location->level }}
                                    </span>
                                @endif
                            </td>
                            <td>
                                <b>{{ $r->product?->displayName() ?? $r->batch?->product?->displayName() ?? '—' }}</b>
                                @if ($r->batch?->product)
                                    <br><span style="font-size:10.5px;color:var(--muted)">{{ $r->batch->product->code }} • {{ $r->batch->product->unitLabel() }}</span>
                                @endif
                            </td>
                            <td class="num"><b>{{ $r->batch?->batch_no ?? '—' }}</b></td>
                            <td class="num">{{ $r->batch?->expires_on?->format('Y-m-d') ?? '—' }}</td>
                            <td>
                                @if ($r->batch)
                                    <span class="badge {{ $r->batch->expiryClass() }}">{{ $r->batch->expiryLabel() }}</span>
                                @else
                                    <span class="badge b-gray">—</span>
                                @endif
                            </td>
                            <td class="num"><b>{{ $fmt($r->qty) }}</b></td>
                        </tr>
                    @endforeach
                </table>
            </div>
        </div>
    @endif
@endforeach

@if ($rows->isEmpty())
    <div class="card">
        <div class="alert good"><span>✅</span><span>{{ __('common.no_results') }}</span></div>
    </div>
@endif

@endsection
