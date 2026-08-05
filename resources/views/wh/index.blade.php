@extends('layouts.system')

@section('title', __('stock.warehouse_overview'))

@php
    $fmt = fn ($n) => number_format((float) $n);
    $awaiting = (int) $pending->sum(fn ($b) => $b->unshelvedQty());
@endphp

@section('actions')
    <a class="btn" href="{{ route('wh.receipts', ['warehouse' => $warehouse->id]) }}">📥 {{ __('stock.goods_receipts') }}</a>
    <a class="btn" href="{{ route('wh.locations', ['warehouse' => $warehouse->id]) }}">🗄️ {{ __('stock.locations') }}</a>
    <a class="btn gold" href="{{ route('wh.expiry', ['warehouse' => $warehouse->id]) }}">⏳ {{ __('stock.expiry_report') }}</a>
@endsection

@section('content')

@if ($warehouses->count() > 1)
    <div class="searchbar">
        <span style="font-size:11.5px;font-weight:800;color:var(--muted)">{{ __('stock.warehouses') }}</span>
        @foreach ($warehouses as $w)
            <a class="btn {{ $w->id === $warehouse->id ? 'gold' : '' }}"
               href="{{ route('wh.index', ['warehouse' => $w->id]) }}">
                {{ $w->displayName() }} <span style="font-size:10.5px;color:var(--muted)">{{ $w->typeLabel() }}</span>
            </a>
        @endforeach
    </div>
@endif

<div class="kpis">
    <div class="kpi">
        <div class="lbl">{{ __('stock.total_in_wh') }}</div>
        <div class="val">{{ $fmt($stockUnits) }}</div>
        <div class="sub2">{{ $warehouse->displayName() }} — {{ $warehouse->typeLabel() }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('stock.available_units') }}</div>
        <div class="val pos">{{ $fmt($availableUnits) }}</div>
        <div class="sub2">{{ __('stock.available_hint') }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('stock.shelf_count') }}</div>
        <div class="val">{{ $fmt($locationCount) }}</div>
        <div class="sub2">{{ __('stock.shelf_map') }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('stock.awaiting_putaway') }}</div>
        <div class="val {{ $awaiting > 0 ? 'mid' : 'pos' }}">{{ $fmt($awaiting) }}</div>
        <div class="sub2">{{ __('stock.batch_countable', ['count' => $pending->count()]) }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('stock.expiry_warn') }}</div>
        <div class="val {{ $expiring->count() > 0 ? 'mid' : 'pos' }}">{{ $fmt($expiring->count()) }}</div>
        <div class="sub2">{{ __('stock.expiring_soon_count', ['count' => $expiring->count()]) }}</div>
    </div>
</div>

@if ($expired->isNotEmpty())
    <div class="alert" style="margin-bottom:14px">
        <span>⛔</span>
        <span>
            {{ __('stock.expired_count', ['count' => $expired->count()]) }}
            <a href="{{ route('wh.expiry', ['warehouse' => $warehouse->id]) }}"
               style="font-weight:800;color:var(--red);margin-inline-start:6px">{{ __('stock.view_all') }}</a>
        </span>
    </div>
@endif

<div class="card">
    <h3>📦 {{ __('stock.awaiting_putaway') }}
        <span class="side">{{ __('stock.awaiting_putaway_hint') }}</span></h3>

    @if ($errors->any())
        <div class="alert" style="margin-bottom:12px;flex-direction:column;align-items:stretch;gap:4px">
            @foreach ($errors->all() as $msg)
                <div class="errline" style="margin:0">{{ $msg }}</div>
            @endforeach
        </div>
    @endif

    @if ($pending->isEmpty())
        <div class="alert good"><span>✅</span><span>{{ __('stock.all_shelved') }}</span></div>
    @else
        <div class="tablewrap">
            <table>
                <tr>
                    <th>{{ __('stock.item') }}</th>
                    <th>{{ __('stock.batch_no') }}</th>
                    <th>{{ __('stock.expires_on') }}</th>
                    <th>{{ __('stock.expiry') }}</th>
                    <th>{{ __('stock.unshelved') }}</th>
                    <th>{{ __('stock.goods_receipt') }}</th>
                </tr>
                @foreach ($pending as $b)
                    <tr>
                        <td>
                            <b>{{ $b->product?->displayName() ?? __('stock.product_hash', ['id' => $b->product_id]) }}</b>
                            @if ($b->product)
                                <br><span style="font-size:10.5px;color:var(--muted)">{{ $b->product->code }} • {{ $b->product->unitLabel() }}</span>
                            @endif
                        </td>
                        <td class="num"><b>{{ $b->batch_no }}</b></td>
                        <td class="num">{{ $b->expires_on?->format('Y-m-d') ?? '—' }}</td>
                        <td><span class="badge {{ $b->expiryClass() }}">{{ $b->expiryLabel() }}</span></td>
                        <td class="num mid"><b>{{ $fmt($b->unshelvedQty()) }}</b></td>
                        <td>
                            @if ($b->receipt)
                                <a class="btn sm gold" href="{{ route('wh.receipt', $b->receipt) }}">
                                    {{ __('stock.put_away') }} — {{ $b->receipt->number }}
                                </a>
                            @elseif (\App\Support\Access::action(auth()->user(), 'act.wh.putaway'))
                                {{-- باتش من غير إذن (استيراد قديم) — ترصيف مباشر من هنا --}}
                                <form method="POST" action="{{ route('wh.putaway', $b) }}"
                                      style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                                    @csrf
                                    <input type="text" name="location_code" list="locCodes" required
                                           placeholder="{{ __('stock.shelf_code') }}" style="width:90px">
                                    <input type="number" name="qty" min="1" max="{{ $b->unshelvedQty() }}"
                                           value="{{ $b->unshelvedQty() }}" required style="width:84px">
                                    <button class="btn sm gold" type="submit">{{ __('stock.put_away') }}</button>
                                </form>
                            @else
                                <span class="badge b-gray">—</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </table>
        </div>
        <datalist id="locCodes">
            @foreach ($locationCodes as $code)
                <option value="{{ $code }}">
            @endforeach
        </datalist>
    @endif
</div>

<div class="card">
    <h3>🔁 {{ __('stock.incoming_transfers') }}
        <span class="side"><a href="{{ route('wh.transfers') }}">{{ __('stock.view_all') }}</a></span></h3>
    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('stock.transfer') }}</th>
                <th>{{ __('stock.from_warehouse') }}</th>
                <th>{{ __('stock.sent_on') }}</th>
                <th>{{ __('stock.qty_sent') }}</th>
                <th>{{ __('common.status') }}</th>
                <th></th>
            </tr>
            @forelse ($incoming as $t)
                <tr>
                    <td class="num"><b>{{ $t->number }}</b></td>
                    <td>{{ $t->fromWarehouse?->displayName() ?? '—' }}</td>
                    <td class="num">{{ $t->sent_on?->format('Y-m-d') ?? '—' }}</td>
                    <td class="num">{{ $fmt($t->qtySent()) }}</td>
                    <td><span class="badge {{ $t->statusClass() }}">{{ $t->statusLabel() }}</span></td>
                    <td><a class="btn sm" href="{{ route('wh.transfers') }}">{{ __('stock.receive_transfer') }}</a></td>
                </tr>
            @empty
                <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:24px">
                    {{ __('common.no_results') }}
                </td></tr>
            @endforelse
        </table>
    </div>
</div>

<div class="card">
    <h3>📥 {{ __('stock.latest_receipts') }}
        <span class="side"><a href="{{ route('wh.receipts', ['warehouse' => $warehouse->id]) }}">{{ __('stock.view_all') }}</a></span></h3>
    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('stock.receipt_number') }}</th>
                <th>{{ __('stock.received_on') }}</th>
                <th>{{ __('stock.supplier') }}</th>
                <th>{{ __('stock.batches') }}</th>
                <th>{{ __('stock.total_units') }}</th>
                <th>{{ __('common.status') }}</th>
            </tr>
            @forelse ($receipts as $r)
                <tr class="clickable" onclick="location.href='{{ route('wh.receipt', $r) }}'">
                    <td class="num"><b>{{ $r->number }}</b></td>
                    <td class="num">{{ $r->received_on?->format('Y-m-d') ?? '—' }}</td>
                    <td>{{ $r->supplier ?: '—' }}</td>
                    <td class="num">{{ $r->batches->count() }}</td>
                    <td class="num">{{ $fmt($r->totalQty()) }}</td>
                    <td>
                        @if ($r->isFullyShelved())
                            <span class="badge b-green">{{ __('stock.fully_shelved') }}</span>
                        @else
                            <span class="badge b-orange">{{ __('stock.partly_shelved') }} — {{ $fmt($r->unshelvedQty()) }}</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:24px">
                    {{ __('stock.no_receipts') }}
                </td></tr>
            @endforelse
        </table>
    </div>
</div>

@endsection
